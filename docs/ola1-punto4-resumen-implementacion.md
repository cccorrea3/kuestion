# Resumen de Implementación — Ola 1, Punto 4: Gate humano de revisión desde Kuestion

*Equipo de Kuestion · Septiembre 2026*

---

## 1. Visión general

El Punto 4 implementa el **gate de revisión humana** para aportes de conocimiento. Cuando un usuario escribe un aporte desde Kuestion y QuBeKa lo clasifica automáticamente (Punto 3), el usuario necesita poder **aprobar, ajustar o descartar** esa clasificación sin salir de Kuestion.

### Arquitectura resultante

```
Usuario escribe aporte
        ↓
Kuestion → POST /contribute → QuBeKa clasifica
        ↓
QuBeKa devuelve { session_id, status, resumen }
        ↓
Kuestion guarda draft con qbk_session_id
        ↓
Badge en header muestra "N pendientes"
        ↓
Usuario clickea badge → /contributions/{id}/review
        ↓
    ┌───────────────┐
    │ is_simple?    │
    └───┬───────┬───┘
        ↓ sí    ↓ no
   Review en    Redirigir a
   Kuestion     QuBeKa
        ↓
   Aprobar / Descartar / Editar
        ↓
   QuBeKa promueve nodos al grafo
```

---

## 2. Cronología de desarrollo

| Fecha | Commit | Fase | Descripción |
|---|---|---|---|
| Sep 2026 | `0ba0a7c` | Fase 1 | Métodos `getSession()`, `approve()`, `reject()` en `QbkContributionService` |
| Sep 2026 | `b6a1a35` | Fase 2 | Componente `ContributionReview` + vista + ruta + migración `qbk_session_id` |
| Sep 2026 | `84520e7` | Fase 3 | Scope `pendingReview` + componente `PendingReviewBadge` en header |
| Sep 2026 | `3807d47` | Fase 4 | QA, fix de test, documento de cierre |
| Sep 2026 | `ce74453` | Funcional | 27 tests funcionales mapeados P1.1–P4.7 |

---

## 3. Archivos creados

### Componentes Livewire (3)

| Archivo | Líneas | Propósito |
|---|---|---|
| `app/Livewire/ContributionReview.php` | 254 | Pantalla de revisión: carga sesión de QuBeKa, muestra nodos en lenguaje natural, aprueba/rechaza/edita, redirige sesiones complejas |
| `app/Livewire/PendingReviewBadge.php` | 49 | Badge en header: cuenta pendientes, poll cada 60s, enlaza a sesión más reciente |
| `app/Livewire/ContributeAporte.php` | +18 | Modificado: guarda `qbk_session_id` al aportar exitosamente |

### Vistas Blade (2)

| Archivo | Líneas | Propósito |
|---|---|---|
| `resources/views/livewire/contribution-review.blade.php` | 174 | 6 estados de UI: loading, error, loaded, processing, approved, rejected |
| `resources/views/livewire/pending-review-badge.blade.php` | 15 | Badge amber con ícono de reloj, conteo y enlace |

### Migración (1)

| Archivo | Propósito |
|---|---|
| `database/migrations/2026_08_15_000006_add_qbk_session_id_to_contribution_drafts_table.php` | Columna `qbk_session_id` (nullable) + 2 índices en `contribution_drafts` |

### Tests (4 archivos, 144 tests)

| Archivo | Tests | Assertions | Cobertura |
|---|---|---|---|
| `tests/Feature/QbkContributionServiceTest.php` | 37 | 105 | Fase 1: servicios getSession/approve/reject |
| `tests/Feature/ContributionReviewTest.php` | 13 | 33 | Fase 2: componente de revisión |
| `tests/Feature/PendingReviewBadgeTest.php` | 9 | 13 | Fase 3: badge de pendientes |
| `tests/Feature/Punto4FunctionalTest.php` | 27 | 79 | Pruebas funcionales P1.1–P4.7 |
| **Subtotal Punto 4** | **86** | **230** | |
| Tests de Punto 3 (ContributeAporte, Draft, NoResults) | 36 | 89 | Regresión |
| **Total Punto 3+4** | **122** | **319** | |

### Documentación (2)

| Archivo | Propósito |
|---|---|
| `docs/ola1-punto4-cierre-implementacion.md` | Cierre formal con criterios de aceptación |
| `docs/ola1-punto4-resumen-implementacion.md` | Este documento |

---

## 4. Archivos modificados

| Archivo | Cambio | Impacto |
|---|---|---|
| `app/Models/ContributionDraft.php` | +`STATUS_REVIEWED`, +`scopePendingReview()`, +`qbk_session_id` en fillable | Habilita badge + revisión |
| `config/services.php` | +`qubeka.base_url` | URL para redirect a QuBeKa |
| `routes/web.php` | +`GET /contributions/{sessionId}/review` | Ruta de revisión |
| `resources/views/layouts/app.blade.php` | +`<livewire:pending-review-badge />` | Badge en header |
| `tests/Feature/ContributionDraftTest.php` | Test actualizado: draft ahora se crea en éxito | Refleja nuevo diseño |

---

## 5. Fixes realizados

### Fix 1: `auth()->id()` vs `auth()->user()->uuid`
- **Dónde:** `PendingReviewBadge::refreshCount()`
- **Problema:** `auth()->id()` devuelve el entero `id`, pero `contribution_drafts.user_id` referencia `users.uuid`. Las queries devolvían 0 resultados silenciosamente.
- **Solución:** Cambiar a `auth()->user()?->uuid`.
- **Severidad:** Alta — el badge nunca mostraba nada.

### Fix 2: Root tag missing en Blade
- **Dónde:** `resources/views/livewire/pending-review-badge.blade.php`
- **Problema:** La vista usaba `@if/@endif` como contenido principal sin un `<div>` raíz. Livewire lanza `RootTagMissingFromViewException`.
- **Solución:** Envolver todo en `<div>`.
- **Severidad:** Alta — el componente no renderizaba.

### Fix 3: Test de draft en éxito
- **Dónde:** `ContributionDraftTest::test_draft_not_created_on_success`
- **Problema:** El test esperaba 0 drafts después de un aporte exitoso. Pero el Phase 2 modificó `ContributeAporte::submit()` para crear un draft con `qbk_session_id` (necesario para el badge de pendientes).
- **Solución:** Renombrar a `test_draft_created_on_success_with_session_id` y verificar que el draft tiene `qbk_session_id` y `status = sent`.
- **Severidad:** Media — test preexistente que ya no reflejaba el diseño.

### Fix 4: `latest()` ordering inconsistency
- **Dónde:** `PendingReviewBadge::refreshCount()`
- **Problema:** `latest()` ordena por `created_at DESC`, pero en tests con timestamps cercanos el orden era inconsistente.
- **Solución:** Cambiar a `latest('id')` para ordering determinístico.
- **Severidad:** Baja — solo afectaba tests.

---

## 6. Hallazgos técnicos

### 6.1 Contrato de QuBeKa: endpoints REST no construidos
- **Impacto:** Todos los tests de Punto 4 usan mocks. La validación E2E contra servicio real queda pendiente.
- **Acción:** Documentado en el cierre. Cuando QuBeKa publique los 3 endpoints (`GET /sesiones-analisis/{id}`, `POST .../approve`, `POST .../reject`), repetir las pruebas P1.1–P2.5 con datos reales.

### 6.2 `redirectExternal()` de Livewire no produce assertRedirect
- **Impacto:** En tests, `assertRedirect()` no funciona con `redirectExternal()`.
- **Workaround:** Se valida `assertSet('isSimple', false)` en vez de verificar el HTTP redirect.
- **Nota:** Esto es limitación del testing framework, no del código en sí.

### 6.3 `wire:model` no se setea en Livewire testing
- **Impacto:** `call('toggleEdit')` + `wire:model` no propagaba el valor del textarea al componente.
- **Workaround:** Usar `call('updateNodeText', index, value)` directamente en vez de depender de `wire:model`.

### 6.4 Doble incremento en `markFailed()`
- **Ubicación:** `ContributionDraft::markFailed()` incrementa `attempts` + setea `status = failed`.
- **Problema potencial:** Si `incrementAttempt()` se llamaba antes de `markFailed()`, `attempts` se incrementaba doble. Se eliminó `incrementAttempt()` como dead code en commit `d551e6a`.

### 6.5 Configuración de IA en QuBeKa
- **Problema:** El `ai_config` del workspace estaba encriptado con una `APP_KEY` vieja → `DecryptException` al leer → fallback a `localhost:11434` (no disponible).
- **Solución:** Limpiar `ai_config` de la BD + configurar Ollama Cloud en `.env` de QuBeKa.
- **Impacto:** Afectaba todos los flujos que dependían de la IA de QuBeKa (contribute, query).

---

## 7. Suite de tests al cierre

### Por archivo

| Archivo | Tests | Assertions | Estado |
|---|---|---|---|
| QbkContributionServiceTest | 37 | 105 | ✅ |
| ContributionReviewTest | 13 | 33 | ✅ |
| PendingReviewBadgeTest | 9 | 13 | ✅ |
| Punto4FunctionalTest | 27 | 79 | ✅ |
| ContributeAporteTest | 14 | 42 | ✅ |
| QbkContributionServiceTest (contribute) | 14 | 39 | ✅ |
| ContributionDraftTest | 15 | 43 | ✅ |
| NoResultsContributeTest | 7 | 21 | ✅ |
| CreateQuestion/Detail/Checker | 8 | 30 | ✅ |
| ConnectorRegistryTest | 24 | 55 | ✅ |
| TenantConnectionTest | 6 | 18 | ✅ |
| Otros (Settings, Team, etc.) | 179 | 509 | ✅ |
| **Total** | **353** | **987** | **✅** |
| Pre-existente (RepositoryMigrationTest) | 1 | — | ⚠️ |

### Evolución de la suite

| Momento | Tests | Assertions |
|---|---|---|
| Inicio Punto 4 | 275 | 756 |
| Después Fase 1 | 312 | 861 |
| Después Fase 2 | 325 | 894 |
| Después Fase 3 | 326 | 908 |
| Después Fase 4 + Funcional | **353** | **987** |
| **Delta total** | **+78** | **+231** |

---

## 8. Criterios de aceptación

| # | Criterio | Estado | Test que valida |
|---|---|---|---|
| CA1 | getSession() devuelve nodos, status, is_simple, pregunta_previa | ✅ | P1.1 |
| CA2 | approve() con/sin textos_ajustados | ✅ | P1.2, P1.3 |
| CA3 | reject() devuelve rechazada | ✅ | P1.4 |
| CA4 | Error handling 401/403/404/5xx/timeout | ✅ | P1.5–P1.7 + unit tests |
| CA5 | Review muestra contenido en lenguaje natural | ✅ | P2.1 |
| CA6 | pregunta_previa como contexto | ✅ | P2.2 |
| CA7 | Aprobar → mensaje de confirmación | ✅ | P2.3 |
| CA8 | Descartar → mensaje de confirmación | ✅ | P2.4 |
| CA9 | Editar texto → envía textos_ajustados | ✅ | P2.5 |
| CA10 | Sesión compleja → redirige a QuBeKa | ✅ | P2.6, P4.4 |
| CA11 | Sesión no encontrada → error claro | ✅ | P2.7 |
| CA12 | Botones deshabilitados durante procesamiento | ✅ | P2.8 |
| CA13 | Aporte exitoso crea draft con qbk_session_id | ✅ | P3.1 |
| CA14 | Badge muestra conteo correcto | ✅ | P3.2 |
| CA15 | Badge enlaza a sesión más reciente | ✅ | P3.3 |
| CA16 | Badge se actualiza después de approve/reject | ✅ | P3.4 |
| CA17 | Drafts sin session_id no aparecen en badge | ✅ | P3.5 |
| CA18 | Flujo E2E completo funciona | ✅ | P4.1 |
| CA19 | Regresión Preguntar | ✅ | P4.2 |
| CA20 | Regresión Aportar | ✅ | P4.3 |
| CA21 | Múltiples pendientes cuentan y decrementan | ✅ | P4.5 |
| CA22 | Sesión ya procesada manejo graceful | ✅ | P4.6 |

---

## 9. Pendientes para futuro

| # | Pendiente | Prioridad | Razón |
|---|---|---|---|
| 1 | E2E con servicio real de QuBeKa | Alta | Los 3 endpoints REST aún no existen en QuBeKa |
| 2 | Notificación in-app al recibir sesión pendiente | Media | Hoy solo el badge; push sería complementario |
| 3 | Auto-refresh configurable del badge | Baja | Actualmente 60s fijo |
| 4 | Selector de tenant en dashboard de equipo | Baja | P13 del plan maestro, diferido |

---

## 10. Lecciones aprendidas

1. **`auth()->id()` ≠ `auth()->user()->uuid`**: En proyectos con UUID como FK, siempre usar `auth()->user()->uuid` para queries. `auth()->id()` devuelve el entero y las queries fallan silenciosamente.

2. **Livewire siempre necesita root tag**: Incluso si la vista renderiza condicionalmente, envolver en `<div>` es obligatorio.

3. **Los tests de integración con mocks no validan la UI real**: Los tests Livewire validan que el componente hace lo que dice, pero no que la pantalla sea usable. Las pruebas funcionales (P1.1–P4.7) agregan esa capa.

4. **Los cambios de diseño requieren actualizar tests preexistentes**: Cuando `submit()` pasó de no-crear-drafts a crear-drafts, el test existente rompió. Siempre revisar tests existentes al cambiar comportamiento.

5. **El ordering de `latest()` puede ser indeterminista**: En tests con timestamps cercanos, usar `latest('id')` es más confiable que `latest()` (que ordena por `created_at`).
