# Cierre de Implementación — Ola 1, Punto 1: Conector Kuestion↔QuBeKa

*Equipo de Kuestion · Agosto 2026*
*Commits: `3e8b3bb` → `84e66fc` (7 commits)*

---

## 1. Resumen Ejecutivo

Se implementó el conector **QuBeKa** (`qbk`) dentro del sistema de conectores RAG de Kuestion, permitiendo que Kuestion consulte a QuBeKa como fuente de conocimiento intercambiable con Kuaforia. El conector cubre:

- Resolución de identidad del workspace del agente
- Consulta al Motor de Consulta (POST /query)
- Routing multi-conector por `connector_type` del repositorio
- UI de fuente y confianza
- Validación E2E del flujo completo de vigilancia
- Mock local para desarrollo y testing

**Estado: COMPLETADO** — Las 6 fases del plan se cerraron con tests y QA.

---

## 2. Fases Implementadas

### Fase 1 — Configuración y registro (`3e8b3bb`)

| Qué se hizo | Archivos |
|---|---|
| Entrada `qbk` en `config/kuestion.connectors.php` | `config/kuestion.connectors.php` |
| Sección `qubeka` en `config/services.php` | `config/services.php` |
| Variable `QUBKA_API_URL` en `.env.example` | `.env.example` |
| Stub `QbkService` (lanza 501) | `app/Services/QbkService.php` |
| Stub `QbkIdentityResolver` (lanza 501) | `app/Services/QbkIdentityResolver.php` |
| Tests de registro (4 tests) | `tests/Feature/ConnectorRegistryTest.php` |

### Fase 2 — Routing multi-conector (`00366c7`)

| Qué se hizo | Archivos |
|---|---|
| `ConnectorRegistry::ragProviderFor()`, `identityResolverFor()`, `signalProviderFor()` | `app/Services/ConnectorRegistry.php` |
| `QuestionChecker` resuelve servicio por `connector_type` | `app/Services/QuestionChecker.php` |
| `CreateQuestion` y `QuestionDetail` usan `ConnectorRegistry` | `app/Livewire/CreateQuestion.php`, `app/Livewire/QuestionDetail.php` |
| Job recibe `ConnectorRegistry` en vez de `RagProviderInterface` | `app/Jobs/CheckQuestionUpdatesJob.php` |
| Tests actualizados (routing + regresión) | 13 archivos de tests |

### Fase 3 — QbkService implementación real (`8220347`)

| Qué se hizo | Archivos |
|---|---|
| `QbkService::consult()` — POST a `/query` con Bearer token | `app/Services/QbkService.php` |
| Circuit breaker aislado (`qbk:failures`) | `app/Services/QbkService.php` |
| Parámetro `?array $credential` en `RagProviderInterface` | `app/Contracts/RagProviderInterface.php` |
| Callers pasan `$repo->credential` | `QuestionChecker`, `CreateQuestion`, `QuestionDetail` |
| Mock local `qbk-mock.php` (POST /query + GET /agent/me) | `qbk-mock.php` |
| Tests QbkService (12 tests) | `tests/Feature/QbkServiceTest.php` |

### Fase 4 — QbkIdentityResolver implementación real (`79f1643`)

| Qué se hizo | Archivos |
|---|---|
| `QbkIdentityResolver::resolveIdentity()` — GET a `/agent/me` | `app/Services/QbkIdentityResolver.php` |
| Mapeo: `tenantSlug` = workspace_id, `tenantName` = workspace_nombre | `app/Services/QbkIdentityResolver.php` |
| Tests (8 tests) | `tests/Feature/QbkIdentityResolverTest.php` |

### Fase 5 — UI fuente y confianza (`2bead71`)

| Qué se hizo | Archivos |
|---|---|
| Badge de fuente en detalle de pregunta | `resources/views/livewire/question-detail.blade.php` |
| Tooltip informativo cuando `confidence <= 50%` | `resources/views/livewire/question-detail.blade.php` |
| Tag de fuente en feed (question-card) | `resources/views/components/question-card.blade.php` |
| Tests de UI (4 tests) | `tests/Feature/QuestionConnectorBadgeTest.php` |

### Fase 6 — Validación E2E y cierre (`b1e5201`)

| Qué se hizo | Archivos |
|---|---|
| Test E2E vigilancia completa con repo qbk | `tests/Feature/QbkConnectorE2ETest.php` |
| Test job horario con repos qbk | `tests/Feature/QbkConnectorE2ETest.php` |
| Test "Comprobar ahora" con repos qbk | `tests/Feature/QbkConnectorE2ETest.php` |
| Test regresión Kuaforia | `tests/Feature/QbkConnectorE2ETest.php` |

### Script de desarrollo (`84e66fc`)

| Qué se hizo | Archivos |
|---|---|
| `scripts/dev-qbk.sh` — start/stop/restart/status | `scripts/dev-qbk.sh` |

---

## 3. Hallazgos Técnicos

### Hallazgo 1: Contrato QUBKA_API_URL vs workspace_id

**Problema:** QuBeKa tenía dos documentos contradictorios sobre si el `workspace_id` iba en el body del `POST /query`.

**Resolución:** QuBeKa confirmó que el workspace se resuelve SIEMPRE desde el token del agente via middleware `CheckWorkspace`. El body solo contiene `{"question": "..."}`. Se actualizó el plan y la implementación.

**Impacto:** La Fase 3 quedó más simple (un campo menos en el body).

### Hallazgo 2: `RagProviderInterface` necesitaba parámetro de credencial

**Problema:** Kuaforia usa una API key global de config, pero QuBeKa usa un `api_token` por repositorio. La interfaz `RagProviderInterface::consult()` no tenía forma de recibir la credencial del repo.

**Solución:** Se agregó parámetro opcional `?array $credential = null` a la interfaz. Backward-compatible: Kuaforia lo ignora, QuBeKa lo necesita.

**Impacto:** Se modificaron la interfaz, `KuaforiaService`, `FakeRagProvider`, y los 3 callers (`QuestionChecker`, `CreateQuestion`, `QuestionDetail`).

### Hallazgo 3: `ConnectorRegistry` necesitaba resolver por tipo

**Problema:** `ConnectorRegistry::classFor()` devolvía siempre la primera clase que implementaba la interfaz (Kuaforia). Con dos conectores, necesitaba resolver por `connector_type`.

**Solución:** Nuevos métodos `ragProviderFor()`, `identityResolverFor()`, `signalProviderFor()` que instancian el servicio correcto dado un tipo.

**Impacto:** Cambio en el flujo crítico de consulta y vigilancia. La Fase 2 fue la de mayor riesgo.

### Hallazgo 4: Tests con `Http::fake` y URLs de mock

**Problema:** Los patrones de `Http::fake` con URLs como `mock-qubeka.test/query` no interceptaban las requests reales (el dominio no resolvía).

**Solución:** Usar wildcard `'*'` o callbacks `Http::fake(fn () => ...)` en vez de patterns de URL específicos.

**Patrón:** Todos los tests de QbkServices usan `Http::fake(['*' => Http::response(...)])` o callbacks.

### Hallazgo 5: Procesos background con `setsid`

**Problema:** El script `dev-qbk.sh` usaba `(cd ... && php ... & echo $! > pidfile)` pero los procesos morían al salir del script padre.

**Solución:** Usar `setsid bash -c "cd ... && exec php ..."` para crear sesiones independientes, y capturar el PID real desde `ss -ltnp` o `pgrep`.

---

## 4. Fixes Aplicados

| Fix | Commit | Descripción |
|---|---|---|
| IdentityResolver forma real | `d82730f` | Acepta `tenant`/`default_workspace` al nivel raíz (sin wrapper `data`) — la forma real de Kuaforia vs el contrato P3 |
| workspace_map eliminado | `7421c76` | G7 cerrado: Kuaforia devuelve `default_workspace.id`, se elimina el fallback `workspace_map` |
| Flaky test `has_unreviewed_changes` | `8220347` | Factory setea `has_unreviewed_changes => false` en tests que dependen de ese estado |
| QbkIdentityResolver Http::fake | `79f1643` | Patrón wildcard para tests de identidad QBK |
| QbkService credential resolution | `8220347` | Tests setean `credential => ['api_token' => ...]` en repos QBK |

---

## 5. Tests — Estado Final

| Suite | Tests | Assertions |
|---|---|---|
| ConnectorRegistryTest | 8 | 22 |
| QuestionCheckerTest | 11 | 40 |
| QbkServiceTest | 12 | 24 |
| QbkIdentityResolverTest | 8 | 22 |
| QuestionConnectorBadgeTest | 4 | 4 |
| QbkConnectorE2ETest | 6 | 30 |
| CheckQuestionUpdatesJobSignalsTest | 5 | 15 |
| CheckQuestionUpdatesJobStatusTest | 4 | 12 |
| CheckQuestionUpdatesJobTest | 4 | 12 |
| CreateQuestionRepositoryTest | 4 | 12 |
| QuestionDetailCheckNowTest | 2 | 6 |
| QuestionDetailFollowUpTest | 2 | 4 |
| RagProviderInterfaceTest | 3 | 9 |
| **Total Punto 1** | **~73** | **~212** |
| Suite completa (con tests previos) | **206** | **598** |

---

## 6. Decisiones de Implementación

| Decisión | Razón |
|---|---|
| `tenantSlug` = workspace_id (string) en ResolvedIdentity | En QuBeKa no hay tenant superior — el workspace ES la unidad más alta. Se reutiliza el DTO existente sin crear uno nuevo. |
| `signal_provider = null` para qbk | QuBeKa no expone señales estructuradas (fuera de alcance). Se mantiene la opción abierta en la config. |
| Circuit breaker aislado (`qbk:failures`) | Independiente de `kuaforia:failures` para que un fallo de QuBeKa no pause Kuaforia ni viceversa. |
| Mock local `qbk-mock.php` | Permite desarrollo sin QuBeKa real. Reproduce el contrato exacto (POST /query + GET /agent/me). |
| `credential` como parámetro opcional | Backward-compatible: no rompe implementaciones existentes que no lo usan. |

---

## 7. Pendiente / Fuera de Alcance

| Item | Estado | Nota |
|---|---|---|
| QuBeKa real (no mock) | Pendiente | QuBeKa aún no tiene el Motor de Consulta construido. El conector funciona con mock; validación E2E real pendiente. |
| Señales estructuradas de QuBeKa | Fuera de alcance | `signal_provider = null`. Si QuBeKa expone señales en el futuro, se agrega a la config. |
| Selector de tipo de conector en UI | YAGNI | `connector_type` se setea automáticamente al conectar un repo. |
| Punto 3 (Aportar conocimiento) | Pendiente | Próximo paso de la Ola 1. |
| Punto 4 (Gate humano) | Pendiente | Depende del Punto 3. |
| Puntos 5-6 (Vigilancia y feed) | Pendiente | Independientes de 3-4. |

---

## 8. Commits

```
84e66fc Script dev-qbk.sh: levantamiento Kuestion + QuBeKa mock
b1e5201 Fase 6 Ola 1 Punto 1: validación E2E y cierre del conector Qbk
2bead71 Fase 5 Ola 1 Punto 1: UI de fuente y confianza del conector
79f1643 Fase 4 Ola 1 Punto 1: QbkIdentityResolver implementación real + tests
8220347 Fase 3 Ola 1 Punto 1: QbkService implementación real + mock + tests
00366c7 Fase 2 Ola 1 Punto 1: routing multi-conector via ConnectorRegistry
3e8b3bb Fase 1 Ola 1 Punto 1: config y stubs del conector Qbk
```

---

*Documento generado como cierre del Punto 1 de la Ola 1.*
