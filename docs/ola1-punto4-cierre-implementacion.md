# Cierre de Implementación — Ola 1, Punto 4: Gate humano de revisión desde Kuestion

*Equipo de Kuestion · Septiembre 2026*

---

## 1. Resumen ejecutivo

El Punto 4 está **completado**. Se construyó el flujo de revisión humana para aportes de conocimiento: cuando un usuario hace un aporte desde Kuestion y QuBeKa lo clasifica, el usuario puede aprobar/ajustar/descartar la clasificación **sin salir de Kuestion** (sesiones simples) o ser redirigido a QuBeKa (sesiones complejas).

### Estado por fase

| Fase | Estado | Commit | Tests |
|---|---|---|---|
| Fase 1 — Servicios QBK (getSession/approve/reject) | ✅ Completada | `0ba0a7c` | 37 tests |
| Fase 2 — Pantalla de revisión (ContributionReview) | ✅ Completada | `b6a1a35` | 13 tests |
| Fase 3 — Badge de pendientes (PendingReviewBadge) | ✅ Completada | `84520e7` | 9 tests |
| Fase 4 — QA, regresión y cierre | ✅ Completada | este commit | Suite completa |

### Suite de tests al cierre

| Suite | Tests | Assertions | Estado |
|---|---|---|---|
| QbkContributionServiceTest | 37 | 105 | ✅ |
| ContributionReviewTest | 13 | 33 | ✅ |
| PendingReviewBadgeTest | 9 | 13 | ✅ |
| ContributeAporteTest | 14 | 42 | ✅ |
| QbkContributionServiceTest (contribute) | 14 | 39 | ✅ |
| ContributionDraftTest | 15 | 42 | ✅ |
| NoResultsContributeTest | 7 | 21 | ✅ |
| CreateQuestion/Detail/Checker | 8 | 30 | ✅ |
| **Total Punto 4** | **117** | **325** | ✅ |
| Suite completa | 326 | 908 | ✅ (1 preexistente) |

---

## 2. Criterios de aceptación

| # | Criterio | Estado | Evidencia |
|---|---|---|---|
| CA1 | `getSession()` devuelve detalle de sesión con nodos, status, is_simple, pregunta_previa | ✅ | `QbkContributionServiceTest::get_session_returns_detail` |
| CA2 | `approve()` con/sin textos_ajustados llama al endpoint correcto | ✅ | `QbkContributionServiceTest::approve_*` (2 tests) |
| CA3 | `reject()` descarta sesión y devuelve success | ✅ | `QbkContributionServiceTest::reject_*` (7 tests) |
| CA4 | Error handling: 401/403/404/5xx/timeout para los 3 métodos | ✅ | 15 tests de error en QbkContributionServiceTest |
| CA5 | `/contributions/{sessionId}/review` muestra nodos en lenguaje natural | ✅ | `ContributionReviewTest::render_component_with_valid_session` |
| CA6 | Aprobar actualiza draft a `reviewed` | ✅ | `ContributionReviewTest::approve_updates_draft_status` |
| CA7 | Descartar actualiza draft a `reviewed` | ✅ | `ContributionReviewTest::reject_updates_draft_status` |
| CA8 | Editar texto y aprobar envía textos_ajustados | ✅ | `ContributionReviewTest::approve_with_edited_texts` |
| CA9 | Sesión compleja (is_simple=false) redirige a QuBeKa | ✅ | `ContributionReviewTest::complex_session_sets_flag_and_redirects` |
| CA10 | Badge muestra conteo correcto de pendientes | ✅ | `PendingReviewBadgeTest::badge_shows_correct_count` |
| CA11 | Badge excluye reviewed, failed, pending_retry, sin session_id | ✅ | 4 tests de exclusión |
| CA12 | Badge refresca después de approve/reject | ✅ | `PendingReviewBadgeTest::review_approved_clears_from_badge` |
| CA13 | Flujo "Preguntar" sin cambios (regresión) | ✅ | 8 tests de CreateQuestion/Detail pasan |
| CA14 | Flujo "Aportar" sin cambios (regresión) | ✅ | 55 tests de ContributeAporte/Draft pasan |

---

## 3. Hallazgos técnicos

### 3.1 auth()->id() vs auth()->user()->uuid
**Fase 3:** El `PendingReviewBadge` usaba `auth()->id()` (entero) para consultar `contribution_drafts.user_id` (que referencia `users.uuid`). Las queries devolvían 0 resultados silenciosamente. Fix: usar `auth()->user()->uuid`.

### 3.2 Draft en éxito ahora es intencional
**Fase 2→4:** El `ContributeAporte::submit()` ahora crea un draft con `qbk_session_id` después de un aporte exitoso (para habilitar el badge de pendientes). El test existente `test_draft_not_created_on_success` se actualizó a `test_draft_created_on_success_with_session_id` para reflejar el nuevo diseño.

### 3.3 Livewire root tag requirement
**Fase 3:** La vista del badge usaba `@if/@endif` como contenido principal sin un `<div>` raíz. Livewire lanza `RootTagMissingFromViewException`. Fix: envolver todo en `<div>`.

### 3.4 Redirect external en Livewire testing
**Fase 2:** `redirectExternal()` de Livewire no produce un HTTP redirect assertions estándar en tests. Se validó verificando `assertSet('isSimple', false)` en vez de `assertRedirect()`.

---

## 4. Archivos creados/modificados

### Archivos nuevos (4)
| Archivo | Propósito |
|---|---|
| `app/Livewire/ContributionReview.php` | Componente de revisión: carga sesión, aprueba/rechaza/edita, redirige sesiones complejas |
| `app/Livewire/PendingReviewBadge.php` | Badge en header: conteo de pendientes, poll cada 60s, link a sesión más reciente |
| `resources/views/livewire/contribution-review.blade.php` | Vista de revisión con 6 estados de UI |
| `resources/views/livewire/pending-review-badge.blade.php` | Vista del badge |
| `database/migrations/2026_08_15_000006_add_qbk_session_id_to_contribution_drafts_table.php` | Columna qbk_session_id |
| `tests/Feature/ContributionReviewTest.php` | 13 tests de revisión |
| `tests/Feature/PendingReviewBadgeTest.php` | 9 tests de badge |

### Archivos modificados (5)
| Archivo | Cambio |
|---|---|
| `app/Livewire/ContributeAporte.php` | Persiste qbk_session_id + crea draft en éxito |
| `app/Models/ContributionDraft.php` | STATUS_REVIEWED + scope pendingReview + qbk_session_id en fillable |
| `config/services.php` | qubeka.base_url para redirect |
| `routes/web.php` | Ruta /contributions/{sessionId}/review |
| `resources/views/layouts/app.blade.php` | Badge en header |
| `tests/Feature/ContributionDraftTest.php` | Test actualizado para nuevo diseño de draft en éxito |

---

## 5. Pendientes para futuro

| # | Pendiente | Razón |
|---|---|---|
| 1 | Validación E2E con servicio real de QuBeKa | Los 3 endpoints REST de QuBeKa aún no están construidos |
| 2 | Auto-refresh del badge más frecuente | Actualmente usa `wire:poll.60s`; se puede ajustar |
| 3 | Notificación in-app al recibir sesión pendiente | Hoy solo se ve el badge; una notificación push sería complementaria |

---

## 6. Commits

| Commit | Fase | Descripción |
|---|---|---|
| `0ba0a7c` | Fase 1 | getSession/approve/reject en QbkContributionService |
| `b6a1a35` | Fase 2 | ContributionReview component + vista + ruta |
| `84520e7` | Fase 3 | PendingReviewBadge + scope pendingReview |
| (este) | Fase 4 | QA, fix de test preexistente, cierre |
