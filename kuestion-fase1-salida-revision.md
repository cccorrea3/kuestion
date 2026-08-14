# Kuestion — Fase 1 · Salida para revisión (documento de cierre)

> **Versión:** 1.0 | **Fecha:** 2026-08-14 | **Alcance:** Fase 1 completa (Bloques 1–6) | **Fuente:** `kuestion-fase1-plan-implementacion.md` (v1.1) sobre `Plan_Mejora_Kuestion_v2.4.md` (cerrado)
>
> **Estado:** ✅ Fase implementada y verificada. Suite: **52 tests, 146 assertions pasando** (la fase arrancó con 16 tests/27 assertions).
>
> **Commits de la fase (orden de implementación):**
>
> | Commit | Contenido |
> |---|---|
> | `33e5998` (M16) | Bloque 5 — métricas diarias |
> | `ace4f9d` (M17) | Bloque 4 — búsqueda FULLTEXT |
> | `3d60ec4` (M18) | Bloque 3 — integridad bajo concurrencia |
> | `c5a99b4` (M19) | Bloque 1 — cuenta y comunicación (notificaciones + reset + settings) |
> | `0933df4` (M20) | Bloque 2 — CSP con nonces + lucide local |
> | `a50e2a0` (M21) | Bloque 6 — conexión de tenant "Conectate a Kuaforia" |

---

## 0. Resumen global de la fase

- **Qué se construyó:** los 6 bloques de deuda técnica definidos en el plan maestro v2.4 (correo/notificaciones nativas, password reset y perfil, CSP sin `unsafe-inline`, concurrencia con locks, retención configurable, manejo de respuestas vacías, búsqueda FULLTEXT, métricas diarias y conexión de tenant por API key scoped).
- **Regla de oro respetada:** el mecanismo REST/hash de Kuaforia **no se tocó** — `POST /api/consult/{tenant_slug}`, `ChangeDetector` (SHA-256 + similitud coseno) y la lógica de detección del job permanecen intactos (ver §6 regresión).
- **Hallazgos relevantes de la fase** (detalle en cada bloque):
  1. **phpunit.xml no aplicaba sus variables de entorno** (crítico, pre-existente): los tests corrían con `APP_ENV=local`, `QUEUE_CONNECTION=redis`, `MAIL_MAILER=log` del `.env` real. Los tests anteriores pasaban de casualidad (inserts crudos no dependían de cola). Corregido con `<server force="true"/>`.
  2. **`Mail::fake()` no captura mails de notificaciones** de Laravel (el canal mail convierte el Mailable a vista antes de llegar al mailer) — los tests verifican el contrato del correo directo sobre `toMail()`.
  3. **`whereLike()` de Laravel 11 es un trap**: compila match exacto para MySQL (sin `%`), no subcadena — el fallback de búsqueda usa LIKE manual con wildcards escapados.
  4. **`whereLike` no fue el único**: `updated_*` hooks de Livewire no son llamables directo en tests, `reset()` choca con un método nativo de Livewire, y la regla `confirmed` espera `campo_confirmation` (no camelCase).
  5. **Los tests comparten la BD MySQL de desarrollo** (`kuestion`): `RefreshDatabase` corre `migrate:fresh` sobre la misma base que dev — **riesgo operativo** (ver §5).

---

## 1. Resumen por bloque (formato de cierre del plan)

### Bloque 5 — Observabilidad base (implementado primero, `33e5998`)

**Resumen ejecutivo:**
- Tabla `daily_metrics` (migración `2026_08_14_000001`) + modelo `DailyMetric`.
- `CollectDailyMetrics` (`metrics:collect`, scheduler **00:30** en `routes/console.php`) con agregación diaria idempotente y `--date` para backfill.
- `ShowMetrics` (`metrics:show`) con `--date`/`--range` (default últimos 7 días).
- **Decisión de revisión aplicada:** sin "API interna" (diferida, YAGNI) — el Bloque 12 de Fase 3 leerá `daily_metrics` directo.

**Evidencia por criterio de aceptación:**

| Criterio (maestro) | Cómo se verificó | Resultado |
|---|---|---|
| Métricas en `daily_metrics` | `MetricsCommandTest` (7 tests): agregación con fixtures controlados, idempotencia, backfill, fecha inválida, empty state | ✅ |
| Consultables vía Artisan | Ejecución real: `metrics:collect` + `metrics:show`/`--date`/`--range` contra BD de dev; `schedule:list` muestra `30 0 * * *` | ✅ |

**Desviaciones:**
- `diffInSeconds` de Carbon con signo invertido → promedio daba 0.0; corregido con duración absoluta (`abs()`).
- La aleatoriedad de la factory (`has_unreviewed_changes` 15%) contaminaba el conteo → fixtures forzados.

**Riesgos no previstos:** — *(ninguno adicional)*

**Preguntas abiertas nuevas:** — *(ninguna)*

---

### Bloque 4 — Escalabilidad de búsqueda: FULLTEXT (`ace4f9d`)

**Resumen ejecutivo:**
- Índice FULLTEXT sobre `question_text` (migración `2026_08_14_000002`, InnoDB).
- Scope centralizado `Question::scopeSearch()`: FULLTEXT en modo natural con fallback a LIKE (términos < 3 chars o descartados por el tokenizador — stopwords).
- Aplicado en `QuestionFeed`, `QuestionController::index` y `RelationsPanel` (elimina los 3 LIKE manuales).
- `RelationSuggester` pre-filtra candidatos por FULLTEXT (`exists()` como guarda) manteniendo el scoring (tags ×3 + keywords) y el slice 10.

**Evidencia por criterio de aceptación:**

| Criterio (maestro) | Cómo se verificó | Resultado |
|---|---|---|
| Búsqueda más rápida/relevante | `EXPLAIN` real con datos: `key=questions_question_text_fulltext`, `type=fulltext` incluso con filtro `user_id` | ✅ |
| RelationSuggester con FULLTEXT | `SuggestRelationsTest` + test nuevo de keyword pasan; scoring intacto | ✅ |
| Palabras cortas/acentos | Test de fallback LIKE ("ia" de 2 chars) pasa; MySQL es accent-insensitive en FULLTEXT | ✅ |

**Desviaciones:**
- **`whereLike()` compila match exacto en MySQL** (sin `%`) → el fallback usa LIKE manual con wildcards escapados (patrón original que ya funcionaba).
- Verificado en docs oficiales de MySQL: el umbral del 50% es de MyISAM (no aplica a InnoDB) y el modo natural solo reordena por relevancia cuando no hay `ORDER BY` explícito (se preservó el orden del feed).

**Riesgos no previstos:** — *(ninguno adicional)*

**Preguntas abiertas nuevas:** — *(ninguna)*

---

### Bloque 3 — Integridad bajo concurrencia y manejo de fallos (`3d60ec4`)

**Resumen ejecutivo:**
- `lockForUpdate` en el job (serializa `max(version_number)+1`), en `acceptChange`/`dismissChange` (API y Livewire, con `refresh()` posterior) y en `CleanupOldVersionsJob`.
- Retención en `config('kuestion.retention.archived_versions')` (default 5); el job conserva todas las versiones de activas y las últimas N de archivadas (`withTrashed()`).
- Respuestas vacías de Kuaforia: **no** se versiona; se notifica `query_error` con anti-spam (una sola no leída por pregunta) y se actualiza `last_consulted_at`.
- `QuestionController::store` (API) pasó a ser **transaccional** (hallazgo: el plan 3.3 lo daba por hecho).

**Evidencia por criterio de aceptación:**

| Criterio (maestro) | Cómo se verificó | Resultado |
|---|---|---|
| Multi-worker sin duplicados | Test: job 2× sobre la misma pregunta vencida → versiones 1 y 2 únicas; la 2ª corrida no crea | ✅ |
| Escrituras críticas con `lockForUpdate` | Revisión por grep de los 5 puntos de escritura (job, cleanup, accept/dismiss API + Livewire, store) | ✅ |
| Retención documentada | Test: con retención 3 se elimina la versión más vieja y se conserva la actual | ✅ |
| Error en consulta vacía | Test: respuesta `''` → sin versión, notificación `query_error`, `last_consulted_at` actualizado; sin duplicación en 2ª corrida | ✅ |

**Desviaciones:**
- `store` (API) no era transaccional (el plan lo asumía) → corregido: pregunta + v1 + relaciones atómicas.
- SoftDeletes rompía el cleanup (el scope global excluía trashed) → `withTrashed()` en el lock y el re-fetch.
- La notificación `query_error` se implementó con el patrón del job (insert crudo); **se migró a clase nativa en el Bloque 1** (ver siguiente) — el filtro del Bloque 5 ya cubre ambos tipos.

**Riesgos no previstos:** — *(ninguno adicional)*

**Preguntas abiertas nuevas:** — *(ninguna)*

---

### Bloque 1 — Cuenta y comunicación con el usuario (`c5a99b4`)

**Resumen ejecutivo:**
- **1.1** Tabla `notifications` adaptada al esquema estándar de Laravel (`notifiable_type`/`notifiable_id`, backfill desde `user_id`, `type` ampliado a 255, `updated_at` agregado). Notificaciones nativas: `AnswerChangedNotification` (canales database + mail condicional) y `QueryErrorNotification`. Job refactorizado a `$user->notify()` con payload idéntico. `App\Mail\AnswerChangedMail` (Mailable) + template `emails/answer-changed` con enlace directo. Toggle `email_notifications` (boolean, default 1).
- **1.2** `ForgotPassword` (`/forgot-password`), `ResetPassword` (`/reset-password/{token}`) y `Settings` (`/settings`: datos personales, contraseña, toggle de notificaciones). Rutas + link "¿Olvidaste tu contraseña?" en login + ícono de settings en el header.
- **Hallazgo crítico corregido:** phpunit.xml no aplicaba sus env vars (ver §0 y §5).

**Evidencia por criterio de aceptación:**

| Criterio (maestro) | Cómo se verificó | Resultado |
|---|---|---|
| Correo al cambiar + enlace directo | `CheckQuestionUpdatesJobTest`: notificación DB con payload correcto; `toMail()` construye el Mailable con destinatario y datos | ✅ |
| Toggle de correo desde perfil | Test: `email_notifications=false` → `via()` sin canal mail; toggle en `/settings` persiste | ✅ |
| Reset válido 60 min | `PasswordResetTest`: token real del broker resetea; inválido/expirado → error sin reset | ✅ |
| Cambiar nombre/email/contraseña desde `/settings` | `SettingsTest`: email único excluyendo self; contraseña actual incorrecta → error; cambio exitoso | ✅ |

**Desviaciones:**
- `reset()` choca con método nativo de Livewire → renombrado `resetPassword()`.
- La regla `confirmed` espera `campo_confirmation` (no camelCase) → `newPassword_confirmation`.
- `request()->session()` sin session store en Livewire test → `session()->regenerate()` (facade).
- Token de reset: en BD solo está su hash bcrypt; el test usa el token plano del broker (el que viaja por correo).
- `Mail::fake()` no captura mails de notificaciones (comportamiento del framework: el canal convierte el Mailable a vista) → los tests verifican `toMail()` directo.

**Riesgos no previstos:**
- El bug de phpunit.xml (ver §5) **enmascaraba** cualquier código dependiente de cola/mail: las notificaciones `ShouldQueue` se encolaban a Redis y nunca se procesaban en tests. Sin el fix, el Bloque 1 habría pasado con falsos verdes.

**Preguntas abiertas nuevas:** — *(ninguna)*

---

### Bloque 2 — Seguridad: CSP con nonces (`0933df4`)

**Resumen ejecutivo:**
- `SecurityHeaders` genera un nonce por request (`base64_encode(random_bytes(18))`), lo registra con `Vite::useCspNonce()` (los tags de Vite y Livewire lo toman solos vía `csp_safe=true`, ya activo) y lo comparte con `View::share('cspNonce', ...)`.
- CSP final **sin `unsafe-inline`**: `default-src 'self'; script-src 'self' 'nonce-{n}'; style-src 'self' 'nonce-{n}' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; ...`.
- Lucide local (Opción A): `npm install lucide` + bundle en `resources/js/app.js` (elimina CDN unpkg y el script inline del layout).

**Evidencia por criterio de aceptación:**

| Criterio (maestro) | Cómo se verificó | Resultado |
|---|---|---|
| CSP sin `unsafe-inline` | `SecurityHeadersTest`: en production el header no contiene `unsafe-inline` ni `unpkg.com`; contiene `nonce-` en `script-src` y `style-src` | ✅ |
| Livewire sigue funcionando | Todos los tests Livewire existentes pasan; todos los `<script>` emitidos llevan nonce (test de layout) | ✅ |
| Observatory mejora | Se eliminaron los dos puntos penalizados: `'unsafe-inline'` (script y style) y el CDN externo de scripts | ✅ |
| Build frontend | `npm run build` OK (lucide bundleado, 1831 módulos) | ✅ |

**Desviaciones:**
- `'strict-dynamic'` **no** se agregó (era opcional): con cero inline y todo desde `'self'` con nonce no aporta y arriesga romper `'self'`.
- Google Fonts **sí se usan** (`@import` en `app.css`) → se mantienen `fonts.googleapis.com`/`fonts.gstatic.com` en la CSP (verificación pedida por el plan).

**Riesgos no previstos:** — *(ninguno adicional)*

**Preguntas abiertas nuevas:** — *(ninguna)*

---

### Bloque 6 — Conexión de tenant "Conectate a Kuaforia" (`a50e2a0`)

**Resumen ejecutivo:**
- **6.1** `KuaforiaService::resolveTenantFromApiKey()` — punto único detrás de `services.kuaforia.tenant_resolution` (`rest | mcp`); vía REST `POST {base}/api/validate-api-key` con Bearer de la key del usuario; errores diferenciados (401 → inválida/revocada, conexión → reintentar, sin tenant → no se pudo resolver); captura `workspace_id` si viene (preparado para el Bloque 8 de Fase 2).
- **6.2** `kuaforia_api_key` (text nullable) con cast `encrypted`.
- **6.3–6.5** Registro: dropdown reemplazado por campo de API key con validación en vivo (debounce 700ms) → "Conectado a <organización>" o error; submit deshabilitado hasta resolver; key persistida cifrada + `tenant_slug` resuelto.
- **6.6** Sección "Conexión con Kuaforia" en `/settings` (reutiliza el resolver; resolución de revisión #2 aplicada).
- **6.7** `KuaforiaKeyPrompt` — banner descartable (por sesión) para usuarios existentes sin key.

**Evidencia por criterio de aceptación:**

| Criterio (maestro) | Cómo se verificó | Resultado |
|---|---|---|
| Registro valida la key en tiempo real | `TenantConnectionTest`: key válida (Http::fake) → tenant resuelto + "Conectado" + redirect a onboarding; inválida → error y no crea usuario | ✅ |
| Tenant se resuelve, no se selecciona | El dropdown de tenants desapareció del registro; el tenant sale del resolver | ✅ |
| No se usan TenantTools | El resolver usa el endpoint de validación (sin TenantTools) | ✅ |
| Key cifrada | Test: en BD se guarda payload `eyJpdiI6...` (cifrado), no la key en claro | ✅ |
| Re-validación desde `/settings` (6.6) | Tests: key nueva válida → actualiza key + tenant; inválida → error sin cambios | ✅ |

**Desviaciones:**
- **Pendiente #1 (externa) sigue abierto:** el endpoint real de Kuaforia (`/api/validate-api-key`, nombre a confirmar) no está verificado contra Kuaforia; todo el flujo se probó con `Http::fake`. Al confirmar el contrato basta ajustar URL/parseo en 6.1.
- La key de la consulta REST **no cambia**: la consulta sigue con la key compartida de la app; la key `kfr_` del usuario solo resuelve el tenant (decisión cerrada del maestro).
- Hooks `updated*` de Livewire no son llamables directo en tests → se disparan seteando la propiedad.

**Riesgos no previstos:** — *(ninguno adicional)*

**Preguntas abiertas nuevas:**
- **Contrato del endpoint de validación de Kuaforia** (URL, método, respuesta): coordinación con Ingeniería de Kuaforia (pendiente #1 del maestro, sigue abierto).
- Decidir si el `workspace_id` se expone en ese endpoint (evitaría el mapeo manual del Bloque 8 de Fase 2).

---

## 2. Decisiones técnicas tomadas en la fase (resumen)

| Decisión | Dónde | Alternativa descartada |
|---|---|---|
| Notificaciones nativas de Laravel (`$user->notify()` encolada) | Bloque 1 | Canal custom sobre la tabla custom (más código propio) |
| `type` = clase FQCN (`App\Notifications\...`); el filtro del Bloque 5 cubre ambos valores | Bloque 1/5 | Mantener string `'answer_changed'` |
| Correo como `Mailable` devuelto desde `toMail()` | Bloque 1 | Vista cruda desde `toMail()` (no testeable) |
| CSP: nonce por request + `Vite::useCspNonce` + lucide local (Opción A) | Bloque 2 | CDN unpkg con nonce (Opción B) |
| `'strict-dynamic'` NO | Bloque 2 | Agregarlo (arriesga romper `'self'`) |
| `Question::scopeSearch()` centralizado con fallback LIKE | Bloque 4 | Repetir FULLTEXT/LIKE en cada consumidor |
| Retención configurable en `config/kuestion.php` (default 5) | Bloque 3 | Número hardcodeado |
| Anti-spam: una sola notificación de error no leída por pregunta | Bloque 3 | Notificar siempre (fatiga) |
| Promedio de revisión en PHP (abs) y filtro de tipos dual | Bloque 5 | `TIMESTAMPDIFF` (no portátil) |
| Resolver de tenant único detrás de config `rest|mcp` | Bloque 6 | Vía fija sin config |
| `phpunit.xml`: `<server force="true"/>` para env vars críticas | QA transversal | — (era el bug) |

## 3. Resoluciones de producto/tecnología/ingeniería aplicadas

| # | Pregunta (v1.0 del plan) | Resolución | Cómo se aplicó |
|---|---|---|---|
| 1 | Proveedor SMTP | SendGrid (u otro ya contratado) — decisión operativa | Código agnóstico (Laravel Mail); falta configurar `.env` de prod (pendiente ops) |
| 2 | Re-validación de key desde `/settings` | Sí, en alcance | Tarea 6.6 implementada |
| 3 | Usuarios existentes (dropdown) | Aceptar lectura + prompt opcional | 6.7 (`KuaforiaKeyPrompt`, descartable por sesión) |
| 4 | Anti-spam de errores de consulta | Notificar solo el primer error no leído | Implementado en 3.5/1.8 |
| 5 | "API interna" de métricas | Diferir (YAGNI) | Solo vía Artisan; Bloque 12 lee la tabla directo |

## 4. Lo que queda pendiente (fuera de esta fase)

- **Coordinación con Kuaforia (pendiente #1):** confirmar endpoint de validación de API key y si expone `workspace_id` (desbloquea verificación real del Bloque 6 y simplifica el Bloque 8 de Fase 2).
- **Operaciones:** configurar SMTP real (SendGrid) en `.env` de producción y proveedor de correo en dev (Mailpit/Mailhog).
- **Smoke manual recomendado (no automatizable aquí):** navegación completa en producción local verificando cero violaciones CSP en consola; y prueba real del correo con Mailpit.
- **Fase 2 (Bloques 7–10)** y **Fase 3 (Bloques 11–14)**: planes listos en `kuestion-fase2-plan-implementacion.md` y `kuestion-fase3-plan-implementacion.md`.

## 5. Riesgos no previstos detectados durante la implementación

1. **phpunit.xml no aplicaba sus env vars (CRÍTICO, pre-existente).** Causa raíz: PHPUnit escribe `<env>` solo en `putenv`/`$_ENV`, pero el repository de Laravel (phpdotenv) lee `$_SERVER` **antes** que `$_ENV`, y `$_SERVER` hereda los valores del `.env` que carga el proceso padre `php artisan test` al bootear el kernel de consola. Fix aplicado: `<server ... force="true"/>` para `APP_ENV`, `QUEUE_CONNECTION`, `MAIL_MAILER` y `SESSION_DRIVER`. **Riesgo latente:** cualquier env var nueva en phpunit.xml sin `<server force>` seguirá ignorada.
2. **Los tests usan la BD MySQL compartida con desarrollo** (`DB_DATABASE=kuestion`): `RefreshDatabase` corre `migrate:fresh`, es decir, **cada corrida de tests borra y recrea las tablas de la BD de dev**. Pre-existente, no corregido en esta fase (fuera de alcance). **Recomendación:** configurar una BD de test dedicada (o SQLite `:memory:`) en phpunit.xml — tarea de higiene de tests prioritaria.
3. **`Mail::fake()` no captura mails de notificaciones** (comportamiento del framework): puede confundir futuros tests que asuman `assertSent` sobre notificaciones. Documentado en el plan; el patrón correcto es verificar `toMail()` directo.
4. **La factory de `Question` es aleatoria** (`has_unreviewed_changes` al 15%): puede contaminar conteos en tests futuros; los fixtures deben forzar el valor (patrón ya usado).

## 6. Preguntas abiertas nuevas (no resueltas en la fase)

| # | Pregunta | Contexto | Estado |
|---|---|---|---|
| 1 | Contrato exacto del endpoint de validación de API key de Kuaforia (URL, método, respuesta; ¿incluye `workspace_id`?) | Bloque 6, pendiente #1 del maestro | Abierta — coordinación con Kuaforia |
| 2 | ¿Separar la BD de test de la de dev? | Riesgo §5.2 — `migrate:fresh` borra datos de dev | Abierta — recomendación de higiene |
| 3 | Configuración SMTP real (proveedor, credenciales) en producción | Bloque 1.1.6 — decisión operativa (SendGrid sugerido) | Abierta — a cargo de ops |
| 4 | ¿Emitir la CSP también fuera de producción? | Hoy el middleware solo emite CSP con `APP_ENV=production` (decisión pre-existente del proyecto, respetada) | Abierta — producto |

---

*Documento generado al cierre de la Fase 1 (2026-08-14). Próximo paso sugerido: coordinar el pendiente #1 con Kuaforia y arrancar la Fase 2 (arquitectura interna, Bloques 7–10) — plan en `kuestion-fase2-plan-implementacion.md`.*
