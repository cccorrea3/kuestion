# Kuestion — Plan de Implementación · Fase 1 (Deuda técnica)

> **Versión:** 1.1 | **Fecha:** 2026-08-14 | **Fuente:** `Plan_Mejora_Kuestion_v2.4.md` (cerrado, v2.4) | **Alcance:** Bloques 1–6 | **Estado:** documento de implementación (el CÓMO)
>
> **v1.1 (2026-08-14):** incorpora las resoluciones de producto/tecnología/ingeniería sobre las preguntas abiertas de la v1.0 (ver §6). Cambios: tareas 6.6 (re-validación de key en `/settings`) y 6.7 (prompt opcional para usuarios existentes) nuevas en el Bloque 6; Bloque 5 sin API interna (diferida, YAGNI).
>
> **Autocontenido:** este documento incluye el contexto, las restricciones y las decisiones pendientes del documento maestro que aplican a esta fase. No reabre decisiones cerradas (QUÉ/POR QUÉ); define cómo implementarlas. Las tareas se apoyan en herramientas de IA generadora (Opencode u otras): la sección 4 indica dónde reutilizar patrones existentes y cómo acotar el contexto por tarea.

---

## 1. Contexto de la fase

### 1.1 Qué cubre esta fase

| Bloque | Objetivo (del maestro) | Esfuerzo |
|---|---|---|
| **1 — Cuenta y comunicación** | Notificaciones por correo reales (1.1) + password reset y perfil de usuario (1.2) | M (4–6 d) |
| **2 — Seguridad** | CSP con nonces, sin `unsafe-inline` (1.3) | M (1–2 d) |
| **3 — Integridad bajo concurrencia** | `lockForUpdate` en versionado (1.4), extensión multi-worker (1.9), política de retención (1.6), manejo de respuestas vacías (1.8) | M (3 d) |
| **4 — Escalabilidad de búsqueda** | Índice FULLTEXT en `question_text` (1.10) | M (2–3 d) |
| **5 — Observabilidad base** | Métricas clave en `daily_metrics`, consultables vía Artisan (1.5) | S-M (1.5 d) |
| **6 — Conexión de tenant** | Validación de tenant por API key scoped, UX "Conectate a Kuaforia" (1.11) + re-validación de key en `/settings` (6.6) | L (~3.5–5.5 d + dependencia externa) |

**Total estimado:** ~16–22 días hábiles con bloques 4/5/3/1/2 en paralelo y el bloque 6 en paralelo sujeto a coordinación externa.

### 1.2 Restricciones del documento maestro aplicables a esta fase

1. **No se modifica la integración REST con Kuaforia en su esencia:**
   - El llamado `POST /api/consult/{tenant_slug}` sigue siendo síncrono.
   - El mecanismo de detección de cambios (hash SHA-256 + similitud coseno) se mantiene intacto.
   - El `CheckQuestionUpdatesJob` sigue siendo el responsable de la re-consulta periódica.
   - En esta fase el job se toca **solo** para robustez (1.4, 1.8, 1.9): locks, manejo de vacío y la escritura de la notificación. **Nunca** su lógica de detección ni el contrato con Kuaforia.
2. **Se prioriza la deuda técnica** como base de todas las mejoras posteriores.
3. **Validación de tenant mediante API key scoped (decisión cerrada):** se descarta usuario/clave. El usuario pega su API key de Kuaforia (prefijo `kfr_`); Kuestion valida la key contra Kuaforia (vía REST o MCP) y resuelve el `tenant_slug` automáticamente. La UX se diseña como "Conectate a Kuaforia".
4. **Exclusión de TenantTools:** las operaciones de listado de tenants están excluidas por diseño en Kuaforia. La API key scoped resuelve la validación sin acceder a TenantTools.

### 1.3 Decisiones pendientes del maestro aplicables a esta fase

- **Pendiente #1 — Validación de tenant (bloque 6):** confirmar con Ingeniería de Kuaforia si existe un endpoint liviano (REST o MCP) que, dada una API key de cliente (`kfr_`), devuelva el `tenant_slug` asociado. Si no existe, definir si se construye o si Kuestion usa el MCP con `stateless: true`. **Impacto en el diseño:** el bloque 6 se implementa con un único punto de resolución detrás de una config (`rest | mcp`) que permite elegir la vía sin cambiar el resto del flujo (ver 6.1). No bloquea las tareas 6.2–6.6 (migración, UI, persistencia).

### 1.4 Estado real del código relevante (verificado en el repo)

| Supuesto / hallazgo | Realidad en el código |
|---|---|
| "El modelo `notifications` ya está preparado" (maestro, Bloque 1) | La **tabla** existe pero con forma custom: `user_id`, `type`, `data`, `read_at` (sin `notifiable_type`/`notifiable_id`). No hay modelo de notificación ni clases `app/Notifications` (directorio vacío). El job inserta crudo con `DB::table('notifications')->insert(...)` dentro de la transacción. |
| `User` listo para notificaciones | El trait `Notifiable` ya está importado en `User` (hoy inactivo por la forma de la tabla). Al adaptar la tabla al esquema estándar, `$user->notify()` funciona sin código custom. |
| Correo | `MAIL_MAILER=log` en `.env.example`; no hay template de email. |
| Password reset | Tabla `password_reset_tokens` existe (esquema estándar); no hay flujo (rutas/components). Lifetime default 60 min (`config/auth.php`). |
| Settings | No existe `/settings` ni componente. |
| CSP | `SecurityHeaders` middleware emite CSP con `'unsafe-inline'` en `script-src`/`style-src` y `https://unpkg.com` (lucide por CDN + `lucide.createIcons()` inline en el layout). |
| Concurrencia | `CheckQuestionUpdatesJob` calcula `max('version_number') + 1` sin lock (comentario `ponytail:` lo documenta). `acceptChange`/`dismissChange` (API y Livewire) transaccionales pero sin lock de fila. |
| Retención | `CleanupOldVersionsJob` ya conserva todas las versiones de preguntas activas y las últimas 5 de archivadas (trashed). El número 5 está hardcodeado. |
| Respuestas vacías | Si Kuaforia devuelve `answer` vacío, hoy se guarda igual (hash de string vacío) y se crea versión. |
| Búsqueda | `LIKE` en 4 lugares: `QuestionFeed::getQuestionsProperty`, `QuestionController::index`, `RelationsPanel::updatedSearch`, y `RelationSuggester` carga todos los candidatos en memoria. |
| Métricas | No existe tabla de métricas ni comandos. |
| Registro/tenant | `Register` usa dropdown de `config('services.kuaforia.tenants')` (solo `ispend` hardcodeado). |
| Consumidores de `notifications.user_id` | `NotificationBadge` (count + markReadAndGo), `QuestionDetail::markNotificationRead`, `QuestionController::markNotificationRead` — los 3 deberán migrar a `notifiable_*`. |

### 1.5 Secuencia recomendada y dependencias finas

```
Bloque 5 (métricas)  ── independiente, chico, sirve de calentamiento
Bloque 4 (FULLTEXT)  ── independiente
Bloque 3 (concurrencia) ── independiente (tocha el job pero no la detección)
Bloque 1 (1.1 correo → 1.2 reset/settings)  ── 1.1 es prerequisito de 1.2
Bloque 2 (CSP nonces) ── independiente (verificar temprano compat. Livewire)
Bloque 6 (API key)    ── en paralelo; depende de pendiente #1 (externa)
```

- **Interna al bloque 1:** 1.1 (infraestructura de notificación + correo) antes de 1.2 (reset usa correo; settings usa el toggle).
- **Interna al bloque 3:** 1.4 (job) y 1.9 (extensión) son el mismo patrón; 1.6 y 1.8 independientes.
- **Bloque 6:** las tareas 6.2–6.7 no dependen del endpoint de Kuaforia; solo 6.1 (resolver) requiere la definición de la vía. Se puede dejar 6.1 detrás de config y avanzar con la UI contra un fake.

---

## 2. Diseño técnico por bloque

### Bloque 1 — Cuenta y comunicación con el usuario

#### 1.1 Notificaciones por correo reales — Esfuerzo M (~2–3 d)

**Criterios de aceptación (del maestro):**
- El usuario recibe un correo cuando una pregunta vigilada cambia, con enlace directo.
- El usuario puede activar/desactivar notificaciones por correo desde su perfil *(el toggle se construye acá; la pantalla de perfil se cierra en 1.2)*.

**Desglose de tareas:**

| ID | Tarea | Archivos clave | Dep | Esf |
|----|-------|----------------|-----|-----|
| 1.1.1 | Adaptar tabla `notifications` al esquema estándar de Laravel: agregar `notifiable_type` (string) y `notifiable_id` (uuid), backfill desde `user_id` (`App\Models\User`), eliminar `user_id` y su índice. | migración nueva | — | S |
| 1.1.2 | Migrar consumidores a `notifiable_*`: `NotificationBadge` (count, markReadAndGo), `markNotificationRead()` en `QuestionDetail` y `QuestionController` → `auth()->user()->notifications()->...` / `where('notifiable_id', ...)`. | `app/Livewire/NotificationBadge.php`, `app/Livewire/QuestionDetail.php`, `app/Http/Controllers/QuestionController.php` | 1.1.1 | S |
| 1.1.3 | Crear `App\Notifications\AnswerChangedNotification` con el payload actual del job (`question_id`, `question_text` limit 80, `version_number`, `change_type`, `similarity`). `via($notifiable)` → `['database']` + `['mail']` si `$notifiable->email_notifications`. | `app/Notifications/AnswerChangedNotification.php` (nuevo) | 1.1.1 | S |
| 1.1.4 | Refactor del job: reemplazar el `DB::table('notifications')->insert(...)` por `$question->user->notify(new AnswerChangedNotification(...))`. | `app/Jobs/CheckQuestionUpdatesJob.php` | 1.1.3 | S |
| 1.1.5 | Template de correo `resources/views/emails/answer-changed.blade.php`: pregunta (truncada), tipo de cambio, similitud, versión, y enlace directo a `route('questions.show', $questionId)`. Estilos inline simples, sin frameworks. | vista nueva | 1.1.3 | S |
| 1.1.6 | Config SMTP en `.env` (`MAIL_MAILER=smtp`, host, puerto, credenciales, `MAIL_FROM` ya definido). Proveedor concreto (SendGrid/Mailgun/otros): elección de ops, el código es agnóstico (Laravel Mail). Dev: Mailpit/Mailhog. | `.env`, `config/mail.php` (verificar) | — | S |
| 1.1.7 | Columna `email_notifications` (boolean, default `true`) en `users` + cast en `User`. | migración nueva, `app/Models/User.php` | — | S |

**Decisiones de implementación (a criterio, no contradicen el maestro):**
- **Usar notificaciones nativas de Laravel** (la tabla se adapta; `User` ya tiene `Notifiable`). Alternativa descartada: canal custom sobre la tabla actual (más código propio, peor mantenibilidad).
- **`$user->notify()` (encolada)** en lugar de `notifyNow()`: el job no se bloquea esperando SMTP; la creación de la notificación pasa a un job de notificación con retries estándar. **Trade-off documentado:** la notificación deja de ser atómica con la creación de la versión (si el job de notificación falla, la versión existe y la notificación se reintenta). Si se prefiere atomicidad estricta a costa de latencia, usar `notifyNow()` dentro de la transacción — decisión del implementador, documentar cuál se eligió.
- El valor de `type` pasa de `'answer_changed'` a la clase (`App\Notifications\AnswerChangedNotification`). Ningún consumidor filtra por `type` (todos filtran por `data->question_id`), por lo que no hay impacto.
- La notificación `QueryErrorNotification` (necesaria en 1.8) reutiliza exactamente este patrón; se crea en el bloque 3.

#### 1.2 Recuperación de contraseña + perfil de usuario — Esfuerzo M (~2–3 d)

**Criterios de aceptación (del maestro):**
- El usuario puede solicitar un enlace de reseteo de contraseña (válido 60 min).
- El usuario puede cambiar su nombre, email y contraseña desde `/settings`.
- (Bloque 1.1) El usuario puede activar/desactivar notificaciones por correo desde su perfil.

**Desglose de tareas:**

| ID | Tarea | Archivos clave | Dep | Esf |
|----|-------|----------------|-----|-----|
| 1.2.1 | `App\Livewire\Auth\ForgotPassword` (`/forgot-password`, guest): email → `Password::broker()->sendResetLink()` (tabla ya existe, lifetime 60 min). Reutilizar patrón/UI de `Login`. | `app/Livewire/Auth/ForgotPassword.php` + vista | 1.1.6 (correo) | S |
| 1.2.2 | `App\Livewire\Auth\ResetPassword` (`/reset-password/{token}`, guest): email + password + confirmación → `Password::reset()` → login → redirect a feed. Mensaje claro si token inválido/expirado. | `app/Livewire/Auth/ResetPassword.php` + vista | 1.2.1 | S |
| 1.2.3 | `App\Livewire\Settings` (`/settings`, auth): secciones — datos personales (nombre, email con `unique:users,email` excluyendo self), contraseña (actual + nueva + confirmación, verificar actual con `Hash::check`), notificaciones (toggle `email_notifications`). | `app/Livewire/Settings.php` + vista | 1.1.7 | M |
| 1.2.4 | Rutas (guest: forgot/reset; auth: settings) + links: "¿Olvidaste tu contraseña?" en login; menú de usuario en el header → Settings. | `routes/web.php`, `resources/views/layouts/app.blade.php`, vistas auth | 1.2.1, 1.2.3 | S |

**Decisiones de implementación:**
- Reutilizar `x-input`/`x-button` y el patrón de validación/errores de `Login`/`Register` (mismo `#[Layout('layouts::app')]`).
- No implementar verificación de email ni "recordarme" (backlog del maestro, fuera de alcance).

### Bloque 2 — Seguridad: CSP con nonces — Esfuerzo M (~1–2 d)

**Criterios de aceptación (del maestro):**
- Las cabeceras CSP no incluyen `unsafe-inline`.
- Livewire sigue funcionando correctamente.
- Las herramientas de seguridad (Mozilla Observatory) reportan mejora.

**Desglose de tareas:**

| ID | Tarea | Archivos clave | Dep | Esf |
|----|-------|----------------|-----|-----|
| 2.1 | Publicar y activar CSP-safe mode de Livewire: `php artisan vendor:publish --tag=livewire:config` y `'csp_safe' => true` (confirmado en docs Livewire 4.x). | `config/livewire.php` | — | S |
| 2.2 | `SecurityHeaders`: generar nonce por request (`base64_encode(random_bytes(18))`), compartirlo (`View::share('cspNonce', ...)`), y construir CSP sin `unsafe-inline`: `default-src 'self'; base-uri 'self'; form-action 'self'; script-src 'self' 'nonce-{n}'; style-src 'self' 'nonce-{n}'; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'`. | `app/Http/Middleware/SecurityHeaders.php` | — | S |
| 2.3 | Integrar el nonce con Livewire: usar el hook de nonces de la versión instalada (`Livewire::useScriptNonce(...)` / `withScriptNonce`) para que `@livewireScripts`/`@livewireStyles` lleven el nonce. **Nota:** si en la versión instalada los estilos inline de Livewire no pueden portar nonce, reportarlo como desviación con evidencia (el criterio exige cero `unsafe-inline`; no aceptarlo silenciosamente). | `app/Providers/AppServiceProvider.php`, layout | 2.1, 2.2 | M |
| 2.4 | Lucide: hoy CDN `unpkg` + `lucide.createIcons()` inline. **Opción A (recomendada):** `npm install lucide` y bundle local vía `resources/js/app.js` (elimina dominio externo de la CSP; el script pasa a ser `'self'`). **Opción B:** mantener unpkg con nonce en el tag. | `package.json`, `resources/js/app.js`, layout | 2.2 | S |
| 2.5 | Aplicar nonce a estilos/scripts inline propios del layout (p.ej. `x-cloak`, `lucide.createIcons()` si se queda inline). | `resources/views/layouts/app.blade.php` | 2.4 | S |

**Decisiones de implementación:**
- El nonce se genera una vez por request y se reutiliza para `script-src` y `style-src`.
- Verificar al inicio si el proyecto usa fuentes de Google (revisar `resources/css/app.css`); si no las usa, quitar `fonts.googleapis.com`/`fonts.gstatic.com` de la CSP.
- `'strict-dynamic'` es opcional (recomendado por docs de Livewire) — evaluar en la prueba de humo si no rompe `'self'`.

### Bloque 3 — Integridad de datos bajo concurrencia y manejo de fallos — Esfuerzo M (~3 d)

**Criterios de aceptación (del maestro):**
- El job `CheckQuestionUpdatesJob` puede correr en múltiples workers sin generar duplicados ni condiciones de carrera.
- Todas las escrituras críticas usan `lockForUpdate`.
- `CleanupOldVersionsJob` respeta la política de retención documentada.
- El usuario ve una notificación de "error en la consulta" cuando Kuaforia devuelve vacío.

**Desglose de tareas:**

| ID | Tarea | Archivos clave | Dep | Esf |
|----|-------|----------------|-----|-----|
| 3.1 (1.4) | Job: dentro de la transacción existente, re-leer la fila con `Question::whereKey($question->id)->lockForUpdate()->first()` antes de `max('version_number') + 1` y de crear la versión; usar la fila lockeada para el resto de la transacción. | `app/Jobs/CheckQuestionUpdatesJob.php` | — | S |
| 3.2 (1.9) | Extender el patrón: `lockForUpdate` en `acceptChange`/`dismissChange` (API `QuestionController` y Livewire `QuestionDetail`) antes de actualizar `has_unreviewed_changes`. | `QuestionController.php`, `app/Livewire/QuestionDetail.php` | 3.1 | S |
| 3.3 (1.9) | Creación de pregunta (API `store` y Livewire `CreateQuestion::save`): ya transaccionales. **Nota de diseño:** la versión v1 es determinista (no hay `max()`), por lo que no hay contienda real que lockear; se mantiene el patrón transaccional por consistencia. No agregar locks innecesarios. | — (verificar) | 3.1 | S |
| 3.4 (1.6) | Política de retención: mover el "5" a config (`config('kuestion.retention.archived_versions') = 5`); agregar lock de fila por pregunta en `CleanupOldVersionsJob` (multi-worker); documentar la política (activas = todas, archivadas = últimas 5). | `app/Jobs/CleanupOldVersionsJob.php`, `config/kuestion.php` (nuevo) | — | S |
| 3.5 (1.8) | Respuestas vacías en el job: si `trim($response->answerText) === ''` → **no** crear versión; actualizar `last_consulted_at = now()`; crear notificación `QueryErrorNotification` (payload: `question_id`, motivo) salvo que ya exista una no leída para esa pregunta (anti-spam); `Log::warning`. | `app/Jobs/CheckQuestionUpdatesJob.php`, `app/Notifications/QueryErrorNotification.php` (nuevo) | 1.1.3 (patrón) | S-M |

**Decisiones de implementación:**
- El `chunk(100)` del job se mantiene; el lock es por pregunta dentro de su transacción.
- Anti-spam de 1.8: solo se notifica el primer error no leído por pregunta (determinístico y testeable). Si el equipo prefiere otra política (p.ej. notificar siempre), es una pregunta de producto → ver §6.

### Bloque 4 — Escalabilidad de búsqueda: FULLTEXT — Esfuerzo M (~2–3 d)

**Criterios de aceptación (del maestro):**
- La búsqueda de texto es más rápida y relevante.
- El `RelationSuggester` puede usar `FULLTEXT` en lugar de carga en memoria.

**Desglose de tareas:**

| ID | Tarea | Archivos clave | Dep | Esf |
|----|-------|----------------|-----|-----|
| 4.1 | Migración: índice `fullText('question_text')` en `questions` (InnoDB). | migración nueva | — | S |
| 4.2 | Reemplazar `LIKE` por `whereFullText('question_text', $search)` en `QuestionFeed::getQuestionsProperty` y `QuestionController::index`; fallback a `LIKE` si `mb_strlen($search) < 3` (tokenizador MySQL, min 3) o si el modo natural devuelve vacío. | `app/Livewire/QuestionFeed.php`, `app/Http/Controllers/QuestionController.php` | 4.1 | S |
| 4.3 | Ídem en `RelationsPanel::updatedSearch`. | `app/Livewire/RelationsPanel.php` | 4.1 | S |
| 4.4 | `RelationSuggester::suggest`: pre-filtrar candidatos con `whereFullText('question_text', implode(' ', $keywords))` en lugar de `->get()` de todas las activas; mantener el scoring actual (tags ×3 + keywords) y el slice 10; fallback a escaneo completo si `$keywords` está vacío. | `app/Services/RelationSuggester.php` | 4.1 | M |

**Decisiones de implementación:**
- El pre-filtro FULLTEXT solo restringe candidatos (deben contener ≥1 keyword); el scoring no cambia, por lo que `RelationSuggesterTest` debe seguir pasando sin modificar fixtures.
- Documentar la limitación: palabras de 1–2 caracteres caen al fallback `LIKE`.

### Bloque 5 — Observabilidad base — Esfuerzo S-M (~1.5 d)

**Criterios de aceptación (del maestro):**
- Las métricas se almacenan en una tabla `daily_metrics`.
- Se pueden consultar vía Artisan o API interna.

**Desglose de tareas:**

| ID | Tarea | Archivos clave | Dep | Esf |
|----|-------|----------------|-----|-----|
| 5.1 | Migración `daily_metrics`: `id`, `metric_date` (date, unique), `preguntas_activas`, `preguntas_creadas`, `cambios_detectados`, `cambios_revisados`, `cambios_sin_revisar`, `tiempo_revision_promedio_horas` (decimal), timestamps. | migración nueva | — | S |
| 5.2 | `CollectDailyMetrics` (scheduler diario 00:30): agrega el día anterior. Definiciones: activas = `status='active'` sin trashed; creadas = `created_at` del día; detectados = versiones con `version_number > 1` creadas el día; revisados = notificaciones `answer_changed` con `read_at` del día; sin revisar = `has_unreviewed_changes=true` al momento; **proxy de tiempo de revisión = `AVG(read_at - created_at)`** sobre notificaciones leídas ese día (documentado: `read_at` se setea al aceptar/descartar o abrir la notificación). | `app/Console/Commands/CollectDailyMetrics.php` (nuevo), `routes/console.php` | 5.1 | M |
| 5.3 | Comando `metrics:show` (Artisan) para consultar por fecha/rango. | `app/Console/Commands/ShowMetrics.php` (nuevo) | 5.1 | S |

**Decisiones de implementación:**
- Se entrega la vía Artisan; la "API interna" del criterio se **difiere (resolución de revisión, YAGNI)**: no hay consumidor concreto y el Bloque 12 (Fase 3) leerá `daily_metrics` directo desde el componente Livewire, sin pasar por una API.
- **Nota de implementación (2026-08-14, Bloque 5 implementado):** el filtro de "revisados" cubre tanto `answer_changed` (valor actual de la tabla custom) como `App\Notifications\AnswerChangedNotification` (valor que introducirá el Bloque 1 al migrar a notificaciones nativas). El Bloque 1 no requiere tocar este comando. El promedio de tiempo de revisión se calcula en PHP (no `TIMESTAMPDIFF`) para ser agnóstico del motor de BD (MySQL en prod, SQLite en tests).
- El proxy de tiempo de revisión es una decisión técnica de medición; documentar en el docblock del comando.

### Bloque 6 — Conexión de tenant: "Conectate a Kuaforia" — Esfuerzo L (~3.5–5.5 d + dependencia externa)

**Criterios de aceptación (del maestro):**
- El registro de usuario valida la API key en tiempo real.
- El tenant se resuelve automáticamente, no se selecciona de una lista.
- No se usan TenantTools.
- La UX es "Conectate a Kuaforia" (campo para pegar la key).

**Desglose de tareas:**

| ID | Tarea | Archivos clave | Dep | Esf |
|----|-------|----------------|-----|-----|
| 6.1 | Punto único de resolución: `resolveTenantFromApiKey(string $kfrKey): array` (o DTO simple) en `KuaforiaService` (o servicio `TenantResolver`). Config `services.kuaforia.tenant_resolution = rest | mcp`: vía REST (endpoint liviano de Kuaforia, nombre a confirmar — pendiente #1) o vía MCP (`POST /api/v1/mcp` con `stateless: true`). | `app/Services/KuaforiaService.php`, `config/services.php` | pendiente #1 | M |
| 6.2 | Migración `users`: columna `kuaforia_api_key` (text, nullable) con cast `encrypted` en `User`. | migración nueva, `app/Models/User.php` | — | S |
| 6.3 | Registro: reemplazar el dropdown por campo "API key de Kuaforia (kfr_)" con validación en vivo (debounced, `updatedKuaforiaApiKey`): llama al resolver; en éxito muestra la organización resuelta y habilita submit; en error muestra mensaje y bloquea. | `app/Livewire/Auth/Register.php` + vista | 6.1, 6.2 | M |
| 6.4 | Persistir `kuaforia_api_key` cifrada + `tenant_slug` resuelto en la creación del usuario. | `app/Livewire/Auth/Register.php` | 6.3 | S |
| 6.5 | Mensajes de error claros: key inválida / sin conexión con Kuaforia / no se pudo resolver el tenant. | vista registro | 6.3 | S |
| 6.6 | Campo "API key de Kuaforia" en `/settings` (re-validación): reutiliza el resolver de 6.1; valida la key nueva y actualiza `kuaforia_api_key` cifrada (+ `tenant_slug` si cambia). **Incluido en alcance por resolución de revisión** (una key revocada en Kuaforia dejaría al usuario sin salida propia). | `app/Livewire/Settings.php` (1.2.3), vista | 6.1, 1.2.3 | S |
| 6.7 | (Opcional, no bloqueante) Prompt "conectá tu key de Kuaforia" para usuarios existentes creados con dropdown: la próxima vez que entren, sin ser obligatorio. Los deja listos para cuando el Bloque 8 (Fase 2) necesite el `workspace_id` real en lugar del mapeo manual. | componente/sesión | — | S |

**Decisiones de implementación:**
- La consulta REST (POST /api/consult) **sigue usando la API key compartida** (`services.kuaforia.api_key`); la key `kfr_` del usuario es solo para validación/resolución de tenant. El flujo de consulta no cambia.
- El diseño detrás de config (`rest | mcp`) permite avanzar con la UI (6.2–6.7) contra un fake del resolver sin esperar el endpoint real.
- La re-validación desde `/settings` (6.6) reutiliza el mismo resolver de 6.1: es un campo más en el componente `Settings` de 1.2.3, sin lógica nueva.
- No usar TenantTools (excluido).

---

## 3. Verificación (QA/Review)

### 3.1 Mapa de criterios de aceptación → verificación

| Criterio (bloque) | Verificación automatizada | Verificación manual |
|---|---|---|
| Correo al cambiar + enlace directo (1.1) | Feature test: job detecta cambio → `Mail::fake()`, `Mail::assertQueued(AnswerChangedNotification)` + notificación DB creada con payload correcto (mismas claves que hoy). | Flujo real con Mailpit (dev) / sandbox del proveedor; abrir el enlace del correo y verificar que lleva a la pregunta. |
| Toggle de correo desde perfil (1.1/1.2) | Feature test: `email_notifications=false` → no se encola mail (sí notificación DB); toggle en `/settings` persiste. | Alternar en `/settings` y verificar comportamiento con una pregunta que cambia. |
| Reset válido 60 min (1.2) | Feature test: `Password::sendResetLink` → token resetea la contraseña; token expirado (o inválido) → error y no resetea. | Flujo completo con correo real en dev. |
| Cambiar nombre/email/contraseña desde `/settings` (1.2) | Feature tests por cada campo: email único excluyendo self; contraseña actual incorrecta → error. | Editar los 3 campos en `/settings`. |
| CSP sin `unsafe-inline` (1.3) | Feature test: `GET /` con `APP_ENV=production` → header CSP no contiene `unsafe-inline` y contiene `nonce-`. | Navegación completa en producción local (crear pregunta, toggles, diff, follow-up); consola del navegador sin violaciones CSP; Mozilla Observatory. |
| Multi-worker sin duplicados (1.4/1.9) | Feature test: ejecutar el job dos veces sobre la misma pregunta vencida → una sola versión nueva (la segunda no crea por `last_consulted_at`). Carrera real: cubierta por diseño (lock) + prueba manual. | Dos `queue:work` simultáneos contra una pregunta vencida con el mock de Kuaforia; verificar una única versión. |
| Escrituras críticas con `lockForUpdate` (1.9) | Revisión de código (los puntos de escritura usan el patrón) + tests de aceptar/descartar existentes siguen pasando. | Aceptar/descartar en paralelo desde dos pestañas. |
| Retención documentada (1.6) | Feature test `CleanupOldVersionsJob`: activa conserva todas; archivada conserva las últimas 5. | `php artisan db:seed` + inspección de la tabla. |
| Error en consulta vacía (1.8) | Feature test: Kuaforia devuelve `''` → no se crea versión, notificación `query_error` creada, `last_consulted_at` actualizado; segunda ejecución no duplica la notificación. | Job manual con mock devolviendo vacío. |
| Búsqueda más rápida/relevante (1.10) | Tests de búsqueda existentes pasan; test nuevo con FULLTEXT; `EXPLAIN` muestra el índice fulltext. | Búsquedas con acentos y palabras cortas (fallback LIKE). |
| RelationSuggester con FULLTEXT (1.10) | `RelationSuggesterTest` pasa sin cambios de fixtures. | Crear pregunta y verificar sugerencias en vivo. |
| Métricas en `daily_metrics` (1.5) | Feature test del comando: seed → ejecutar → fila correcta. | `php artisan metrics:show`. |
| Registro valida key en tiempo real (1.11) | Feature test: `Http::fake` del resolver → key válida crea usuario con tenant resuelto + key cifrada; key inválida → error y no crea usuario. | Flujo completo contra el endpoint real (cuando exista). |
| Re-validación de key desde `/settings` (6.6) | Feature test: key nueva válida → se actualiza `kuaforia_api_key` (+ `tenant_slug` si cambia); key inválida → error sin cambios. | Cambiar la key en `/settings`. |

### 3.2 Plan de regresión — alerta roja

**Lo que NO debe romperse en esta fase. Cualquier cambio en estos puntos es una alerta roja y debe detener el merge:**

1. **`POST /api/consult/{tenant_slug}`**: URL, método, payload `{question, conversation_id}`, auth Bearer compartida, timeout 120 s, circuit breaker (3 fallos → pausa 60 s), parseo tolerante `{answer|response, confidence, sources, conversation_id}`.
2. **`ChangeDetector`**: hash SHA-256, umbral de similitud 0.8 (`minor` / `new_version`), tests unit existentes.
3. **`CheckQuestionUpdatesJob`**: frecuencia de re-consulta (weekly/monthly/quarterly), clasificación `unchanged`/`minor`/`new_version`, actualización de `last_consulted_at`/`last_change_detected_at`/`has_unreviewed_changes`. Solo cambia: escritura de la notificación (vía `notify()`), locks y manejo de vacío — nunca la detección.
4. **Aislamiento por `current_user_id()`** en todas las queries; los jobs resuelven tenant desde `$question->user` (sin sesión).
5. **Suite actual**: los 16 tests (27 assertions) deben seguir pasando después de cada bloque.
6. **UI existente**: feed, detalle, diff, relaciones, tags — sin cambios fuera de los especificados.

### 3.3 Validación aislada por bloque

- Cada bloque se cierra con sus feature/unit tests + un smoke manual mínimo (ver 3.1).
- Orden de cierre sugerido: **5 → 4 → 3 → 1 → 2 → 6** (6 en paralelo con 1–5).
- Cierre de fase: suite completa + smoke end-to-end (registro → crear pregunta → job → notificar → aceptar/descartar) + `git diff` acotado a los archivos esperados (verificar que el mecanismo REST/hash no se tocó).

---

## 4. Eficiencia de código/tokens

**Reutilizar patrones existentes (no generar lógica nueva):**

| Tarea | Reutilizar |
|---|---|
| 1.1 (notificaciones) | `User` ya importa `Notifiable` (hoy inactivo) → `$user->notify()` sin código custom. El payload actual del job (claves JSON) se conserva tal cual en `toDatabase`. |
| 1.2 (reset/settings) | Patrón de `Login`/`Register` (Livewire, `#[Layout]`, validación, errores) y componentes `x-input`/`x-button`. |
| 2 (CSP) | Extender el `SecurityHeaders` existente; no crear middleware nuevo. |
| 3 (concurrencia) | El patrón de transacción ya existe en job/accept/dismiss → solo agregar `lockForUpdate()`. |
| 4 (FULLTEXT) | La firma de `RelationSuggester::suggest` y el scoring no cambian; solo el origen de candidatos. |
| 5 (métricas) | Convención de comandos Artisan (`app/Console/Commands/`) y del scheduler (`routes/console.php`). |
| 6 (API key) | Patrón `Http::fake()` de los tests existentes para el resolver; convención de columnas string para enums. |

**División en sub-tareas verificables (preferir cambios chicos):**

- **1.1 en 3 pasos**: (a) migración tabla + `AnswerChangedNotification` con canal DB (comportamiento actual preservado) → (b) canal mail + template + SMTP → (c) toggle `email_notifications` + settings. Cada paso testeable y committeable.
- **1.2 en 2 pasos**: reset (1.2.1–1.2.2) / settings (1.2.3–1.2.4).
- **6 en 2 pasos**: resolver (6.1) + UI/persistencia (6.2–6.6) — permite avanzar sin el endpoint de Kuaforia.

**Acotar el contexto para la IA generadora:**

- Trabajar **por bloque** pasando solo los archivos del bloque; este plan por fase es autocontenido (no incluir el maestro ni `docs/kuestion-referencia-plataforma.md` en los prompts).
- En 1.1, no tocar vistas no relacionadas (welcome, tags, etc.).
- Evitar refactors cosméticos fuera de alcance (no renombrar `QuestionController`, no reorganizar directorios).

---

## 5. Salida para revisión (formato de cierre de la fase)

Al cerrar la fase, entregar un **documento nuevo `.md`** (p.ej. `kuestion-fase1-salida-revision.md`) con, **por bloque**:

1. **Resumen ejecutivo**: qué se hizo (tareas completadas con sus IDs), cómo se verificó.
2. **Evidencia por criterio de aceptación**: tabla `criterio → cómo se comprobó (test/commando/captura) → resultado`. No basta "listo"; mostrar la comprobación.
3. **Desviaciones**: qué quedó distinto de lo planeado y la razón (p.ej., el hook de nonces de Livewire, el proveedor SMTP elegido, fallback a `notifyNow()` si se eligió).
4. **Riesgos no previstos** en el plan maestro, detectados durante la implementación.
5. **Preguntas abiertas nuevas** surgidas en la implementación.

Plantilla por bloque:

```markdown
### Bloque X — <nombre>
**Resumen ejecutivo:** ...
**Evidencia por criterio de aceptación:**
| Criterio (maestro) | Cómo se verificó | Resultado |
|---|---|---|
| ... | ... | ✅ / ❌ / ⚠️ |
**Desviaciones:** ...
**Riesgos no previstos:** ...
**Preguntas abiertas nuevas:** ...
```

---

## 6. Preguntas abiertas — resoluciones de producto/tecnología/ingeniería (2026-08-14)

Las preguntas abiertas de la v1.0 fueron resueltas en revisión. Resumen e impacto en este plan:

| # | Pregunta (v1.0) | Resolución | Impacto en el plan |
|---|---|---|---|
| 1 | Proveedor SMTP concreto | **SendGrid** (soporte nativo de Laravel; si ya hay otro contratado, usar ese). Decisión operativa, no bloquea. | Sin cambios de código: `.env` con SMTP de SendGrid (1.1.6). |
| 2 | Re-validación de la key `kfr_` desde `/settings` | **Sí, en alcance del Bloque 6** (no diferir): las keys se pueden revocar en Kuaforia; sin vía de reemplazo el usuario quedaría trabado sin salida propia. Barato: reutiliza el resolver de 6.1. | Nueva tarea **6.6**. |
| 3 | Usuarios existentes (dropdown) | **Aceptar la lectura propuesta** (no requiere migración; `tenant_slug` ya persistido sigue funcionando). Extensión suave no bloqueante: prompt opcional "conectá tu key de Kuaforia" la próxima vez que entren. | Nueva tarea opcional **6.7**; deja a esos usuarios listos para el `workspace_id` real del Bloque 8 (Fase 2). |
| 4 | Política anti-spam de 1.8 | **Aceptar la propuesta** (notificar solo el primer error no leído por pregunta). Determinística y testeable; evita fatiga por fallas transitorias de Kuaforia. | Confirmado: 3.5 (1.8) sin cambios. |
| 5 | "API interna" de métricas (1.5) | **Diferir (YAGNI)**: no hay consumidor concreto; el Bloque 12 (Fase 3) lee `daily_metrics` directo desde el componente Livewire. | Bloque 5 sin API interna; solo vía Artisan (5.2–5.3). |

---

*Documento generado a partir de `Plan_Mejora_Kuestion_v2.4.md` (v2.4, cerrado). Próxima acción: ejecutar el bloque 5 (calentamiento) o iniciar 4/3/1/2 en paralelo, y coordinar el pendiente #1 con Kuaforia para el bloque 6.*
