# Cierre — Ola 2, Punto 5: Notificaciones fuera de la app (correo electrónico)

*Equipo de Kuestion · Septiembre 2026*
*Plan ejecutado: `docs/ola2-punto5-plan-implementacion-kuestion.md`*
*Contrato: `docs/CONTRATO_API_OLA2.md` (v1.4 — incluye `revisado_por_*` y `GET /workspaces/{id}/miembros`)*

---

## 1. Qué se implementó (por fase del plan)

| Fase | Entregable | Archivos |
|---|---|---|
| **A.1** | Migración booleano → enum `all`/`critical_only`/`none` (mapeo `true→all`, `false→none`, `0` explícito), constantes + `emailPreferenceAllows()` en `User`, factory con default | `2026_09_10_000001_...`, `app/Models/User.php`, `UserFactory` |
| **A.2** | Tabla `email_logs` con índice único de dedupe por bucket + servicio `EmailDispatcher` (`shouldSend`/`logSent`), ventana configurable (`kuestion.email.ventana_dedupe_min`, default 30) | `2026_09_10_000002_...`, `app/Models/EmailLog.php`, `app/Services/EmailDispatcher.php`, `config/kuestion.php` |
| **A.3** | Settings: toggle booleano → 3 radios con copy del plan; persistencia validada | `app/Livewire/Settings.php`, `resources/views/livewire/settings.blade.php` |
| **A.4** | Baja por URL firmada sin login (`/unsubscribe/{user}`, `signed`) + página de confirmación + redirect firmado "Configurar mis notificaciones" | `app/Http/Controllers/UnsubscribeController.php`, `emails/unsubscribed.blade.php`, `routes/web.php` |
| **B.1** | Gate del canal mail: solo `new_version` + preferencia + dedupe, decidido en `AnswerChangedNotification::via()` | `app/Notifications/AnswerChangedNotification.php` |
| **B.2** | Preview de la nueva respuesta (primer párrafo, ≤300 chars) generado en `QuestionChecker` | `app/Services/QuestionChecker.php` |
| **B.3** | Template `answer-changed` con asunto por evento, preview, CTA "Ver cambios" y pie con ambos links firmados | `app/Mail/AnswerChangedMail.php`, `resources/views/emails/answer-changed.blade.php` |
| **B.4** | Dedupe aplicado al flujo real (mismo `via()` — un solo mail por pregunta/ventana) | idem B.1 |
| **C.1** | `CheckContributionStatusJob` (hourly): drafts `sent` con sesión → `getSession()` → transición a `promocionada`/`rechazada` (no `aprobada`) → mail → draft a `reviewed` | `app/Jobs/CheckContributionStatusJob.php` |
| **C.2** | `ContributionDecisionMail` + template con copy aprobado/rechazado, texto del aporte, CTA a bandeja, pie | `app/Mail/ContributionDecisionMail.php`, `emails/contribution-decision.blade.php` |
| **C.3** | Alcance declarado: solo aportes desde Kuestion (drafts con `status=sent`); pre-B2 sin autor = caso normal | idem C.1 |
| **C.4** | `approve()`/`reject()` envían `revisado_por_email`/`revisado_por_nombre` (contrato v1.3); `getSession()` expone `revisado_por_*` para el copy "revisó [nombre]" | `app/Services/QbkContributionService.php` |
| **D.1/D.2** | `NotifyPendingReviewJob` (cada 15 min): listado de pendientes (`§4.1`) → revisores vía `GET /workspaces/{id}/miembros` (`§8.2`, **ya entregado** por QuBeKa, commits `491d5ae`/`efc325f`) → `PendingReviewMail` con CTA "Revisar ahora" a la bandeja. Dedupe por (revisor, sesión). Miembros de QuBeKa sin cuenta en Kuestion: sin correo (sin control de baja) | `app/Jobs/NotifyPendingReviewJob.php`, `app/Mail/PendingReviewMail.php`, `emails/pending-review.blade.php`, `listarMiembros()` |
| **E.1** | `NotifyReconfirmationDueJob` (daily 9:00, spec §6-7): reutiliza `vigenciaQbk()` del Punto 2 → `ReconfirmationDueMail` "¿Sigue siendo válido?" con CTA al detalle. D2: un solo correo por ventana | `app/Jobs/NotifyReconfirmationDueJob.php`, `app/Mail/ReconfirmationDueMail.php`, `emails/reconfirmation-due.blade.php` |
| **E.2/E.3** | Plantilla `validity-alert` con **contrato de datos documentado** en la propia vista (disparador, campos, destinatario, dedupe) — **no conectada**: B5 (Kuaforia no expone señales por respuesta) | `emails/validity-alert.blade.php` |
| **F.1** | Transporte: se mantiene `log` en dev (B1 — proveedor transaccional sigue pendiente de decisión de producto/infra); los 4 mailables son `ShouldQueue` con retry/backoff (60/300/900) y fallo registrado | `.env`, jobs |
| **Scheduler** | 3 jobs registrados: `CheckContributionStatusJob` hourly, `NotifyPendingReviewJob` cada 15 min, `NotifyReconfirmationDueJob` 9:00 | `routes/console.php` |

---

## 2. Pruebas ejecutadas

### Tests automatizados (nuevos: 21, total suite: **518 passed / 1467 assertions / 0 failed**)

| Checklist | Casos | Resultado |
|---|---|---|
| **FA** | Regla por nivel (all/critical_only/none, crítico vs no crítico), dedupe dentro/fuera de ventana, carrera (1 fila), log por envío, Settings persiste los 3 niveles y muestra el copy, baja firmada OK / sin firma 403 | ✅ |
| **FB** | `new_version` mail con asunto+preview+CTA+pie; `minor` nunca maila; `critical_only`/`none` no reciben; segunda detección en ventana sin mail | ✅ |
| **FC** | `promocionada` → mail aprobado 1 vez con `revisadoPorNombre`; `rechazada` → mail rechazado; estado sin cambio → nada; QuBeKa 500 → sin estado falso; draft pre-B2 → no notifica | ✅ |
| **FD** | Pendiente → correo al revisor con cuenta (1 vez, dedupe entre corridas); miembro sin cuenta → sin correo | ✅ |
| **FE** | Pregunta vencida (95 días) → mail de reconfirmación 1 vez; confirmada (10 días) → nada; plantilla de vigencia renderiza | ✅ |

Regresión: suites tocadas actualizadas al nuevo contrato (`SettingsTest` enum, `AnswerWasEmptyPrevTest` copy B.1, `RepositoryMigrationTest` rollback 6→8 pasos por las migraciones nuevas).

### Verificación real (E2E contra servicios levantados, no mocks)

| # | Flujo | Evidencia |
|---|---|---|
| 1 | **Listado + `/miembros` contra QuBeKa real** (FD/FC.5): token válido → 2 sesiones pendientes con `autor_nombre`, texto; workspace con 1 miembro (rol propietario) | curl a `:8000/api/v1/...` |
| 2 | **Ciclo completo de decisión (C)**: draft real rearmado a `sent` → job contra QuBeKa real → sesión 13 `promocionada` → mail encolado y **procesado por la cola** → draft a `reviewed`, 1 fila en `email_logs` | `queue:work` DONE + log de transporte |
| 3 | **Correo recibido (transporte log)**: `To: ccorrea@proteam.cl`, `Subject: Kuestion: tu aporte fue aprobado`, CTA "Abrir la bandeja de revisión" y link firmado de baja en el HTML | `storage/logs/laravel.log` |
| 4 | **Baja por link firmado (FA.6)**: el link exacto del correo entregado → HTTP 200 "Correos desactivados" → preferencia del usuario en `none` (restaurada a `all` después) | curl + query de BD |
| 5 | **Templates renderizados en HTML real** (ítem obligatorio §3): los 3 mailables nuevos + `answer-changed` renderizan con pie completo y atribución | tinker render |

### Assets y compatibilidad (ítems obligatorios)

- `npm run build` OK; clases de la UI de preferencias verificadas en el **CSS servido** (`text-text-muted`, `border-border`, `rounded-xl`, `hover:bg-page/50`, `focus:ring-primary`, `space-y-3`). Hallazgo y fix: `hover:bg-background/50` no existía en el design system → reemplazada por `hover:bg-page/50` (patrón real del proyecto, 3 usos previos) y re-verificada en el bundle.
- APIs verificadas contra el vendor instalado (no docs): `MailFake::sendMail` enruta ShouldQueue → **`assertQueued`**; `URL::temporarySignedRoute` (Laravel 11); `MailFake::assertNothingQueued` existe; semántica de propiedades untyped+default en `unserialize`.

---

## 3. Hallazgos

1. **`/miembros` ya estaba entregado** (contrato v1.4) — la Fase D dejó de ser "dependencia de entrega" y se ejecutó completa contra el endpoint real, con mock solo en los tests.
2. **El Punto 2 ya proveía los datos de E.1** (`vigenciaQbk()`): el correo de reconfirmación se implementó completo, no solo el diseño. Solo E.2 (vigencia Kuaforia) queda diferido por B5.
3. **Gap real detectado por el E2E: notificaciones en cola pre-deploy explotaban al hidratar** — la propiedad nueva `$preview` (typed + readonly) quedaba *uninitialized* en payloads serializados antes del deploy. Fix: propiedad untyped con default null (compatible con `unserialize` de payloads legacy). Encontrado solo porque se probó la cola real con jobs en backlog.
4. **Gap de mapping en `getSession()`**: no exponía `revisado_por_*` (contrato v1.3) — el copy "revisó [nombre]" nunca habría llegado. Corregido y testeado.
5. **Naming del listado real**: el endpoint devuelve `fecha_creacion`/`texto_original_del_aporte` (no `creado_en`/`contenido_entrada` como sugería el contrato) — el normalizador ya acepta ambas variantes, sin impacto. Señalado para la próxima revisión del contrato.
6. **El token de QuBeKa guardado en Kuestion sigue siendo el problema conocido del P4** (401 con token revocado). Para el E2E se mintió uno de desarrollo (`qk:1:e2e-p5-kuestion`, expira 2026-10-10) y se actualizó la credencial del repo activo. En producción esto se resuelve reconectando desde `/settings`.
7. **Los correos reales requieren resolver B1** (proveedor transaccional). Hoy el transporte es `log` (dev) — el E2E validó el HTML entregado y el link de baja contra ese transporte; el envío a buzones reales queda bloqueado por la decisión de infraestructura, no por código.

---

## 4. Pendientes declarados (no asumidos)

| Pendiente | Motivo |
|---|---|
| Envío a buzón real (E2E con proveedor/Mailpit) | **B1** — decisión de proveedor transaccional pendiente (producto/infra) |
| Verificación visual DevTools de la pantalla de preferencias | Requiere navegador humano; DOM y CSS compilado ya verificados por tests + bundle |
| E.2 — correo de vigencia crítica conectado | **B5** — Kuaforia no expone señales por respuesta; plantilla + contrato listos |
| Reconectar token QBK en producción | Continuidad del hallazgo del P4 (token revocado tras reinicio de BD de QuBeKa) |

---

## 5. Estado final

- **Plan completo ejecutado**: Fases A–F, incluida la Fase D (antes condicionada, hoy posible por la entrega de QuBeKa) y la E.1 (antes condicionada al Punto 2, hoy implementado).
- **Suite completa verde**: 518 passed / 1467 assertions / 0 failed. Pint limpio. Assets recompilados y verificados.
- **Ciclo real cerrado de punta a punta**: decisión de sesión en QuBeKa → correo al autor con atribución de revisor → baja por link firmado — todo contra los servicios reales.
