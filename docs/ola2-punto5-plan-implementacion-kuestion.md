# Plan de Implementación — Ola 2, Punto 5: Notificaciones fuera de la app (correo electrónico)

*Equipo de Kuestion · Septiembre 2026*
*Documento de entrada: `OLA_2_Punto_5.md` (especificación cerrada de producto)*
*Contrato de referencia: `docs/CONTRATO_API_OLA2.md` (fuente única de contrato Ola 2)

---

## 1. RESUMEN DE ALCANCE

### Qué voy a construir

Que Kuestion **envíe correos electrónicos** a los usuarios cuando ocurren eventos relevantes del ecosistema, convirtiendo la vigilancia de "el usuario debe acordarse de abrir la app" a "Kuestion le avisa donde está". En esta versión: correo por evento individual (sin digest diario), con preferencias por usuario (`all` / `critical_only` / `none`), deduplicación por ventana de tiempo, registro (log) de cada envío para trazabilidad, y plantillas accionables con CTA y pie de baja de suscripción.

Los eventos definidos en la especificación (§2.1) y su estado real hoy en el código:

| Evento | Destinatario | Estado actual en el código de Kuestion |
|---|---|---|
| Cambio importante (`new_version`) en pregunta vigilada | Autor de la pregunta | **Parcial**: `AnswerChangedNotification` + `AnswerChangedMail` ya envían correo, pero con gaps frente al spec (ver hallazgos F1) |
| Aporte pendiente de revisión (aporte de otro) | Revisores del workspace | **No existe**: depende del listado de sesiones y roles de QuBeKa (Punto 1) |
| Aporte propio aprobado / rechazado | Autor del aporte | **No existe**: requiere detectar el cambio de estado de la sesión en QuBeKa (polling) |
| Reconfirmación pendiente (nodo sobre umbral) | Autor / último revisor | **No existe**: depende de los datos del Punto 2 (`fecha_ultima_confirmacion`) |
| Alerta de vigencia crítica (Kuaforia `stale_case`/`low_confidence`) | Autor de la pregunta | **No existe**: depende de las señales por respuesta del Punto 3 |

### Hallazgos de la revisión del código que condicionan el alcance

- **F1 — El correo de cambio ya existe pero no cumple el spec en tres puntos.** `QuestionChecker` notifica (con canal mail si el usuario tiene `email_notifications` activo) **tanto para `minor` como para `new_version`** — el spec §2.1 dice explícitamente que en esta versión solo `new_version` genera correo. Además, `AnswerChangedMail` no incluye el **primer párrafo de la nueva respuesta** (spec §2.3) ni un **pie con enlaces de configuración y baja** (§2.3), y el asunto no sigue el copy por evento.
- **F2 — `users.email_notifications` es un booleano hoy; el spec §5 lo define como enum `all`/`critical_only`/`none`.** Hay que migrar la columna (o su semántica) y actualizar todos los puntos que la leen: `User` (fillable/casts), `Settings` Livewire + vista (hoy un toggle), `AnswerChangedNotification::via()`, y los tests existentes (`SettingsTest`, `CheckQuestionUpdatesJobTest`, `AnswerWasEmptyPrevTest`) que asumen booleano.
- **F3 — No existe infraestructura de deduplicación ni de log de envíos.** El spec §5 exige ambas (dedupe por pregunta y ventana de ~30 min; log con fecha/destinatario/evento). Hoy no hay tabla ni servicio para eso.
- **F4 — La resolución de autores y revisores de aportes es una brecha real.** Kuestion solo conoce autores de aportes hechos *desde Kuestion* (tabla `contribution_drafts` con `user_id` + `qbk_session_id`). No conoce aportes hechos directamente en QuBeKa por miembros del equipo (no tienen draft local), no tiene un modelo de roles de workspace (el `team_dashboard_access` es un flag de solo lectura para `/team`, no roles), y no sabe quién es "el revisor" de un aporte ajeno. **No voy a inventar esta resolución**: queda como bloqueante hacia QuBeKa/Punto 1 (B2/B3).
- **F5 — El transporte de correo está en `log`** (`.env.example`: `MAIL_MAILER=log`). Enviar correos reales exige decidir e integrar un proveedor transaccional (SendGrid, Mailgun, etc.) — decisión de infraestructura declarada en el spec §3 y §6-6, que tomo como bloqueante de infraestructura (B1), no de código.
- **F6 — El badge in-app (`NotificationBadge`) navega por `data->question_id`; el feed clasifica `new_version`/`minor`.** Los correos nuevos no deben romper esos consumidores: las notificaciones nuevas de este punto usan el canal mail directamente (o notificaciones con payload propio), sin alterar el payload del badge.

### Lo que NO construyo en esta versión

- Digest diario o semanal (spec §2.2: "No hay un digest diario en esta versión").
- Configuración de preferencias por tipo de evento (spec §2.2: una sola preferencia global por usuario).
- Correos por cambios `minor` (spec §2.1).
- Migración al modelo de eventos push de QuBeKa (spec §3 la declara pendiente; este punto usa polling como decisión pragmática).
- Canales fuera del correo (Slack, calendario, etc. — solo mencionados como contexto en §0).
- La bandeja de revisión (Punto 1) ni los datos de reconfirmación/vigencia (Puntos 2 y 3): este punto solo **consume** sus resultados cuando existan.

---

## 2. FASES Y TAREAS

### Fase A — Infraestructura de correo: preferencias (enum), deduplicación y log

**Objetivo:** dejar la base transversal: preferencia de correo en tres niveles, regla central de "¿este evento le llega a este usuario?", dedupe por ventana y trazabilidad de envíos. Sin esto ninguna plantilla es segura de enviar.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| A.1 | Migración de `users.email_notifications` (boolean) a enum `all` / `critical_only` / `none` (string con default `all`; mapeo `true→all`, `false→none`). Actualizar `User` (fillable, casts, constantes, helper p. ej. `emailPreferenceAllows(string $eventLevel): bool`) y `UserFactory`. | — | Migración + modelo con semántica de 3 niveles, sin romper datos existentes. |
| A.2 | Tabla `email_logs` (user_id, event_type, reference_key — p. ej. question_id o session_id —, sent_at, unique de dedupe por ventana). Servicio `EmailDispatcher` (o similar) con: `shouldSend(user, eventType, referenceKey, windowMinutes)` y `logSent(...)` — ventana configurable en `config/kuestion.php` (default 30 min, spec §5). | A.1 | Servicio de dedupe/log + tests unitarios (mismo evento en ventana → 1 envío; fuera de ventana → 2; eventos distintos → 2). |
| A.3 | UI de preferencias en Settings (Livewire): reemplazar el toggle booleano por 3 opciones con copy claro de qué incluye cada nivel (`all` = todo; `critical_only` = solo revisión pendiente, vigencia crítica y reconfirmación; `none` = solo in-app). Actualizar tests de `SettingsTest`. | A.1 | Pantalla de preferencias usable y testeada. |
| A.4 | Ruta de baja y de configuración para los pies de correo: `/settings` (configurar) y una ruta de baja con URL firmada (no requiere login) que ponga la preferencia en `none`. | A.1, A.3 | Links de pie funcionales. |

**Entregable verificable:** un usuario cambia su preferencia entre los 3 niveles en `/settings` y queda persistida; el servicio de correo decide correctamente si un evento (crítico vs. no crítico) aplica según el nivel; dos eventos del mismo tipo sobre la misma entidad dentro de 30 min generan un solo envío; el log registra cada envío.

**Validación:** tests unitarios del servicio de dedupe + tests del componente de Settings + checklist FA de la sección 3.

---

### Fase B — Correo de cambio en respuesta vigilada (alinear el existente al spec)

**Objetivo:** que el correo que ya existe para "cambió una respuesta vigilada" cumpla exactamente lo que pide el spec: solo `new_version`, con preview de la respuesta, CTA "Ver cambios" y pie de baja.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| B.1 | En `AnswerChangedNotification::via()` (o en `QuestionChecker` al notificar): **enviar canal mail solo cuando `changeType === 'new_version'`** (los `minor` quedan solo in-app). Combinar con la preferencia del usuario (Fase A): `all` recibe todo; `critical_only` y `none` no reciben este evento (no es crítico según §2.2). | Fase A | Regla de envío correcta por tipo de cambio + preferencia. |
| B.2 | Pasar a `AnswerChangedNotification`/`AnswerChangedMail` el **primer párrafo de la nueva respuesta** (preview, ~2–3 frases) para el cuerpo del correo (spec §2.3: contexto adicional). `QuestionChecker` tiene el texto en el momento de notificar. | B.1 | Mailable con preview real de la respuesta. |
| B.3 | Actualizar `emails/answer-changed.blade.php` y el asunto según el copy del spec: asunto claro con el evento y la pregunta afectada, CTA destacado "Ver cambios" (enlace a la pregunta), y pie con "Configurar mis notificaciones" + "Dejar de recibir estos correos" (rutas de A.4). | A.4, B.2 | Plantilla alineada al spec. |
| B.4 | Integrar el envío con el servicio de dedupe/log de A.2 (una notificación de cambio duplicada en la ventana no genera dos correos). | A.2, B.1 | Dedupe aplicado al flujo real. |

**Entregable verificable:** un cambio `new_version` en una pregunta vigilada genera un correo con preview + CTA + pie; un cambio `minor` no genera correo (solo badge); con preferencia `none` o `critical_only` no llega; dos cambios en la misma pregunta en la ventana generan un correo.

**Validación:** tests del flujo (con `Mail::fake`/render real del mailable) + checklist FB de la sección 3.

---

### Fase C — Correos del ciclo de aportes: aprobado / rechazado al autor (polling)

**Objetivo:** avisar al autor cuando su aporte fue aprobado o rechazado — por el flujo del spec §6-3: Kuestion **consulta periódicamente** el estado de las sesiones de análisis en QuBeKa (decisión pragmática declarada; el modelo de eventos queda pendiente).

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| C.1 | Job programado (`CheckContributionStatusJob`, horario) que recorre los aportes con sesión QBK conocida (`contribution_drafts` con `qbk_session_id` + estado `sent`/`reviewed`), consulta `getSession()` de QuBeKa, y detecta transiciones a `aprobada`/`promocionada` (éxito) o `rechazada`. | A.2 (dedupe/log para no re-enviar en cada corrida) | Job que detecta el cambio de estado sin duplicar correos. |
| C.2 | Notificaciones/mailables nuevos (canal mail, respetando preferencia `all`/`critical_only`): "Tu aporte fue aprobado" y "Tu aporte fue rechazado", con el texto del aporte, CTA a la pregunta/detalle correspondiente y pie de baja. | A.4, C.1 | Plantillas de aprobado/rechazado. |
| C.3 | **Alcance declarado**: esta vía cubre aportes hechos desde Kuestion (única fuente con autor local). Aportes hechos directamente en QuBeKa por otro miembro no tienen `contribution_drafts` — su autor solo se podría resolver con datos de QuBeKa (ver bloqueante B2/B4). No se simula esa resolución. | C.1 | Límite de alcance documentado. |

**Entregable verificable:** un aporte hecho desde Kuestion que un revisor aprueba (o rechaza) en QuBeKa genera, en la próxima corrida del job, un correo al autor con el estado correcto — una sola vez.

**Validación:** tests del job con Http fake del contrato de QuBeKa + checklist FC. **La validación contra QuBeKa real queda pendiente** de que QuBeKa exponga el estado de sesión de forma consultable para esta transición (ver B2/B4 y la matriz mock vs real).

---

### Fase D — Correo de "aporte pendiente de revisión" a revisores (condicional al Punto 1)

**Objetivo:** avisar a los revisores cuando alguien aporta contenido que requiere su revisión (spec §2.1, flujo 4.2).

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| D.1 | Detectar aportes nuevos pendientes consultando el **listado de sesiones pendientes de QuBeKa** (endpoint del Punto 1, sin contrato cerrado hoy) y filtrar los que este revisor puede revisar. | Punto 1 (Ola 2) + endpoint de listado de QuBeKa | Fuente de detección definida. |
| D.2 | Determinar destinatarios: **roles de revisor del workspace en QuBeKa** (Kuestion no tiene roles; ver bloqueante B3). Enviar correo con el texto del aporte y CTA "Revisar ahora" (destino: la bandeja del Punto 1 cuando exista). | D.1, roles de QuBeKa | Correo a revisores con CTA correcto. |

**Entregable verificable:** cuando un miembro aporta y QuBeKa lo deja pendiente, cada revisor del workspace recibe un correo con CTA a la revisión.

**Validación:** **Esta fase no se puede cerrar hasta que existan** el listado de sesiones de QuBeKa (Punto 1) y la exposición de roles. Se ejecuta con mock del listado para validar la plantilla y el flujo de envío, y se declara pendiente la validación real con su motivo.

---

### Fase E — Correos de reconfirmación pendiente y alerta de vigencia crítica (condicional a Puntos 2 y 3)

**Objetivo:** los dos eventos restantes, ambos dependientes de datos que aún no existen en el código.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| E.1 | Job diario (spec §6-7, ~9am) que detecte preguntas/nodos sobre el umbral de reconfirmación (Punto 2: `fecha_ultima_confirmacion` por fuente) y envíe el correo "¿Sigue siendo válido? [Reconfirmar]" al autor o último revisor, con CTA a la pregunta (acción del Punto 2). | Punto 2 (Ola 2) implementado | Correo de reconfirmación. |
| E.2 | Correo de alerta de vigencia crítica: cuando un chequeo detecte señales Kuaforia `stale_case`/`low_confidence` para una pregunta vigilada (Punto 3, que a su vez depende de que Kuaforia exponga señales por respuesta), enviar correo al autor con CTA de revisión. | Punto 3 (Ola 2) implementado | Correo de alerta de vigencia. |
| E.3 | Mientras P2/P3 no existan: dejar **definido el contrato de datos** que estos correos consumirán (qué campo, dónde se lee, qué dispara el envío) y la plantilla/copy lista para conectarse — sin inventar la fuente de datos. | Documento de contrato (interno) | Diseño + plantilla, sin código muerto. |

**Entregable verificable:** (tras P2/P3) un nodo que cruza el umbral genera el correo de reconfirmación; una señal de vigencia crítica detectada genera el correo de alerta. **Hasta entonces: entregable = contrato de datos + plantillas revisadas con UX.**

**Validación:** con mock del contrato (cuando P2/P3 estén definidos) + checklist FE. La validación real queda **bloqueada por P2/P3** — se declara explícitamente.

---

### Fase F — Proveedor transaccional, QA, regresión y cierre

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| F.1 | Decidir e integrar el proveedor de correo transaccional (SendGrid/Mailgun u otro — ver bloqueante B1): credenciales en `.env`, actualizar `.env.example`, configurar cola/retry del envío (los mailables ya son `ShouldQueue`). | Decisión de infraestructura (B1) | Envío real configurado en el entorno correspondiente. |
| F.2 | E2E real con proveedor/Mailpit en dev: cambio `new_version` → correo recibido con preview + CTA + pie; baja por link firmado; preferencia `none` → no llega. | Fases A–E, F.1 | E2E documentado. |
| F.3 | Regresión de las suites tocadas (Settings, CheckQuestionUpdatesJob, QuestionChecker, AnswerWasEmptyPrev, PendingReviewBadge, NotificationBadge), suite completa, Pint, rebuild de assets si se tocó UI, y documento de cierre. | Todo | Verde y documentado. |

---

## 3. PRUEBAS FUNCIONALES Y DE INTEGRACIÓN

Ítems obligatorios en toda fase con UI o integración (no negociables):

1. **Rebuild y verificación de assets compilados**: los cambios en `settings.blade.php` (Fase A) y cualquier vista nueva exigen `npm run build` y verificar en el CSS servido (`public/build/`) las clases usadas.
2. **Verificación visual en navegador real**: la pantalla de preferencias (3 opciones, copy, estado seleccionado) se inspecciona con DevTools. Las plantillas de correo se inspeccionan renderizadas (vista HTML real del mailable, no solo aserciones de texto).
3. **Compatibilidad con la versión instalada**: verificar contra `composer.json`/vendor antes de usar APIs (p. ej. URLs firmadas de Laravel 11, `Mail::fake`, `Notification::route`). Ejemplo conocido: Livewire 4 tiene `redirect()` pero no `redirectExternal()`.
4. **Fallo visible y claro en runtime**: si el envío falla (proveedor caído, 5xx), el job debe reintentar con backoff y registrar el error — nunca un estado silencioso; la UI de preferencias nunca queda colgada en "cargando…" si el guardado falla.
5. **Prueba contra el servicio real cuando esté disponible**: el envío real contra el proveedor transaccional (o Mailpit en dev) en la Fase F; la detección de aprobados/rechazados contra QuBeKa real cuando el estado de sesión sea consultable; reconfirmación/vigencia cuando P2/P3 existan. Lo no disponible se declara pendiente con su motivo.

### Checklist FA — Infraestructura (preferencias + dedupe + log)

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| FA.1 | Migración booleano → enum conserva datos (true→all, false→none) | Test de migración / factory | Usuarios existentes mapeados sin pérdida |
| FA.2 | Guardar cada nivel en Settings | Navegador real + test | `all`/`critical_only`/`none` persisten y recargan |
| FA.3 | Regla de nivel: evento crítico vs. no crítico | Test unitario del helper | `all` recibe todo; `critical_only` solo críticos; `none` nada |
| FA.4 | Dedupe: mismo evento + misma entidad en ventana | Test unitario | 1 envío; fuera de ventana, 2 |
| FA.5 | Log registra fecha/destinatario/evento | Test unitario | Fila en `email_logs` por envío |
| FA.6 | Baja por link firmado sin login | Navegador real | Preferencia pasa a `none` |
| FA.7 | Rebuild + clases en bundle | `npm run build` + grep | Clases de la UI de preferencias presentes |

### Checklist FB — Correo de cambio de respuesta (spec B)

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| FB.1 | `new_version` genera correo (pref `all`) | Flujo real con mock de proveedor + render del mailable | Correo con asunto por evento, preview de la respuesta, CTA "Ver cambios", pie con links |
| FB.2 | `minor` NO genera correo | Test del flujo | Solo badge in-app; `email_logs` sin fila mail |
| FB.3 | Pref `critical_only` y `none` no reciben este correo | Test | Sin envío |
| FB.4 | Dos cambios en ventana → un correo | Test | Dedupe aplicado |
| FB.5 | CTA lleva a la pregunta correcta | Navegador real (dev) | Enlace resuelve a `/questions/{id}` |

### Checklist FC — Aprobado/rechazado al autor (job de polling)

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| FC.1 | Sesión pasa a `aprobada`/`promocionada` | Http fake del contrato QBK | Correo "aprobado" al autor, una sola vez (dedupe entre corridas) |
| FC.2 | Sesión pasa a `rechazada` | Http fake | Correo "rechazado" al autor |
| FC.3 | Estado sin cambio entre corridas | Http fake | Sin correo duplicado |
| FC.4 | QuBeKa caído / timeout en el job | Http fake | Job reintenta/registra, no marca falso estado |
| FC.5 | Contrato real de QuBeKa | **Pendiente**: requiere estado consultable + datos de autor (B2/B4) | Declarado pendiente |

### Checklist FD — Aporte pendiente de revisión → revisores

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| FD.1 | Nuevo pendiente aparece en listado (mock del Punto 1) | Http fake | Correo al revisor con CTA "Revisar ahora" |
| FD.2 | Roles del workspace | **Pendiente**: requiere roles expuestos por QuBeKa (B3) | Declarado pendiente |
| FD.3 | Validación real | **Pendiente**: requiere Punto 1 + listado real | Declarado pendiente |

### Checklist FE — Reconfirmación y vigencia (tras P2/P3)

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| FE.1 | Nodo sobre umbral → correo "¿Sigue siendo válido?" | Mock del contrato P2 (cuando exista) | Correo con CTA "Reconfirmar" |
| FE.2 | Señal `stale_case`/`low_confidence` → correo de vigencia | Mock del contrato P3 (cuando exista) | Correo con CTA de revisión |
| FE.3 | Validación real | **Pendiente**: depende de P2/P3 | Declarado pendiente |

### Matriz mock vs real

| Fase | Con mock (siempre) | Contra servicio real (cuando esté disponible) |
|---|---|---|
| A — Infra/preferencias | Tests unitarios + navegador | N/A (lógica propia) |
| B — Cambio de respuesta | Render real del mailable + fake del proveedor | Proveedor transaccional o Mailpit en dev (Fase F) |
| C — Aprobado/rechazado | Http fake del contrato QBK | **QuBeKa real**: requiere estado de sesión consultable + autor resoluble (B2/B4) |
| D — Pendiente → revisores | Http fake del listado (Punto 1) | **QuBeKa real + Punto 1** (listado + roles, B2/B3) |
| E — Reconfirmación/vigencia | Mock del contrato de P2/P3 | **Depende de P2/P3 implementados** |
| F — Cierre | Suite completa | E2E real con proveedor/Mailpit |

**Regla de cierre:** ninguna fase se declara cerrada sin completar los ítems obligatorios de esta sección; lo no ejecutable se declara pendiente con su motivo (C/D/E y el envío real dependen de dependencias externas que hoy no existen).

---

## 4. DUDAS Y BLOQUEOS

### Bloqueantes

| # | Pregunta | Para quién |
|---|---|---|
| B1 | **Proveedor de correo transaccional**: el spec §3/§6-6 lo declara "decisión de infraestructura" a tomar antes de implementar. ¿SendGrid, Mailgun u otro? ¿En qué entorno se prueban envíos reales (Mailpit en dev?)? Se necesita la decisión y credenciales para la Fase F. | Infraestructura / producto |
| B2 | **Estado y autor de sesiones QBK**: para el correo de "aprobado/rechazado" (C) y el de "pendiente de revisión" (D), ¿Qué endpoint de QuBeKa expone el estado de las sesiones y el autor de un aporte (incluidos los hechos directamente en QuBeKa, sin draft local)? El listado del Punto 1 no tiene contrato cerrado hoy. Kuestion no puede resolver autores de aportes ajenos sin esos datos. | QuBeKa / Punto 1 |
| B3 | **Roles de revisor por workspace**: el spec §2.1 manda el correo de "aporte pendiente" a "revisores del workspace (según roles de QuBeKa)". Kuestion no tiene modelo de roles (solo `team_dashboard_access`, un flag de solo lectura). ¿Cómo expone QuBeKa quién puede revisar qué, para que Kuestion arme los destinatarios? | QuBeKa |
| B4 | **Nombre del revisor** en el correo de aprobado/rechazado (el ejemplo del spec dice "por [nombre del revisor]"): ¿la API de aprobación de QuBeKa devuelve quién aprobó? Hoy `approve()` de `QbkContributionService` no recibe ese dato. Si no, el copy va sin nombre o se difiere. | QuBeKa |
| B5 | **Datos de P2/P3**: reconfirmación y vigencia crítica dependen de `fecha_ultima_confirmacion` (Punto 2) y de señales Kuaforia por respuesta (Punto 3 — que a su vez tiene su propio bloqueante con Kuaforia). Sin esos contratos, las Fases D/E no tienen fuente de datos real. | QuBeKa / Kuaforia / Puntos 2–3 |

### No bloqueantes

| # | Pregunta | Supuesto / estado | Para quién |
|---|---|---|---|
| D1 | ¿Correo por cada `new_version` o agrupado? | Spec §5: dedupe por ventana (30 min) con resumen si hay varios cambios. Se implementa el dedupe por (evento, entidad, ventana) de A.2; el "resumen de varios" se limita a no duplicar (el detalle fino del resumen agregado se confirma con producto si aparece el caso real). | Producto |
| D2 | ¿La reconfirmación pendiente se avisa una vez o se repite? (decisión abierta del spec §7) | Se asume **un solo correo** al cruzar el umbral (propuesta del spec), configurable. Confirmar con uso real antes de agregar recordatorios. | Producto |
| D3 | ¿El correo de cambio incluye texto completo o resumen? (decisión abierta §7) | Se asume **resumen (primer párrafo)** como propone el spec. | Producto |
| D4 | Preferencias: ¿tabla propia de Kuestion o de QuBeKa? (decisión abierta §7) | **Kuestion** (el correo lo envía Kuestion; el spec lo marca como más simple). Se almacena en `users`. | Producto / interno |
| D5 | ¿Qué pasa con usuarios sin correo? | Kuestion exige email en el registro; se usa el email del usuario (spec §7: "se usa ese"). | — |
| D6 | ¿Correo con sesión expirada / usuario inactivo? | Sí, el correo es independiente del estado de sesión (spec §7). Los jobs corren en cola/scheduler, no requieren sesión. | — |
| D7 | Columna de preferencia: ¿renombrar a algo como `email_preference` o reutilizar `email_notifications` con nuevo tipo? | El spec §5 nombra la columna `email_notifications` con enum. Se mantiene el nombre y se cambia el tipo (string + check) para no tocar decenas de referencias, con cast y constantes en el modelo. | Interno |

---

## 5. ESFUERZO ESTIMADO

| Fase | Esfuerzo estimado | Incertidumbre |
|---|---|---|
| **Fase A** — Infra (preferencias enum + dedupe + log + UI) | M (1.5–2 d) | Media: toca una columna existente (booleano→enum) con referencias en modelo, Livewire y varios tests; el dedupe es lógica nueva pero acotada. |
| **Fase B** — Correo de cambio alineado al spec | S–M (0.5–1.5 d) | Baja-media: el flujo ya existe; el trabajo es gate por `changeType`, agregar preview y rediseñar la plantilla con pie. |
| **Fase C** — Aprobado/rechazado al autor (polling) | M (1–1.5 d) | **Media-alta por dependencia externa**: el job es directo con mock, pero el cierre real depende de qué exponga QuBeKa (B2/B4). |
| **Fase D** — Pendiente de revisión → revisores | M (1–1.5 d) | **Alta**: bloqueada por Punto 1 y roles de QuBeKa (B2/B3); sin esos, solo plantilla + mock. |
| **Fase E** — Reconfirmación + vigencia | S (0.5–1 d) de diseño hoy; M (1–2 d) al implementar | **Alta**: 100% dependiente de P2/P3 (B5); hoy solo contrato + plantillas. |
| **Fase F** — Proveedor + QA + cierre | S–M (0.5–1.5 d) | Media: depende de la decisión de infraestructura (B1) y del acceso al proveedor/Mailpit. |
| **TOTAL** | **M (4.5–9 d)** | La mayor incertidumbre está repartida entre B1 (infraestructura) y B2–B5 (dependencias externas: QuBeKa, Punto 1, P2/P3). El núcleo de valor inmediato (Fases A+B) es de ~2–3.5 d y no depende de nadie. |

---

## 6. FUERA DE ALCANCE

| Elemento | Por qué queda fuera |
|---|---|
| Digest diario/semanal | Spec §2.2 lo excluye explícitamente de esta versión. |
| Preferencias por tipo de evento | Spec §2.2: una sola preferencia global en esta versión. |
| Correos por cambios `minor` | Spec §2.1: solo `new_version` y eventos con acción humana en esta versión. |
| Migración a eventos push de QuBeKa | Spec §3: declarada pendiente; este punto usa polling como decisión pragmática. |
| Bandeja de revisión (Punto 1) | Es otro punto de la Ola 2; este punto solo la referencia como destino de CTA. |
| Datos de reconfirmación (P2) y señales de vigencia (P3) | Son otros puntos; este punto solo consume sus contratos cuando existan. |
| Canales fuera del correo (Slack, calendario, etc.) | Solo mencionados como contexto en §0; no son alcance. |
| Configuración/gestión del proveedor transaccional más allá de credenciales y cola | Es infraestructura; se integra con el sistema de colas existente y `.env`. |
| Notificaciones in-app nuevas para estos eventos | Los correos de aportes (C/D/E) no agregan payloads al badge in-app existente (F6); la paridad in-app de esos eventos pertenece a la bandeja del Punto 1. |

---

## 7. Entregables comprometidos contra el contrato

Los siguientes entregables de esta fase comprometen el contrato `docs/CONTRATO_API_OLA2.md`:

| # | Entregable | Sección del contrato | Compromiso |
|---|---|---|---|
| 1 | Evento `new_version` → correo, con preview + CTA + pie | §8.2 (implicito: consumo del cambio que QBK notifica) | `AnswerChangedMail` alineado al spec: solo `new_version`, preview, CTA "Ver cambios", pie de baja. |
| 2 | Polling de `GET /api/v1/sesiones-analisis?estado=historial` para detectar aprobado/rechazado | §4.1, §8.1 | Job `CheckContributionStatusJob` consume listado con filtros contractuales; detecta `promocionada`/`rechazada` (no `aprobada`). |
| 3 | Autor del aporte via `autor_email`/`autor_nombre` (Nudo B2) | §10 | Correo de aprobado/rechazado al autor declarado; sesiones pre-B2 sin autor → caso normal, no error. |
| 4 | Endpoint `GET /api/v1/workspaces/{id}/miembros` para correo a revisores | §8.2 | Correo "aporte pendiente de revisión" a revisores según roles expuestos. |
| 5 | Filtro `actualizado_desde` como optimización del polling | §8.3 | Parámetro opcional sobre `estado=historial`; no requisito del ciclo. |
| 6 | Contrato de datos para P2 (reconfirmación) y P3 (vigencia) | §5.2, §6 | Plantillas listas para conectarse; datos reales diferidos a P2/P3. |

---

## 8. Trazabilidad de referencias cruzadas (Ola 1)

- Este plan no reabre el contrato de la Ola 1. Referencia conceptual `ola1-preguntas-abiertas.md` solo como registro histórico de la Ola 1. El correo de cambio basado en `AnswerChangedNotification` + `AnswerChangedMail` ya existe desde Ola 1 P5/6; este punto lo alinea al spec (§B) sin tocar su superficie in-app.
