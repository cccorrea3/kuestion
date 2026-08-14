# Kuestion — Documento de Referencia Integral

> **Versión:** 1.0 | **Fecha:** 2026-08-02
> **Fuente del documento:** código fuente en `/root/Ollama/Kuestion` (commit `ffc1308`), `kuestion-v1-plan-implementacion.md`, `docs/auth-multi-user-feature.md`, migraciones, rutas, servicios y componentes Livewire.
> **Estado declarado:** MVP implementado (fases F1–F9 + auth multi-usuario M1–M15), no en producción.

---

## 1. Resumen Ejecutivo

Kuestion es un **vigilante de respuestas RAG (Retrieval-Augmented Generation)**: el usuario registra una pregunta, el sistema la consulta al motor RAG **Kuaforia**, guarda la respuesta como una **versión**, y a partir de ahí la **re-consulta en segundo plano** con una frecuencia configurable para **detectar cambios** en la respuesta a lo largo del tiempo. Cuando una respuesta cambia, Kuestion lo notifica, muestra un **diff visual** y deja al usuario **aceptar o descartar** el cambio. Además, **sugiere conexiones entre preguntas** (por tags y palabras clave) para construir una red de conocimiento navegable.

- **A quién sirve:** equipos técnicos y analistas que consumen conocimiento desde una base RAG multi-tenant (Kuaforia) y necesitan saber cuándo "la verdad" que usa su sistema cambia — el caso de uso semilla es el área de seguridad de **Ispend**.
- **Propuesta de valor única:** convierte un RAG de respuesta-única (estático, hay que re-preguntar para ver cambios) en un **activo vigilado, versionado y auditable**. El usuario no re-consulta manualmente: Kuestion detecta el cambio, lo difea, y decide qué versión queda vigente.
- **Alcance actual vs. visión:** hoy es una herramienta **single-deploy, multiusuario** (registro/login, cada usuario con su tenant) orientada a un flujo individual de vigilancia. La visión a futuro (documentada en el plan) incluye notificaciones por email/Slack, búsqueda full-text escalable, auditoría, y multi-usuario colaborativo con aislamiento/compra de preguntas — todo ello deliberadamente **en backlog** (YAGNI).

---

## 2. Arquitectura

### 2.1 Estilo arquitectónico

**Monolito modular** en Laravel 11, con capas separadas por responsabilidad dentro de una sola aplicación desplegable.

**Justificación:** equipo mínimo, MVP de validación, un solo despliegue. La documentación interna (`ponytail:` comments) registra los puntos de escalado explícitos y cuándo romper el monolito no es necesario (ver §7.3).

```
┌──────────────────────────────────────────────────────────────┐
│                        K U E S T I O N                        │
│  (Laravel 11 · PHP 8.2 · MySQL 8.0 · Redis · Livewire v4)     │
│                                                                │
│  Presentación   Blade + Livewire v4 + Alpine.js + Tailwind v4  │
│  Lógica         Controllers · Livewire Components · Services   │
│  Datos          Eloquent ORM → MySQL 8.0 (Cache/Session/Queue  │
│                              → Redis vía Predis)               │
│  Integraciones  HTTP client → Kuaforia (REST, síncrono)        │
└───────────────────────────────┬────────────────────────────────┘
                                │ POST /api/consult/{tenant_slug}
                                │ Bearer token (API key compartida)
                                ▼
┌──────────────────────────────────────────────────────────────┐
│  K U A F O R I A  (motor RAG externo, multi-tenant)           │
│  Database-per-tenant · ConsultController resuelve por slug    │
│  Respuesta: { answer, confidence, sources, conversation_id }  │
└──────────────────────────────────────────────────────────────┘
```

### 2.2 Capas del sistema

| Capa | Tecnología | Contenido |
|---|---|---|
| **Presentación** | Blade + Livewire v4 + Alpine.js + Tailwind CSS v4 + Lucide (icons) | Layouts, componentes base (`x-button`, `x-card`, `x-input`, `x-badge`, `x-tag`, `empty-state`, `skeleton-card`, `question-card`, `version-timeline`), 8 componentes Livewire |
| **Lógica de negocio** | PHP 8.2, `App\Services` y `App\Livewire` | `KuaforiaService` (cliente HTTP + circuit breaker), `ChangeDetector` (hash + similitud coseno), `DiffGenerator` (diff textual), `RelationSuggester` (matching en memoria) |
| **Acceso a datos** | Eloquent ORM + migraciones | Modelos `User`, `Question`, `AnswerVersion`, `QuestionRelation`; tabla `notifications` accedida vía Query Builder |
| **Integraciones** | Laravel HTTP Client | Consulta síncrona a Kuaforia (`POST /api/consult/{tenant_slug}`) con timeout 120 s |
| **Infraestructura** | MySQL 8.0, Redis (Predis), scheduler de Laravel, cola de jobs | Cola Redis con worker único; scheduler: re-consulta horaria, cleanup diario 03:00, backup diario 02:00 |

### 2.3 Modelo de datos de alto nivel (ERD simplificado)

```
users ─────────────┬── 1:N ── questions ── 1:N ── answer_versions
 (id, uuid PK-2°,   │                     │
  tenant_slug,      │                     ├─ 1:N ── question_relations
  name, email,      │                     │        (source ──→ target)
  password)         │                     │
                    │                     └─ conversation_id (contexto
                    │                        multi-turno con Kuaforia)
                    └── 1:N ── notifications
                                (tabla propia, NO polimórfica)
```

| Entidad | PK / Identidad | Campos clave | Reglas |
|---|---|---|---|
| `users` | `id` auto-increment; `uuid` UNIQUE (usado como FK de negocio) | `name`, `email`, `password`, `tenant_slug` (indexado, nullable) | `uuid` se auto-genera en `creating` |
| `questions` | `id` UUID (HasUuids) | `user_id` (uuid), `question_text` (2000), `answer_text` (longText), `status` (active/archived), `is_starred`, `tags` (JSON), `review_frequency` (weekly/monthly/quarterly), `last_consulted_at`, `last_change_detected_at`, `has_unreviewed_changes`, `conversation_id` | Soft deletes; `tags` normalizados a minúsculas en `saving`; índices `(user_id,status)` y `(user_id,is_starred)` |
| `answer_versions` | `id` UUID | `question_id`, `version_number`, `answer_text`, `confidence` (decimal 5,2), `sources` (JSON), `response_hash` (SHA-256, 64), `is_current`, `status` (current/accepted/dismissed/minor_change/new_version), `feedback` (helpful/not_helpful) | `UNIQUE(question_id, version_number)`; `version_number` por pregunta, no global |
| `question_relations` | `id` UUID | `source_question_id`, `target_question_id`, `label` (texto libre, 100), `relation_type` (manual/tag_suggested) | `UNIQUE(source, target, label)`; FK en cascada; app evita `source == target` |
| `notifications` | `id` UUID | `user_id`, `type` (`answer_changed`), `data` (JSON con `question_id`, `question_text`, `version_number`, `change_type`, `similarity`), `read_at` | Tabla propia, escrita por el job dentro de la transacción |

### 2.4 Decisiones técnicas y trade-offs

| Decisión | Elegido | Descartado | Razón |
|---|---|---|---|
| **Auth V1** | Registro/login manual (Livewire) | Breeze/Jetstream/Sanctum | Evita ~40 archivos innecesarios; roles/equipos no existen aún |
| **Identidad del usuario** | `uuid` propio + `tenant_slug` | `APP_USER_ID` hardcodeado en `.env` | Migración completada (M1–M15): datos por usuario real |
| **Detección de cambio** | SHA-256 + similitud coseno con frecuencias de palabras en PHP puro | OpenAI embeddings | Cero costo, cero latencia, sin API key; hash ya captura cambios exactos |
| **Sugerencia de relaciones** | Matching en memoria (tags ×3 + keywords) | FULLTEXT / embeddings | Suficiente < 1000 preguntas; upgrade documentado |
| **Diff textual** | Diff posicional por líneas + `similar_text` | LCS/Myers | Suficiente para respuestas típicas; `similar_text` O(n²) acotado |
| **Cola** | `php artisan queue:work` | Laravel Horizon | Un solo worker en V1 |
| **Abstracción Kuaforia** | Clase concreta `KuaforiaService` (singleton) | Interfaz `KuaforiaInterface` | Un solo proveedor en V1 |
| **Relaciones label** | Texto libre + chips sugeridos | Enum fijo | El usuario nombra sus relaciones |
| **Tags** | Columna JSON + normalización | Tabla normalizada | Suficiente para V1 |
| **Notificaciones** | In-app (tabla `notifications`) | Email + Slack | Email/Slack en backlog |
| **Tenant** | `tenant_slug` por usuario; Kuaforia resuelve por slug | Aislamiento DB-per-tenant en Kuestion | El multi-tenancy real vive en Kuaforia; Kuestion es multi-usuario con datos aislados por `user_id` |

### 2.5 Requisitos no funcionales

- **Seguridad (implementada):**
  - `SecurityHeaders` middleware: HSTS, CSP, X-Frame-Options, nosniff, Referrer-Policy. CSP con `'unsafe-inline'` requerido por Livewire (mejorar a nonces en V2).
  - Sesiones Redis cifradas, `Secure + HttpOnly + SameSite=Lax`.
  - API con API key (`X-App-Key` header, middleware `AuthenticateApiKey`) + rate limiting.
  - **Todas** las queries de datos están scoped por `current_user_id()` (helper global) — aislamiento por usuario incluso en jobs (que resuelven `user_id`/`tenant_slug` desde el modelo, no desde sesión).
  - Rate limiting: API global 100 req/min por IP; POST `/api/questions` 10/min; `suggest-relations` 60/min; `diff` 30/min; `feedback`/`relations` 30/min; login 5/min; follow-up 5/min por pregunta.
  - `answer_text` jamás se renderiza con `{!! !!}`; Markdown convertido con `html_input=escape` y `allow_unsafe_links=false` (league/commonmark).
  - Validación en Form Requests y sanitización de tags (regex `[a-z0-9áéíóúüñ-]`, max 10 tags, max 50 chars).
  - Backups `mysqldump` diarios con `--defaults-extra-file` (credenciales fuera del listado de procesos).
  - `APP_DEBUG=false`, modo producción desde el día 1; API keys solo en `.env`.
- **Escalabilidad:** arquitectura orientada a cola (el trabajo pesado de re-consulta no bloquea la web); `chunk(100)` en jobs; versiones numeradas con `UNIQUE` constraint; upgrade paths documentados en comentarios `ponytail:` (ver §7.3).
- **Multi-tenancy:** parcial/soft. Kuestion es **multi-usuario** (aislamiento de datos por `user_id`); la **multi-tenancy real** la resuelve Kuaforia vía `tenant_slug` en la URL de consulta. Un despliegue de Kuestion sirve a usuarios de distintos tenants de Kuaforia.
- **Rendimiento:** respuesta de Kuaforia es síncrona (timeout 120 s) con estados de carga/error en UI; Redis como cache/sesión/cola; circuit breaker evita cascadas de llamadas a un Kuaforia caído.

---

## 3. Funcionalidades (Features)

### 3.1 Funcionalidades core (MVP — implementadas)

| # | Módulo / Funcionalidad | Usuario | Descripción | Valor |
|---|---|---|---|---|
| F1 | **Vigilancia de pregunta** | Analista | Crea una pregunta, el sistema la consulta a Kuaforia y guarda la respuesta como v1 | Obtiene la respuesta "vigilada" sin esfuerzo manual repetido |
| F2 | **Versionado de respuestas** | Analista | Cada respuesta se almacena como versión numerada con confianza, fuentes y hash SHA-256 | Trazabilidad completa de la evolución de una respuesta |
| F3 | **Detección de cambios** | Analista (automático) | Job horario re-consulta las preguntas vencidas (weekly/monthly/quarterly) y clasifica: `unchanged` / `minor` / `new_version` | Nadie necesita re-preguntar a mano; Kuestion avisa |
| F4 | **Revisión de cambios (aceptar/descartar)** | Analista | Al detectarse cambio, muestra diff lado a lado y permite aceptar (la nueva versión pasa a ser la actual) o descartar (se restaura la anterior) | Control humano sobre "la verdad vigente" |
| F5 | **Notificaciones in-app** | Analista | Badge de notificaciones no leídas (tipo `answer_changed`) con navegación directa a la pregunta | El usuario se entera sin mirar el feed |
| F6 | **Diff visual** | Analista | Comparación de dos versiones línea a línea (añadido/eliminado/cambiado/sin cambio) con resumen de similitud | Entendimiento rápido de qué cambió y cuánto |
| F7 | **Sugerencia de relaciones en vivo** | Analista | Al escribir la pregunta, sugiere conexiones con preguntas existentes (tags ×3 + keywords) con toggle "Conectar/Conectada" | Construcción de una red de conocimiento desde el primer momento |
| F8 | **Relaciones manuales y backlinks** | Analista | Panel para crear relaciones con etiqueta libre ("depende de", "contradice", ...) y listado de backlinks (relaciones entrantes) | Navegación semántica entre preguntas |
| F9 | **Feed con filtros** | Analista | Lista paginada con filtros: todas / con cambios sin revisar / destacadas, por tag y búsqueda de texto | Visión del estado de vigilancia de un vistazo |
| F10 | **Destacar y archivar** | Analista | `is_starred` (con filtro "Destacadas") y archivado (soft delete) | Organización personal del conocimiento |
| F11 | **Feedback de respuestas** | Analista | Thumbs 👍/👎 persistido sobre la versión actual | Señal de calidad para iterar el producto |
| F12 | **Follow-up multi-turno** | Analista | Desde el detalle, hace una pregunta de seguimiento usando `conversation_id` (contexto de la conversación en Kuaforia) | Conversación continua sin perder contexto |
| F13 | **Registro / Login / Logout** | Usuario nuevo | Registro con selector de tenant (dropdown), login con rate limiting, logout | Acceso por usuario con tenant asociado |
| F14 | **Onboarding post-registro** | Usuario nuevo | Pantalla "Cuenta creada" con CTA a la primera consulta | Reducción de fricción inicial |
| F15 | **Índice de tags** | Analista | Grid de tags con conteos | Descubrimiento de temas cubiertos |
| F16 | **Health check** | Operaciones | `GET /api/health` verifica BD, Redis y configuración de Kuaforia | Monitoreo de infraestructura |

### 3.2 Funcionalidades roadmap / backlog (no implementadas)

| Funcionalidad | Estado | Fuente |
|---|---|---|
| Notificaciones por **email y Slack** | Backlog (in-app solo) | plan F0/plan de implementación |
| **Password reset** ("olvidé mi contraseña") | Backlog (tabla `password_reset_tokens` ya existe) | docs/auth §Backlog B2 |
| **Settings** de usuario (nombre, email, contraseña) | Backlog | docs/auth B1 |
| **Recordarme** en login, **verificación de email** | Backlog | docs/auth B3, B4 |
| **Multi-usuario colaborativo** (aislamiento de datos, compartir preguntas) | Backlog — YAGNI hasta 2+ usuarios reales | docs/auth B5 |
| **Auditoría** avanzada de cambios de estado | Backlog (logging básico ya existe) | plan F9.17 |
| **Búsqueda full-text** escalable (FULLTEXT / embeddings) | Upgrade documentado, no implementado | services `ponytail:` comments |
| **Laravel Horizon** (múltiples workers, per-question scheduling) | Upgrade documentado, no implementado | jobs `ponytail:` comments |
| **Editor/actualización del texto de la pregunta** | No existe — solo tags/estado/frecuencia | — |

---

## 4. Integraciones

### 4.1 Kuaforia (motor RAG) — la única integración externa

| Aspecto | Detalle |
|---|---|
| **Tipo** | Saliente, **síncrona**, REST/JSON vía Laravel HTTP Client |
| **Endpoint** | `POST {KUAFORIA_BASE_URL}/api/consult/{tenant_slug}` |
| **Autenticación** | `Authorization: Bearer {KUAFORIA_API_KEY}` (API key compartida Kuestion→Kuaforia) |
| **Timeout / resiliencia** | 120 s; **circuit breaker** en cache Redis: 3 fallos seguidos → pausa de 60 s (todas las consultas fallan rápido con mensaje amigable) |
| **Payload** | `{ "question": string, "conversation_id": string\|null }` |
| **Respuesta esperada** | `{ "answer"\|"response": string, "confidence": number, "sources": array, "conversation_id": string\|null }` — consumida con tolerancia (`answer` o `response`) |
| **Multi-tenancy** | El slug del tenant se resuelve desde el usuario (`auth()->user()->tenant_slug`) o desde la pregunta en jobs (`$question->user->tenant_slug`); Kuaforia resuelve el tenant por slug en su capa (database-per-tenant) |

**Datos intercambiados:** entrada — pregunta y contexto de conversación; salida — texto de respuesta, confianza, fuentes (metadatos del RAG) y `conversation_id` para follow-ups.

### 4.2 Otras integraciones y utilidades

| Sistema | Tipo | Propósito |
|---|---|---|
| **API de Kuestion** (`/api/*`) | **Entrante** REST, auth por API key + rate limits | Expone CRUD, versiones, diff, relaciones, tags, feedback y health. Consumible por clientes externos (hoy el frontend usa Livewire directo, no esta API) |
| **MySQL** | Persistencia | Datos de negocio (ver ERD §2.3) |
| **Redis** | Cache, sesión, cola | Circuit breaker, rate limiting counters, sesiones cifradas, driver de cola |
| **Scheduler / Cola** | Interno | Jobs: re-consulta horaria, cleanup 03:00, backup 02:00 |
| **`kuaforia-mock.php`** | Desarrollo | Mock local de Kuaforia para desarrollo/testing (`Http::fake()` en tests) |
| **SendGrid/SMTP** | No configurado | MAIL_MAILER=`log` en `.env.example` — solo log, sin envío real |

**Datos intercambiados con Kuaforia:** pregunta + `conversation_id` (entrada); `answer/response`, `confidence`, `sources`, `conversation_id` (salida). No se envían datos personales ni credenciales al motor.

---

## 5. Flujos de Usuario y de Negocio

### 5.1 Onboarding: registro → primera consulta

```
Landing ──▶ Registro (nombre, email, password, tenant) ──▶ validar tenant existe
  └── login si ya tiene cuenta ──▶ ──▶ login automático ──▶ Onboarding
                                              "Cuenta creada" [Hacer mi primera consulta →]
                                                          ▼
                                                   /questions (feed vacío)
```

- **Actores:** usuario nuevo; sistema (validación, sesión).
- **Puntos de decisión:** el tenant debe existir en Kuaforia (rechazo con mensaje claro si no); si hay un solo tenant configurado, no se muestra el selector.
- **Resultado:** sesión iniciada, usuario asociado a su `tenant_slug`, redirigido al feed.
- **Reglas críticas:** login con rate limiting (5/min) y regeneración de sesión; contraseñas con hash bcrypt (12 rounds); el `uuid` del usuario se genera al crear.

### 5.2 Flujo central: crear pregunta vigilada

```
/question/create ──▶ escribir texto + tags + frecuencia (weekly/monthly/quarterly)
      │  (debounce 300ms → RelationSuggester sugiere conexiones en vivo)
      ▼  toggle "Conectar" de las que apliquen
  Consulta a Kuaforia (síncrona, 120s, con circuit breaker)
      │   ✓ respuesta → transacción: Question + AnswerVersion v1 (is_current=true)
      │   ✗ error/tiempo de espera → estado error con mensaje (sin guardar)
      ▼
  Guardada. El job horario empieza a vigilarla según review_frequency
```

- **Actores:** analista; KuaforiaService; BD.
- **Puntos de decisión:** tags opcionales (max 10, validados); frecuencia de revisión (default weekly); relaciones confirmadas opcionales.
- **Resultado:** pregunta persistida con su v1 y hash SHA-256; `last_consulted_at = now()`.
- **Reglas críticas:** validación `question_text` max 2000; tags normalizados (minúsculas/trim) y filtrados por regex; rate limit 10/min en POST; en error de Kuaforia **no se crea** la pregunta (transacción atómica); las relaciones confirmadas se validan contra preguntas del mismo usuario.

### 5.3 Flujo de vigilancia: detección de cambios (núcleo del producto)

```
Cada hora (scheduler) ──▶ CheckQuestionUpdatesJob
   chunk(100) de preguntas activas ──▶ ¿vencida? (weekly/monthly/quarterly vs last_consulted_at)
        ├─ No → skip
        └─ Sí → consulta Kuaforia (con tenant del dueño de la pregunta)
              ├─ error → log + continue (retry con backoff [60,300,900]s, tries=3)
              └─ respuesta → ChangeDetector.detect(old, new)
                    ├─ unchanged (hash igual) → solo actualiza last_consulted_at
                    └─ minor (sim ≥0.8) / new_version (sim <0.8) →
                         transacción:
                           · desmarca la versión anterior (is_current=false)
                           · crea nueva versión (is_current=true)
                           · marca has_unreviewed_changes=true
                           · inserta notificación answer_changed
```

- **Actores:** job de cola (sin sesión — resuelve el usuario/tenant desde el modelo); analista (beneficiario).
- **Puntos de decisión:** hash exacto → si difiere, umbral de similitud coseno 0.8 separa `minor` de `new_version`.
- **Resultado:** nueva versión vigente + notificación. **La versión nueva ya queda como `is_current=true`** hasta que el usuario la acepte o descarte.
- **Reglas críticas:** todo dentro de transacción (si falla, se revierte y el retry deja estado limpio); `UNIQUE(question_id, version_number)`; race condition mitigada por diseño de worker único (upgrade a `lockForUpdate` documentado); el job no usa `auth()` — el `tenant_slug` sale de `$question->user`.

### 5.4 Flujo de revisión: aceptar / descartar un cambio

```
Notificación / badge / feed "con cambios" ──▶ /questions/{id}
   diff precargado (última vs anterior) ──▶ ver diff lado a lado
        ├─ ACEPTAR → transacción: versión actual → status=accepted,
        │            has_unreviewed_changes=false, notificación marcada leída
        └─ DESCARTAR → transacción: versión actual → status=dismissed + is_current=false;
                        versión anterior → is_current=true; answer_text restaurado;
                        has_unreviewed_changes=false; notificación marcada leída
```

- **Actores:** analista (decisión); sistema (consistencia).
- **Puntos de decisión:** aceptar mantiene la nueva respuesta; descartar **revierte** `answer_text` a la versión anterior.
- **Resultado:** `has_unreviewed_changes=false`, notificaciones de esa pregunta leídas, diff cerrado.
- **Reglas críticas:** verificación de ownership (`where user_id = current_user_id()`); idempotente (si no hay cambios pendientes, no hace nada); transaccional.

### 5.5 Flujo secundario: follow-up multi-turno

```
/question/{id} ──▶ textarea "Hacer seguimiento" ──▶ consulta Kuaforia con
        conversation_id almacenado en la pregunta (contexto de la conversación)
        ──▶ respuesta mostrada en el detalle (no se versiona, no se guarda)
```

- **Actores:** analista.
- **Reglas:** rate limit 5/min por pregunta; el follow-up **no crea versión** ni modifica la pregunta (solo respuesta efímera en pantalla).

---

## 6. Interfaz de Usuario

### 6.1 Pantallas principales

| Ruta | Pantalla | Propósito |
|---|---|---|
| `/` | Landing (welcome) | Logueado → redirige a `/questions`; invitado → landing con CTAs |
| `/register` | Registro | Formulario nombre/email/password + selector de tenant |
| `/login` | Login | Autenticación con rate limiting |
| `/onboarding` | Onboarding | "Cuenta creada" + CTA a primera consulta |
| `/questions` | **Feed** | Lista de preguntas vigiladas con filtros (todas/cambios/destacadas), búsqueda, tags, starring, archivar, badge de cambios |
| `/questions/create` | **Crear pregunta** | Texto + tags + frecuencia + sugerencias de relación en vivo |
| `/questions/{id}` | **Detalle** | Respuesta actual (Markdown), feedback, diff/aceptar/descartar, timeline de versiones, panel de relaciones, backlinks, follow-up |
| `/tags` | Índice de tags | Grid de tags con conteo, click → filtro en feed |

### 6.2 Patrones de navegación

- **Navegación Livewire con `navigate`** (SPA-style, sin recarga completa) para transiciones rápidas.
- **Header persistente** en `layouts/app.blade.php`: logo, navegación (Feed, Tags), badge de notificaciones (con `wire:poll` para el contador), menú de usuario (avatar/inicial + logout). Estados guest vs autenticado.
- **Layout single-column** `max-w-4xl` centrado — feed y detalle como página principal; sin sidebar de módulos.
- **Wizards:** ninguno. Flujo de creación en una sola página con feedback en vivo (sugerencias debounced).
- **Estados:** skeleton loaders (`animate-pulse`) en feed y cards; "Consultando Kuaforia..." durante creación; estados de error (timeout/red/genérico) separados.
- **Atajos de teclado:** planificados (N nueva, J/K navegar, Escape cerrar).

### 6.3 Componentes reutilizables / distintivos

- Componentes Blade base: `x-button`, `x-card`, `x-badge`, `x-input`, `x-tag`, `empty-state`, `skeleton-card`, `question-card`, `version-timeline`.
- Livewire: `QuestionFeed`, `CreateQuestion`, `QuestionDetail`, `FeedbackButtons`, `NotificationBadge`, `RelationsPanel`, `BacklinksPanel`, `TagIndex`.
- **Distintivo:** `FeedbackButtons` con micro-animación y estado persistente; badge de "Cambio sin revisar" con `animate-pulse` (primeras 24 h); diff lado a lado con colores semánticos (viejo `gray-50`, nuevo `teal-50`; añadido verde, eliminado rojo tachado).

### 6.4 Consideraciones de UX

- **Responsive:** breakpoints Tailwind; diff apilado en mobile (columnas en desktop); filtros con scroll horizontal; touch targets ≥ 44×44 px; archivar con swipe en mobile (Alpine).
- **Accesibilidad:** ARIA labels, skip link, navegación por teclado, contraste; `prefers-reduced-motion` para animaciones.
- **Lectura:** respuestas renderizadas en Markdown con `league/commonmark` (HTML escapado).
- **Idioma:** interfaz en español (`APP_LOCALE=es`, faker `es_CL`); stopwords de detección en español.

---

## 7. Capacidades y Límites del Sistema

### 7.1 Qué puede hacer la plataforma hoy (capacidades confirmadas)

- Crear preguntas vigiladas y consultar Kuaforia en tiempo real (multi-tenant por `tenant_slug`).
- Versionar cada respuesta (v1, v2, ...) con confianza, fuentes y hash SHA-256.
- Re-consultar automáticamente por frecuencia (semanal/mensual/trimestral) vía job horario y clasificar cambios (`unchanged`/`minor`/`new_version`).
- Notificar in-app y permitir aceptar/descartar cambios con restauración de versión.
- Comparar versiones con diff línea a línea + resumen de similitud.
- Sugerir relaciones en vivo y permitir relaciones manuales etiquetadas + backlinks.
- Filtrar/buscar el feed (estado, tag, texto, destacadas, cambios pendientes).
- Feedback por versión, follow-up multi-turno, destacar, archivar, índice de tags.
- Registro/login/logout multi-usuario con tenant asociado y onboarding.
- API REST autenticada por API key con rate limits y health check (BD + Redis + Kuaforia).
- Backups diarios de BD y limpieza de versiones antiguas de preguntas archivadas.

### 7.2 Qué NO hace (límites explícitos)

- **No edita el texto de una pregunta** — solo tags, estado, `is_starred` y `review_frequency`.
- **No envía email ni Slack** — notificaciones solo in-app; mailer configurado a `log`.
- **No tiene password reset, verificación de email, "recordarme" ni settings de usuario** (backlog).
- **No tiene roles/permisos** (no Spatie, no `authorize` granular) — solo autenticación y aislamiento por `user_id`.
- **No es multi-tenant por sí mismo** — el aislamiento de tenants lo resuelve Kuaforia; Kuestion es multi-usuario.
- **No permite compartir preguntas ni colaboración entre usuarios** (YAGNI).
- **No hay búsqueda semántica ni embeddings** — similitud por frecuencia de palabras; búsqueda por `LIKE` (hasta ~1000 preguntas).
- **No hay auditoría completa** — solo logging básico de operaciones.
- **No hay SSO/OAuth**.
- **No expone la API de Kuaforia al frontend** — el frontend consume servicios de Kuestion; el backend habla con Kuaforia.
- **Sin multi-worker ni Horizon** — un solo worker; la numeración de versiones asume worker único.

### 7.3 Extensibilidad

La arquitectura tiene **puntos de escalado explícitos** marcados en el código (comentarios `ponytail:`):

| Capacidad futura | Cambio requerido | Dificultad |
|---|---|---|
| Embeddings / búsqueda semántica | Reemplazar `ChangeDetector`/`RelationSuggester` por un proveedor de embeddings (la interfaz de entrada ya es estable) | Baja |
| FULLTEXT / > 1000 preguntas | Índice FULLTEXT en `question_text`; dejar de cargar candidatos en memoria | Baja |
| Multi-worker | `lockForUpdate` en numeración de versiones + Horizon | Media |
| Per-question scheduling | Reemplazar un job horario global por jobs individuales retardados | Media |
| Notificaciones email/Slack | Añadir canales a la notificación (el modelo `data` ya es JSON semántico) | Baja |
| Segundo proveedor de IA | Extraer interfaz de `KuaforiaService` (hoy clase concreta única) | Baja |
| Auditoría | Capa de eventos/logging sobre las mutaciones ya logueadas | Baja |

La separación **servicios puros y deterministas** (`ChangeDetector`, `DiffGenerator`, `RelationSuggester`) sin dependencias de framework facilita testear y reemplazar cada pieza de forma aislada.

---

## Supuestos y vacíos de información

> Listado de lo que se asumió al redactar este documento (dado que el material provisto es el código fuente) y de lo que falta definir.

1. **Contrato exacto de la API de Kuaforia.** Se asume la forma `POST /api/consult/{tenant_slug}` con respuesta `{answer|response, confidence, sources, conversation_id}` — confirmada por `KuaforiaService.php` y `kuaforia-mock.php`. **Falta validar** contra el Kuaforia real: campos de error, esquema exacto de `sources`, códigos de estado y estructura de `conversation_id`.
2. **Lista de tenants de Kuaforia.** La configuración `services.kuaforia.tenants` está parcialmente poblada (solo `ispend` visible en `config/services.php`). **Falta definir** si se lee de Kuaforia vía API o se mantiene hardcodeada en configuración.
3. **Estado de despliegue.** No hay evidencia de infraestructura de producción (proveedor cloud, dominio, SSL, workers en supervisor/systemd, monitoreo). Se asume despliegue único de Laravel; **falta definir** el pipeline CI/CD y el hosting.
4. **Datos de usuarios reales.** Los usuarios/tenants distintos de `ispend`/admin no están documentados; se asume que el seeder crea al admin actual.
5. **`conversation_id` y multi-turno.** Se asume que el follow-up mantiene contexto vía el `conversation_id` guardado en la pregunta; **falta definir** política de expiración/reutilización de conversaciones y si el follow-up debería versionarse.
6. **Métricas de validación.** El plan define targets (preguntas/semana > 5, % cambios revisados > 50%, etc.), pero **no hay instrumentación** de dashboard ni script para calcularlas; se recomienda definir dónde se reportan.
7. **Retención de versiones.** `CleanupOldVersionsJob` conserva las últimas 5 de preguntas archivadas, pero **no hay política documentada** para preguntas activas (se conservan todas). Se recomienda definir retención si crece el volumen.
8. **CSP con `unsafe-inline`.** Aceptado como deuda técnica para V1 (requisito de Livewire); **pendiente** migrar a nonces en V2.
9. **Alcance de "vigilante" para respuestas vacías.** Si Kuaforia devuelve `answer` vacío, se guarda igual (hash de cadena vacía); **falta definir** si eso debe ser un estado de error.
10. **No se especifica** un proceso de onboarding para early adopters, canal de feedback, ni el plazo del MVP restante (el plan lo fija en ~8 semanas desde 2026-07-09).

---

*Documento generado a partir del código fuente de Kuestion. Cualquier sección marcada como "falta definir" requiere confirmación del equipo de producto o del propietario de Kuaforia.*
