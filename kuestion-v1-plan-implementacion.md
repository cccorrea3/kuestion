# Kuestion V1 — Plan de Implementación Integral

> **Versión:** 1.0 | **Fecha:** 2026-07-09
> **Stack real:** PHP 8.2 · MySQL 8.0 · Redis · Laravel 11 · Livewire v4 · Tailwind CSS v4 · Lucide icons
> **Docs base:** `kuestion-v1-especificacion-funcional-final.md` · `kuestion-v1-usabilidad-final.md`
> **Contribuciones:** @pm (producto) · @ponytail (código) · @cyber-neo (seguridad) · @ui-designer (UX/UI) · @headroom (estructura)

---

## Resumen Ejecutivo

Kuestion es un vigilante de respuestas RAG: guarda preguntas, versiona respuestas, detecta cambios en el tiempo, y sugiere conexiones. Stack adaptado a MySQL 8.0 (no PostgreSQL como dice la spec). MVP en 10 fases (~8 semanas). Single-user con semilla multi-user (`user_id` desde F1).

---

## Decisiones de Arquitectura

| Decisión | Opción elegida | Alternativa descartada | Razón |
|---|---|---|---|
| **Auth V1** | Single-user + API key `.env` | Sanctum + multi-user | YAGNI hasta validar PMF |
| **user_id** | Presente en schema desde F1 | Agregarlo después | Evitar migración dolorosa |
| **Similitud coseno** | Word frequencies en PHP puro | OpenAI embeddings | Cero $, cero latencia, sin API key |
| **Queue UI** | `php artisan queue:work` | Laravel Horizon | Overkill para 1 worker |
| **Abstracción Kuaforia** | Clase concreta, sin interfaz | `KuaforiaInterface` | Un solo proveedor en V1 |
| **Relaciones label** | Texto libre + chips sugeridos | Enum fijo | El usuario nombra sus relaciones |
| **Tags** | JSON column + minúsculas al guardar | Tabla separada | Suficiente para V1 |
| **Notificaciones** | In-app (database) | Email + Slack | Email se suma si sobra tiempo |

---

## Fases de Implementación

> Formato: `ID | Tarea | Archivos clave | Dependencias | Esfuerzo`

---

### F0 — Setup del Entorno

> Stack: Laravel 11 · MySQL 8.0 · Redis · Tailwind v4

| ID | Tarea | Archivos | Dep | Esf |
|----|-------|----------|-----|-----|
| F0.1 | `composer create-project laravel/laravel:^11.0 kuestion` | — | — | S |
| F0.2 | Configurar `.env`: DB, Redis, QUEUE_CONNECTION=redis | `.env` | F0.1 | S |
| F0.3 | `composer require livewire/livewire:^4.0 predis` | `composer.json` | F0.1 | S |
| F0.4 | `npm install -D tailwindcss @tailwindcss/vite` + build | `resources/css/app.css` | F0.1 | S |
| F0.5 | Configurar headers seguridad (HSTS, CSP, XFO, nosniff) | `app/Http/Middleware/SecurityHeaders.php` | F0.1 | S |
| F0.6 | Permisos: `storage/` 775, `.env` 600, resto 644 | — | F0.1 | S |
| F0.7 | Hardening MySQL: usuario con solo SELECT/INSERT/UPDATE/DELETE | SQL script | F0.1 | S |
| F0.8 | Configurar Tailwind v4 theme (colores, tipografía, fonts) | `resources/css/app.css` | F0.4 | S |
| F0.9 | Componentes base Blade: x-button, x-card, x-badge, x-input, x-tag | `resources/views/components/*.blade.php` | F0.4 | S |
| F0.10 | AppLayout: header + main container max-w-4xl | `resources/views/layouts/app.blade.php` | F0.9 | S |

**Seguridad:** APP_KEY generada y no versionada. Modo producción desde el día 1. Sesiones Secure + HttpOnly + SameSite=Lax. Rate limiter global (100 req/min).

---

### F1 — Modelos, Migraciones y API Core (Semana 1)

> Modelo de datos completo: `kuestion-v1-especificacion-funcional-final.md` §2

| ID | Tarea | Archivos | Dep | Esf |
|----|-------|----------|-----|-----|
| F1.1 | Migrations: questions, answer_versions, question_relations (MySQL: JSON, CHAR(36), VARCHAR para ENUMs) | `database/migrations/*` | F0.2 | S |
| F1.2 | Models: Question, AnswerVersion, QuestionRelation + HasUuids + casts | `app/Models/*.php` | F1.1 | S |
| F1.3 | QuestionFactory (seeding inicial) | `database/factories/QuestionFactory.php` | F1.2 | S |
| F1.4 | KuaforiaService + KuaforiaResponse + KuaforiaException | `app/Services/*.php`, `app/Exceptions/*.php` | F0.2 | S |
| F1.5 | Registrar KuaforiaService singleton en AppServiceProvider | `app/Providers/AppServiceProvider.php` | F1.4 | S |
| F1.6 | `POST /api/questions` — crear + consultar Kuaforia + guardar v1 | `app/Http/Controllers/QuestionController.php` | F1.2, F1.4 | M |
| F1.7 | `GET /api/questions` — lista paginada con filtros (status, tag, search, starred, has_changes) | `QuestionController.php` | F1.2 | M |
| F1.8 | `GET /api/questions/:id` — detalle + última versión | `QuestionController.php` | F1.2 | S |
| F1.9 | `PATCH /api/questions/:id` — actualizar tags, is_starred, status | `QuestionController.php` | F1.2 | S |
| F1.10 | `DELETE /api/questions/:id` — soft delete (status: archived) | `QuestionController.php` | F1.2 | S |
| F1.11 | Form Request: StoreQuestionRequest, UpdateQuestionRequest | `app/Http/Requests/*.php` | F1.2 | S |
| F1.12 | Rate limiting: POST /api/questions (10/min), global (100/min) | `AppServiceProvider.php` | F0.5 | S |
| F1.13 | Validar UUIDs en rutas (Route::pattern) | `routes/api.php` | F1.6 | S |

**Ponytail:** Sin repositorios, sin DTOs, sin servicios CRUD separados. Controladores llaman a Eloquent directo. `KuaforiaService` es concreto, sin interfaz.

**Seguridad:** Input sanitization en StoreQuestionRequest (text max 2000 chars, sin HTML). Tags: max 10, solo alfanumérico + guión. `answer_text` nunca se renderiza con `{!! !!}`.

---

### F2 — Frontend Básico: Feed + Crear + Detalle (Semana 2)

> Diseño UX: `kuestion-v1-usabilidad-final.md` §3.1, §3.2, §3.3

| ID | Tarea | Archivos | Dep | Esf |
|----|-------|----------|-----|-----|
| F2.1 | Livewire: QuestionFeed (lista paginada + filtros: Todas/Con cambios/Destacadas) | `app/Livewire/QuestionFeed.php`, `resources/views/livewire/questions-index.blade.php` | F1.7 | M |
| F2.2 | Livewire: QuestionCard (card con badge estado + metadatos) | `app/Livewire/QuestionCard.php`, `resources/views/components/question-card.blade.php` | F1.2 | M |
| F2.3 | Empty state: "Todavía no tienes preguntas vigiladas" | `questions-index.blade.php` | F2.1 | S |
| F2.4 | Livewire: CreateQuestion (textarea + tags + frecuencia revisión + sugerencias) | `app/Livewire/CreateQuestion.php`, `resources/views/livewire/questions-create.blade.php` | F1.6, F6.1 | M |
| F2.5 | Livewire: QuestionDetail (respuesta actual + feedback + relaciones + backlinks) | `app/Livewire/QuestionDetail.php`, `resources/views/livewire/questions-show.blade.php` | F1.8 | M |
| F2.6 | Livewire: FeedbackButtons (👍/👎 con micro-animación) | `app/Livewire/FeedbackButtons.php` | F2.5 | S |
| F2.7 | Loading states: skeleton feed, "Consultando Kuaforia..." en creación | Componentes | F2.1 | S |
| F2.8 | Error states: timeout Kuaforia, error red, error genérico | Componentes | F2.1 | S |
| F2.9 | Atajos teclado: N (nueva), J/K (navegar), Escape (cerrar) | `resources/js/app.js` | F2.1 | S |

**Ponytail:** Alpine.js incluido con Livewire (no instalar aparte). Debounce 300ms nativo con `wire:model.debounce.300ms`. Lucide desde CDN en V1.

**UX:** Estados de carga con skeleton loader (animate-pulse). Transiciones suaves en hover de cards (shadow-md). Touch targets mínimos 44x44px.

---

### F3 — Versionado + Línea de Tiempo (Semana 3)

> `kuestion-v1-especificacion-funcional-final.md` §2.2, §5

| ID | Tarea | Archivos | Dep | Esf |
|----|-------|----------|-----|-----|
| F3.1 | `GET /api/questions/:id/versions` — historial de versiones | `QuestionController.php` | F1.2 | S |
| F3.2 | `GET /api/questions/:id/diff?from=V&to=V` — comparación textual | `QuestionController.php`, `app/Services/DiffGenerator.php` | F1.2 | M |
| F3.3 | VersionHistory component (tabla: #, fecha, preview, confianza, fuentes, "Ver diff") | `resources/views/components/version-timeline.blade.php` | F3.1 | M |
| F3.4 | Integrated hash SHA-256 en AnswerVersion (response_hash) | `app/Models/AnswerVersion.php` | F1.2 | S |
| F3.5 | Race condition: transacción + lock pesimista en creación de versión | `CheckQuestionUpdatesJob.php` | F4.1 | S |

**Ponytail:** `$question->versions()->max('version_number')` en transacción con `freshLockForUpdate()`. Sin UUID secuencial — version_number es por pregunta.

**Seguridad:** `response_hash` validado como SHA-256 (64 chars hex). `UNIQUE(question_id, version_number)` en BD.

---

### F4 — ChangeDetector + Worker + Notificaciones (Semana 4)

> El corazón del producto. `kuestion-v1-especificacion-funcional-final.md` §5, §8, §9

| ID | Tarea | Archivos | Dep | Esf |
|----|-------|----------|-----|-----|
| F4.1 | ChangeDetector: hash SHA-256 + similitud coseno (word frequencies, PHP puro) | `app/Services/ChangeDetector.php` | F1.4 | M |
| F4.2 | CheckQuestionUpdatesJob: re-consulta + detect + crear versión + notificar | `app/Jobs/CheckQuestionUpdatesJob.php` | F4.1, F1.2 | M |
| F4.3 | nextConsultInterval(): según review_frequency (weekly/monthly/quarterly) | `CheckQuestionUpdatesJob.php` | F1.1 | S |
| F4.4 | AnswerChangedNotification (in-app, database channel) | `app/Notifications/AnswerChangedNotification.php` | F1.2 | S |
| F4.5 | Scheduler: `Schedule::job(...)->hourly()` | `routes/console.php` | F4.2 | S |
| F4.6 | CleanupOldVersionsJob: limpiar versiones viejas de archivadas | `app/Jobs/CleanupOldVersionsJob.php` | F1.2 | S |
| F4.7 | Circuit breaker: 3 fallos seguidos → 60s de pausa en llamadas a Kuaforia | `KuaforiaService.php` | F1.4 | S |

**Ponytail:** Similitud coseno con word frequencies — sin OpenAI, sin embeddings, sin API keys. Cero latencia de red. Upgrade path documentado.

```
// auto-check embebido
assert((new ChangeDetector)->cosineSimilarity('hola mundo', 'hola mundo') === 1.0);
```

**Seguridad:** Job filtra por `user_id`. Timeout 30s en llamada a Kuaforia. API key no expuesta en logs. Retry con backoff: [60, 300, 900] segundos. `$tries = 3`.

---

### F5 — Diff Visual + Ciclo Aceptar/Descartar (Semana 5)

> `kuestion-v1-usabilidad-final.md` §2.3, §3.4

| ID | Tarea | Archivos | Dep | Esf |
|----|-------|----------|-----|-----|
| F5.1 | Livewire: VersionDiff (comparación lado a lado + resumen) | `app/Livewire/VersionDiff.php`, `resources/views/livewire/questions-diff.blade.php` | F3.2 | M |
| F5.2 | DiffSummary: valor, confianza, fuentes nuevas/eliminadas | `resources/views/components/diff-summary.blade.php` | F5.1 | S |
| F5.3 | Botones Aceptar/Descartar con confirmación | `VersionDiff.php` | F5.1 | S |
| F5.4 | `PATCH /api/questions/:id/accept-change` + `dismiss-change` | `QuestionController.php` | F3.2 | S |
| F5.5 | Badge "Cambio sin revisar" en cards del feed | `QuestionCard.php` | F4.4 | S |
| F5.6 | Badge animado: `animate-pulse` primeras 24h | CSS | F5.5 | S |
| F5.7 | NotificationDropdown + contador no leídos (wire:poll.30s) | `app/Livewire/NotificationDropdown.php` | F4.4 | M |
| F5.8 | Transición suave a vista de diff (fade + slide) | CSS | F5.1 | S |

**UX:** Columnas lado a lado en desktop, apiladas en mobile. Colores: old=gray-50, new=teal-50. Texto añadido en verde, eliminado en rojo tachado.

**Seguridad:** Verificar ownership antes de aceptar/descartar cambio. Rate limiting: 10 operaciones/minuto.

---

### F6 — RelationSuggester + Sugerencias en Vivo (Semana 6)

> `kuestion-v1-especificacion-funcional-final.md` §6

| ID | Tarea | Archivos | Dep | Esf |
|----|-------|----------|-----|-----|
| F6.1 | RelationSuggester: matching por tags (x3 peso) + palabras clave | `app/Services/RelationSuggester.php` | F1.2 | M |
| F6.2 | `GET /api/questions/suggest-relations?text=&tags=` — endpoint | `QuestionController.php` | F6.1 | S |
| F6.3 | Sugerencias en vivo en CreateQuestion (debounce 300ms) | `CreateQuestion.php`, `questions-create.blade.php` | F6.2, F2.4 | M |
| F6.4 | Toggle "Conectar ↔" / "Conectada ✓" con Alpine.js | `questions-create.blade.php` | F6.3 | S |
| F6.5 | Array `confirmedRelations` enviado en POST /api/questions | `StoreQuestionRequest.php`, `QuestionController.php` | F6.4, F1.6 | S |
| F6.6 | Rate limiting: 60 req/min en suggest-relations | `AppServiceProvider.php` | F6.2 | S |
| F6.7 | Spinner + "Buscando relaciones..." durante carga de sugerencias | `questions-create.blade.php`, CSS | F6.3 | S |

**Ponytail:** `->get()` carga todas las preguntas activas en memoria. Para V1 (< 1000) es ~1MB. Upgrade: FULLTEXT cuando escale. Sin embeddings, sin IA.

**Seguridad:** Filtrar por `user_id` en las sugerencias. Stopwords hardcodeadas (no configurables por usuario).

---

### F7 — Relaciones Manuales + Backlinks + Tags + Búsqueda (Semana 7)

> `kuestion-v1-especificacion-funcional-final.md` §6.3, §7, §10

| ID | Tarea | Archivos | Dep | Esf |
|----|-------|----------|-----|-----|
| F7.1 | `POST /api/questions/:id/relations` — crear relación manual | `QuestionController.php` | F1.2 | S |
| F7.2 | `DELETE /api/questions/:id/relations/:rid` — eliminar relación | `QuestionController.php` | F1.2 | S |
| F7.3 | `GET /api/questions/:id/backlinks` — backlinks con preview | `QuestionController.php` | F1.2 | S |
| F7.4 | RelationsPanel Livewire (lista + añadir con búsqueda + chips) | `app/Livewire/RelationsPanel.php` | F7.1 | M |
| F7.5 | BacklinksPanel Livewire (lista entrante con preview hover) | `app/Livewire/BacklinksPanel.php` | F7.3 | S |
| F7.6 | Chips frases comunes: "depende de", "contradice", "ejemplo de", "relacionado con" | `relations-panel.blade.php` | F7.4 | S |
| F7.7 | `GET /api/tags` — lista de tags con conteos | `QuestionController.php` | F1.2 | S |
| F7.8 | Livewire: TagIndex (grid de tags + conteo + filtro) | `app/Livewire/TagIndex.php` | F7.7 | S |
| F7.9 | Búsqueda full-text con `where('text', 'LIKE', ...)` | `QuestionController.php` | F1.2 | S |
| F7.10 | Normalización de tags: minúsculas + trim al guardar | `Question.php` (mutator) | F1.2 | S |

**Seguridad:** `CHECK(source != target)` en app. Validar ownership de ambas preguntas en relaciones. Búsqueda full-text usa Eloquent (parametrizada), no concatenación.

---

### F8 — Feedback + Archivar + Destacar + Tests (Semana 8)

| ID | Tarea | Archivos | Dep | Esf |
|----|-------|----------|-----|-----|
| F8.1 | `POST /api/questions/:id/feedback` — helpful/not_helpful | `QuestionController.php` | F1.2 | S |
| F8.2 | FeedbackButtons: estado persistente + micro-animación | `FeedbackButtons.php` | F2.6, F8.1 | S |
| F8.3 | Archivar: swipe left en mobile (Alpine.js) + botón | `QuestionCard.php` | F2.2 | S |
| F8.4 | Destacar (is_starred): toggle + filtro "Destacadas" | `QuestionCard.php`, `QuestionFeed.php` | F2.1 | S |
| F8.5 | Responsive: breakpoints, header mobile, diff apilado, filtros scroll-x | CSS, componentes | F2.x | M |
| F8.6 | Accesibilidad: ARIA labels, skip link, keyboard nav, contraste | Layout, componentes | F2.x | M |
| F8.7 | Unit test: ChangeDetector (hash, similitud, unchanged/minor/new) | `tests/Unit/ChangeDetectorTest.php` | F4.1 | S |
| F8.8 | Unit test: RelationSuggester (matching por tag y keyword) | `tests/Unit/RelationSuggesterTest.php` | F6.1 | S |
| F8.9 | Feature test: POST /api/questions (creación + consulta Kuaforia) | `tests/Feature/QuestionApiTest.php` | F1.6 | M |
| F8.10 | Feature test: suggest-relations endpoint | `tests/Feature/SuggestRelationsTest.php` | F6.2 | S |
| F8.11 | Feature test: diff + accept/dismiss flow | `tests/Feature/DiffTest.php` | F5.4 | M |
| F8.12 | `composer audit` + `npm audit` — sin vulnerabilidades críticas | — | F0.1 | S |

**Ponytail:** 4-6 tests, ~80 líneas total. Sin Pest plugins, sin Dusk, sin fixtures externas. `Http::fake()` para Kuaforia. Una `QuestionFactory` basta.

---

### F9 — Seguridad y Hardening (Paralelo a F1-F8)

> Aplicar progresivamente. Checklist pre-lanzamiento al final.

| ID | Tarea | Severidad | Cuándo |
|----|-------|-----------|--------|
| F9.1 | APP_DEBUG=false + APP_ENV=production en producción | 🔴 Blocker | F0 |
| F9.2 | `composer audit` sin CVEs críticas | 🔴 Blocker | F0 + semanal |
| F9.3 | API keys solo en `.env`, nunca en logs | 🔴 Blocker | F1 |
| F9.4 | `answer_text` nunca con `{!! !!}` en Blade | 🔴 Blocker | F2 |
| F9.5 | Todas las queries filtran por user_id | 🔴 Blocker | F1 |
| F9.6 | No exponer stack traces en respuestas error | 🔴 Alta | F1 |
| F9.7 | Rate limiting: 10/min POST questions, 60/min suggest | 🟡 Media | F1 + F6 |
| F9.8 | Circuit breaker Kuaforia (3 fallos → 60s pausa) | 🟡 Media | F4 |
| F9.9 | Sesiones: Secure + HttpOnly + SameSite=Lax | 🟡 Media | F0 |
| F9.10 | Headers: HSTS, CSP, XFO, nosniff, Referrer-Policy | 🟡 Media | F0 |
| F9.11 | CORS: origen exacto del frontend, credentials true | 🟡 Media | F1 |
| F9.12 | UUIDs validados en todas las rutas con parámetro | 🟡 Media | F1 |
| F9.13 | CSP con `'unsafe-inline'` para Livewire (mejorar a nonces en V2) | 🟡 Media | F0 |
| F9.14 | Login rate limiting (5 intentos/minuto) | 🟡 Media | F1 |
| F9.15 | Backup automático BD (diario) | 🟡 Media | F0 |
| F9.16 | Logging de cambios de estado y errores | 🟢 Baja | F1 |
| F9.17 | Auditoría básica (cambios de estado, creación relaciones) | 🟢 Baja | V2 |
| F9.18 | `prefers-reduced-motion` para animaciones | 🟢 Baja | F5 |

---

### F10 — Lanzamiento + Monitoreo con Early Adopters

| ID | Tarea | Dep | Esf |
|----|-------|-----|-----|
| F10.1 | Reclutar 3-5 early adopters | — | M |
| F10.2 | Configurar monitoreo: failed_jobs, logs, rate limiting alerts | F9 | S |
| F10.3 | Health check endpoint `GET /api/health` (BD + Redis + Kuaforia) | F0.2 | S |
| F10.4 | Onboarding early adopters: breve guía + canal de feedback | F10.1 | S |
| F10.5 | Monitorear métricas de validación (ver §Métricas) | F10.2 | S |
| F10.6 | Iterar según feedback: bugs primero, features después | F10.5 | — |

---

## Métricas de Validación

| Métrica | Fórmula | Target |
|---------|---------|--------|
| Preguntas/semana por early adopter | `COUNT(questions) / semana / usuario` | > 5 |
| % preguntas con cambio detectado (30d) | `cambiadas / activas * 100` | > 10% |
| % cambios revisados | `revisadas / total_cambios * 100` | > 50% |
| % preguntas con ≥1 sugerencia aceptada | `con_sugerencia / total_nuevas * 100` | > 25% |
| Relaciones/pregunta (2 semanas) | `total_relaciones / total_preguntas` | > 0.5 |
| % preguntas con feedback | `con_feedback / total * 100` | > 30% |

---

## Grafo de Dependencias

```
F0.x (setup) ──────────────────────────┐
                                       ├──→ F2.x (frontend básico)
F1.x (models + API core) ─────────────┤
  ├── F3.x (versionado) ───→ F5.x (diff visual)
  ├── F4.x (worker + notif) ──→ F5.x (badge + notif)
  └── F6.x (sugerencias) ──→ F7.x (relaciones + backlinks)
                                        │
F2.x ───→ F5.x ───→ F8.x (tests + pulido)
                              │
F9.x (seguridad) ───→ todo lo anterior (paralelo)
                              │
F8.x ───→ F10.x (lanzamiento)
```

---

## Riesgos Identificados

| Riesgo | Impacto | Probabilidad | Mitigación |
|--------|---------|-------------|------------|
| Contrato API Kuaforia difiere de spec | Alto | Alta | Obtener ejemplo real antes de F1.4 |
| Embeddings no disponibles/caros | Medio | Media | ChangeDetector usa word frequencies (no embeddings) |
| MySQL sin soporte JSONB | Bajo | 100% | Usar JSON nativo MySQL 8.0 — mismo comportamiento |
| Worker con race conditions | Medio | Baja | Transacciones + lock pesimista |
| Early adopters no usan el producto | Alto | Media | Reclutar usuarios con dolor real, no voluntarios |
| Similitud coseno word-freq imprecisa | Bajo | Media | Hash SHA-256 ya captura cambios exactos; upgrade a embeddings si es necesario |

---

## Glosario

| Término | Definición |
|---------|-----------|
| **Pregunta** | Unidad atómica. Texto consultado a Kuaforia con frecuencia de revisión |
| **Versión** | Snapshot de respuesta: texto, fuentes, confianza, hash SHA-256 |
| **Versión actual** | La última aceptada (`is_current = true`) |
| **ChangeDetector** | Clase que decide si cambió: hash → similitud → unchanged/minor/new_version |
| **Sugerencia de conexión** | Relación propuesta por tag/keyword compartida, requiere confirmación |
| **Relación** | Vínculo entre 2 preguntas con etiqueta de texto libre (origen: tag_suggested o manual) |
| **Backlink** | Relación entrante: otra pregunta apunta a esta |
| **review_frequency** | weekly/monthly/quarterly — intervalo entre re-consultas |

---

*Documento generado el 2026-07-09. Próxima acción: ejecutar F0.1 y comenzar implementación.*
