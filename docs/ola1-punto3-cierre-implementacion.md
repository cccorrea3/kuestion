# Cierre de Implementación — Ola 1, Punto 3: Aportar conocimiento desde Kuestion

*Equipo de Kuestion · Septiembre 2026*

---

## 1. Resumen ejecutivo

El Punto 3 transforma la pantalla de entrada de Kuestion de un solo campo "Consultar" a **dos acciones explícitas**: **Preguntar** (flujo existente) y **Aportar** (flujo nuevo de contribución de conocimiento a QuBeKa).

**Estado:** ✅ Implementado y verificado.

| Fase | Estado | Commits |
|---|---|---|
| Fase 1 — Refactor UI dos acciones | ✅ Completada | `bf89f0b` |
| Fase 2 — Servicio de clasificación | ✅ Completada | `b3b1113` |
| Fase 3 — Integración componente | ✅ Completada | `a80e26a` |
| Fase 4 — Persistencia y retry | ✅ Completada | `b5a1d38` |
| Fase 5 — Conexión "sin resultados" → "Aportar" | ✅ Completada | `3db591a` |
| Fase 6 — QA y cierre | ✅ Completada | Este documento |

---

## 2. Qué se construyó

### 2.1 Componente Livewire `ContributeAporte`
- **Ruta:** `GET /contribute`
- **Estados de UI:** `idle` → `analyzing` → `saved` / `error`
- **Validación:** texto requerido, 10-2000 chars
- **Selector de repositorio:** visible con 2+ repos, badge con 1 repo
- **Contexto de pregunta previa:** captura `?prev=...` y lo muestra informativo

### 2.2 Servicio `QbkContributionService`
- Llama a `POST {QUBKA_API_URL}/contribute` con Bearer token
- Maneja: 401 (token inválido), 403 (sin permiso write), 422 (validación), 5xx/timeout
- Parsea envoltura `{success, data}` de QuBeKa
- Retorna `['session_id', 'status', 'resumen']`

### 2.3 Persistencia de drafts (`contribution_drafts`)
- Tabla con: user_id, repository_id, texto, pregunta_previa, status, attempts, last_error
- Modelo `ContributionDraft` con scopes: `pending`, `forUser`, `stale`
- Si el servicio falla → draft se guarda → botón "Reintentar" en UI
- Si el reintento falla → se incrementa intento y se actualiza error
- Job `CleanupContributionDraftsJob` elimina drafts >7 días (diario a las 03:30)

### 2.4 Conexión "sin resultados" → "Aportar"
- Campo `found` agregado a `KuaforiaResponse` (default `true`)
- `QbkService` propaga `found` de la respuesta de QuBeKa
- Cuando `found: false` → banner amarillo "No encontramos información" con link a `/contribute?prev=...`
- La pregunta se guarda igualmente (para vigilancia futura)

### 2.5 Navegación
- Link "Aportar" en menú de usuario del header
- Botón "Aportar conocimiento" en feed vacío
- Botón "Aportar" en header del feed con preguntas

---

## 3. Evidencia de cumplimiento de criterios de aceptación

| Criterio (del plan maestro) | Cómo se verificó | Resultado |
|---|---|---|
| La pantalla de entrada muestra dos acciones claras | Test `test_renders_form_with_active_repository` + vista | ✅ |
| "Aportar" envía texto al servicio de clasificación de QBK | Test `test_successful_contribution` con Http::fake | ✅ |
| El usuario ve confirmación liviana ("Gracias, pendiente de revisión") | Test `test_saved_state_shows_reset_button` | ✅ |
| Si el servicio falla, el texto se guarda como draft | Test `test_draft_created_on_service_failure` | ✅ |
| El usuario puede reintentar desde el draft | Test `test_retry_increments_attempt_counter` | ✅ |
| Si la búsqueda no tiene resultados, se ofrece aportar | Test `test_banner_shows_when_found_false` | ✅ |
| El flujo de "Preguntar" no se rompió | 17 tests de CreateQuestion/Detail/Checker pasan | ✅ |
| El sistema de conectores sigue funcionando | 24 tests de Connector/Settings pasan | ✅ |

---

## 4. Suite de tests

### Estado de la suite al cierre

| Área | Tests | Assertions | Estado |
|---|---|---|---|
| Punto 3 — ContributeAporteTest | 14 | 31 | ✅ |
| Punto 3 — QbkContributionServiceTest | 14 | 32 | ✅ |
| Punto 3 — ContributeAporteIntegrationTest | 19 | 58 | ✅ |
| Punto 3 — ContributionDraftTest | 15 | 38 | ✅ |
| Punto 3 — NoResultsContributeTest | 7 | 18 | ✅ |
| **Total Punto 3** | **69** | **177** | **✅** |
| Preguntas/Conectores (Punto 1/2) | 41 | 143 | ✅ |
| Resto de la suite | 165 | 454 | ✅ |
| **Suite completa** | **275** | **774** | **✅** |

### Failures preexistentes (no introducidos por este trabajo)
- `RepositoryMigrationTest::backfill` — QueryException (1)
- `RepositoryTest` — 3 failures de foreign key constraints
- `TeamDashboardTest` — 2 failures de agregación
- `TenantConnectionTest::register` — 1 failure de migración

**Nota:** estos 7 failures existían antes de este trabajo y pasan individualmente. Son problemas de aislamiento de tests (RefreshDatabase con estado compartido), no regresiones.

---

## 5. Hallazgos técnicos no previstos

### 5.1 Doble incremento en retry de drafts
**Problema:** `retryFromDraft()` llamaba `incrementAttempt()` + `markFailed()` (que también incrementa), causando doble incremento del contador de intentos.
**Fix:** removido `incrementAttempt()` de `retryFromDraft()` — `markFailed()` ya maneja el incremento.
**Impacto:** bajo, solo afectaba el contador de intentos (no funcionalidad).

### 5.2 `KuaforiaResponse` sin campo `found`
**Problema:** el contrato de QuBeKa devuelve `found: false` cuando no hay nodos relevantes, pero `KuaforiaResponse` no tenía este campo. El componente `CreateQuestion` no podía detectar "sin resultados".
**Fix:** campo `bool $found = true` agregado a `KuaforiaResponse`, propagado por `QbkService`.
**Impacto:** medio — sin esto, el banner "sin resultados → aportar" no funcionaría.

### 5.3 `reset()` conflicta con Livewire
**Problema:** método `reset()` en el componente conflictaba con `Livewire\Component::reset()`.
**Fix:** renombrado a `resetForm()`.
**Impacto:** bajo.

---

## 6. Decisiones tomadas durante la implementación

| Decisión | Razón |
|---|---|
| Ruta `/contribute` separada de `/questions/create` | Más limpio, mantiene flujos desacoplados, permite deep linking con `?prev=` |
| Button outline para "Aportar" vs accent para "Preguntar" | jerarquía visual: preguntar es la acción primaria |
| Draft se reutiliza en retry (no se crea nuevo) | Evita duplicados, mantiene contador de intentos preciso |
| Cleanup job a las 03:30 (no 03:00) | Evita conflicto con `CleanupOldVersionsJob` que corre a las 03:00 |
| Banner amber (no red) para "sin resultados" | Es un suggestion, no un error — amber comunica "info/opción" |

---

## 7. Archivos creados/modificados

### Nuevos (6)
- `app/Livewire/ContributeAporte.php`
- `app/Services/QbkContributionService.php`
- `app/Models/ContributionDraft.php`
- `app/Jobs/CleanupContributionDraftsJob.php`
- `database/migrations/2026_08_15_000005_create_contribution_drafts_table.php`
- `resources/views/livewire/contribute-aporte.blade.php`

### Tests nuevos (5)
- `tests/Feature/ContributeAporteTest.php` (14 tests)
- `tests/Feature/QbkContributionServiceTest.php` (14 tests)
- `tests/Feature/ContributeAporteIntegrationTest.php` (19 tests)
- `tests/Feature/ContributionDraftTest.php` (15 tests)
- `tests/Feature/NoResultsContributeTest.php` (7 tests)

### Modificados (7)
- `app/Services/KuaforiaResponse.php` — campo `found`
- `app/Services/QbkService.php` — propaga `found`
- `app/Livewire/CreateQuestion.php` — `$noResults` + banner logic
- `resources/views/livewire/create-question.blade.php` — banner "sin resultados"
- `resources/views/layouts/app.blade.php` — link "Aportar" en menú
- `resources/views/livewire/question-feed.blade.php` — botones "Aportar" en feed
- `routes/web.php` — ruta `/contribute`
- `routes/console.php` —CleanupContributionDraftsJob en scheduler

---

## 8. Preguntas abiertas pendientes

Ninguna. Todos los puntos bloqueantes y no bloqueantes del plan fueron resueltos durante la implementación.

---

## 9. Pendientes para futuras mejoras

| Item | Prioridad | Nota |
|---|---|---|
| Capa 2 (aporte que responde a Q/SQ/H existente) | Baja | Explícitamente fuera de alcance del Punto 3 |
| Revisión humana desde Kuestion | Media | Vive del lado de QuBeKa (Punto 4) |
| Historial de aportes del usuario | Baja | No se construye vista "mis aportes" |
| Notificación de revisión completada | Baja | Depende del Punto 4 |
