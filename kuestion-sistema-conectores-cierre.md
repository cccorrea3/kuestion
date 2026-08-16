# Sistema de Conectores RAG — Documento de Cierre

**Versión:** 1.1 · **Fecha:** 2026-08-15 · **Estado:** IMPLEMENTADO (Fases A–G completas)

**Referencias:** [plan de implementación](kuestion-sistema-conectores-plan-implementacion.md) (v2.0), [preguntas abiertas y respuestas](kuestion-sistema-conectores-preguntas-abiertas.md), [diseño funcional](docs/kuestion-sistema-conectores-referencia.md) (v1.1).

---

## 1. Resumen ejecutivo

Kuestion generalizó su conexión hardcodeada a Kuaforia en un **modelo de conectores + repositorios**: cada usuario conecta sus propias fuentes de conocimiento (`repositories`), la identidad del tenant se resuelve 100% vía MCP (`get_client_context`, contrato P3 confirmado por Ingeniería de Kuaforia), la consulta RAG y las señales estructuradas usan la credencial del repositorio (cerrando el **Hallazgo 2** de la key compartida), y el dashboard de equipo agrega por el tenant del repo `is_default` (decisión **P13 corregida**, sin mezclar organizaciones).

La implementación se ejecutó en **7 fases / 7 commits (M31–M37)**, sin coordinación externa bloqueante, con suite de tests verde en cada fase y la base de datos de test separada desde el inicio (M30).

**Métricas finales:** 155 tests / 433 assertions, 100% verde, sin tests en `@group skip`. `vendor/bin/pint` PASS en todas las fases.

---

## 2. Estado por fase (evidencia de cumplimiento)

| Fase | Contenido | Commit | Delta tests | Suite al cierre |
|---|---|---|---|---|
| **A** — Modelo de datos | `repositories` (uuid PK, FK `users.uuid` cascade, `credential` cifrada, `status`, `is_default`, `resolved_tenant_*`), `questions.repository_id` (FK restrict + backfill defensivo), modelo + factory, registro de conectores | `491810a` (M31) | **+8** (cascade, cifrado en reposo, FK restrict, backfill end-to-end, relaciones, config) | **113/310** |
| **B** — Interfaces | `IdentityResolverInterface` + DTO `ResolvedIdentity` + clase `IdentityResolver` (contrato P3), wrapper `resolveTenantFromApiKey`, `ConnectorRegistry` + bindings desde config, mock con `get_client_context` | `2cc1c8e` (M32) | **+11** (JSON-RPC, 401 plano, sin tenant, wrapper) + smoke real contra el mock | **124/344** |
| **C** — Flujo de conexión | `Register` crea usuario + primer repo (transacción), `/settings` gestiona repos (crear/editar/desconectar P5), `x-connector-help`, `KuaforiaKeyPrompt` por repos (B1), onboarding con `resolved_tenant_name` (B2) | `f9631ea` (M33) | **+7** (registro con repo, key inválida, editar, desconectar único activo, primera conexión, prompt, onboarding) | **131/369** |
| **D** — Consulta con repo | `consult()` con tenant explícito, 401 fuera del breaker, migración NOT NULL, `CreateQuestion` (selector 2+, bloqueo 0), `QuestionController::store`, `askFollowUp` con repo de la pregunta, job 401→`invalid` / 503→`active` | `a4cc145` (M34) | **+11** (bloqueo, selector, follow-up, job 401/503) | **142/402** |
| **E** — Señales + dashboard | `KuaforiaMcpProvider` con credencial del repo (cierra Hallazgo 2), job con `resolved_workspace_id` + key del repo, `TeamDashboard` por `resolved_tenant_slug` del `is_default` (P13, sin mezcla, degradación) | `bb79636` (M35) | **+5** (header con key del repo, workspace del repo, no-mezcla de tenants, degradación) | **147/417** |
| **F** — Estado visible en UI | `x-repository-status-badge` (invalid→enlace, revoked→sin acción, active→nada) en feed y detalle con eager load; `RepositoryStatusIndicator` en el header (invalid→`/settings?highlight=`) | `4398891` (M36) | **+8** (badge feed/detalle, indicador header) | **155/433** |
| **G** — Limpieza y cierre | Drop de `users.tenant_slug` + `users.kuaforia_api_key`, `UserFactory`/`AdminUserSeeder` con repo, config sin `tenant_resolution`/`tenants`, docs actualizados | `5f7a27b` (M37) | **+0** (G no agrega tests nuevos; los 2 actualizados siguen verdes) | **155/433** |

---

## 3. Hallazgos y decisiones clave

### 3.1 Hallazgos del diseño (validados contra el código)

| # | Hallazgo | Resolución |
|---|---|---|
| A1 | El documento descartaba la vía REST de identidad, pero el código usaba `/api/validate-api-key` con default REST | Identidad 100% vía MCP (`get_client_context`); la vía REST se eliminó del código en B3 y de config en G4 |
| A2 | El doc referenciaba `\App\Connectors\Kuaforia\*` inexistente | Clases se quedan en `app/Services/`; no se crea `app/Connectors/` hasta tener un segundo conector real |
| A3/A4 | Clase y firma del resolver no estaban definidas | Clase dedicada `App\Services\IdentityResolver` con `resolveIdentity(array $credential): ResolvedIdentity`; `resolveTenantFromApiKey(string)` queda como wrapper público |
| A5 | ¿`get_client_context` entra al mapeo genérico `mcp_tools`? | No — ese mapeo es para señales; el resolver maneja la tool directamente |
| B1–B5 | Componentes no cubiertos por §7 (prompt, onboarding, config, factories) | Todos adaptados: prompt por `repositories`, onboarding por `resolved_tenant_name`, seeder con repo, `services.kuaforia.tenants` eliminado |

### 3.2 Decisiones de producto cerradas (24 ítems del doc de preguntas)

Todas las respuestas del equipo quedaron aplicadas y documentadas en §0.4 del plan. Las más relevantes:

- **P3 (contrato confirmado):** `POST /api/v1/mcp` landlord, JSON-RPC `tools/call` con `get_client_context`; `content[0].text` es **string JSON** (`data.tenant.slug/name`); errores **401 en JSON plano** que rompen el sobre JSON-RPC; no existe "key sin tenant"; `workspace_id` **NO viene hoy** → fallback `workspace_map` vigente.
- **P5:** se permite desconectar el único repo activo → estado "0 repos activos" (bloqueo en creación + onboarding del feed vacío).
- **P7:** nombre del repo `"{display_name} - {tenant_name}"` truncado a 100.
- **P9:** `last_used_at` al crear pregunta y en el job, no en cada follow-up.
- **P10:** el circuit breaker es por servicio, no por repo; el 401 no cuenta para él.
- **P12:** resaltado vía `?highlight=<repo_id>` + anillo visual en `/settings`.
- **P13 (corregida):** el dashboard **no mezcla tenants** — se restringe al tenant del repo `is_default` del usuario; sin selector de tenant en esta versión.
- **P8:** se eliminó `tenant_resolution` sin flag de fallback (un fallback a un endpoint no confirmado no es una red de seguridad real).

### 3.3 Hallazgos técnicos de la implementación (no previstos en el plan)

| Hallazgo | Fase | Cómo se resolvió |
|---|---|---|
| El NOT NULL de `repository_id` rompería la app y ~15 archivos de tests entre fases | A | **Desviación documentada:** la columna queda nullable en A2; el NOT NULL se difiere a D2 (cuando los flujos de creación setean `repository_id`) |
| Livewire 4 no tiene `forgetComputed`; el prompt sin root tag falla al ocultarse | C | `unset($this->repositories)` invalida la computed; root tag siempre presente con `hidden` |
| MySQL exige dropear la FK antes de cambiar una columna a NOT NULL | D | Drop/readd de la FK en la misma migración |
| La migración nueva (000004) rompió el rollback del test de backfill (2 pasos → revertía la migración equivocada) y dejaba la BD de test corrupta | D/G | `RepositoryMigrationTest` revierte 3 pasos (G1 + D2 + A2) |
| Las migraciones históricas posicionaban columnas `after('tenant_slug')` — con el drop de G1, `migrate:fresh` fallaría | G | Se eliminó el `after()` en `000004_add_email_notifications` y `000005_add_kuaforia_api_key` |
| `Register`/`Settings` capturan `KuaforiaException` | B | El wrapper B3 convierte `KuaforiaMcpException` → `KuaforiaException` para no romper el manejo de error de la UI |
| `TenantConnectionTest` fakes el endpoint REST viejo | B/C | Actualizado al contrato MCP (P3); los asserts de UI no cambiaron |

---

## 4. Desviaciones del plan (todas documentadas en el propio plan)

1. **`repository_id` nullable en A2, NOT NULL en D2** — el plan anticipaba el NOT NULL "en la práctica" en D; se formalizó como desviación explícita.
2. **`RepositoryFactory` creada en Fase A** (el plan la ubicaba en G2) — los tests de A1/A2 la necesitaban.
3. **Credencial por parámetro de método en `KuaforiaMcpProvider`** (no constructor por repo) — el provider es singleton en el contenedor; el parámetro opcional mantiene el fallback a config.
4. **Test de `identity_resolver` diferido a B5** — la clase no existía en A4; se verifica solo la declaración de la FQCN.
5. **`TenantConnectionTest` actualizado en B/C** (el plan lo ubicaba en G3) — el cambio de vía de resolución lo obligó antes.
6. **`RepositoryMigrationTest` a 3 pasos** — consecuencia del drop de columnas (G1).

Ninguna desviación implicó reabrir una decisión de producto cerrada.

---

## 5. Garantías de regresión (alerta roja)

| Comportamiento protegido | Verificación |
|---|---|
| `POST /api/consult/{tenant_slug}` + parsing `{answer, confidence, sources, conversation_id}` | `KuaforiaServiceTest` + smoke con mock; diff de `consult()` acotado al tenant explícito y al 401 fuera del breaker |
| `ChangeDetector` (SHA-256 + similitud coseno) | `ChangeDetectorTest` y `DiffTest` sin cambios en todo el proyecto |
| Transacción del job (`lockForUpdate`, numeración, `response_hash`, notificación) | `CheckQuestionUpdatesJobTest` intacto; diff del job revisado línea por línea (solo origen de tenant/credencial + catch 401) |
| Filtros del feed, FULLTEXT, tags, relaciones, feedback, timeline | Suite completa verde en cada fase |
| Aislamiento por usuario (`current_user_id()`) | Excepción única y documentada: TeamDashboard cruza por `resolved_tenant_slug` del repo `is_default` (P13) |

---

## 6. Estado de las preguntas externas (Kuaforia)

| Pregunta | Estado | Impacto si se responde |
|---|---|---|
| **8.1 / G6** — ¿La `kfr_` del usuario autentica `POST /api/consult/{tenant_slug}`? | Pendiente (no bloqueante) | `consult()` usaría la credencial del repo y se eliminaría la key compartida (`services.kuaforia.api_key`) |
| **8.2 / G7** — ¿`get_client_context` devuelve `workspace_id`? | **Confirmado que NO hoy** (P3) | Cuando lo devuelva: persisti-lo en `repositories.resolved_workspace_id` y eliminar el fallback `workspace_map` |

---

## 7. Reconciliación de números de tests (corrección v1.1)

**Hallazgo del revisor:** la v1.0 de este documento listaba en la sección 8 una evolución que empezaba en 113 — absorbía el salto de la Fase A (+8) en el baseline y desfasaba los incrementos una posición respecto de lo declarado por fase. El diagnóstico era correcto: los saltos de la lista (+11,+7,+11,+5,+8,+0) eran exactamente los deltas de las fases B→G, con el +8 de A oculto en el punto de partida.

**Respuestas a las dos preguntas:**

1. **¿El 113 de partida era un número real?** No como baseline: era el cierre verificado de la Fase A (105 previos + 8 nuevos). El baseline real de la suite al arrancar el proyecto (M30) era **105 tests / 146 assertions**, verificado en el commit `473ce06` (separación de la base de datos de test dedicada). La cadena completa 105 → 155 quedó reconstruida en la sección 8 con cada delta respaldado por la corrida de QA de su fase.
2. **Confirmación final:** `php artisan test` corrió sobre el estado actual del repo el **2026-08-15** → **155 tests / 433 assertions**, 100% verde, sobre `kuestion_test`. Ese es el número único y verificado de cierre.

**Corrección aplicada:** la sección 2 ahora declara el delta y la suite al cierre por fase (los deltas suman exactamente +50: 105 → 155); la sección 8 muestra la cadena con los deltas explícitos por fase.

## 8. Pendientes y recomendaciones

1. **Coordinar G6/G7 con Ingeniería de Kuaforia** — los dos únicos condicionales restantes; ninguno bloquea el uso actual.
2. **Segundo conector real** (cuando exista) — recién ahí crear `app/Connectors/` y el selector de conector (§6.3, decisión P6/YAGNI).
3. **Sistema de roles** — reemplazar `team_dashboard_access` (nota 12.5 del maestro, sigue vigente).
4. **Revisar la superficie de confianza residual** (`mcp_api_key` como fallback de señales) el día que haya más de un tenant sensible conectado simultáneamente.

---

## 9. Cadena de commits y evolución verificada de la suite

```
M31 491810a  Fase A — modelo de datos de repositorios
M32 2cc1c8e  Fase B — IdentityResolver con contrato P3
M33 f9631ea  Fase C — flujo de conexión con repositorios
M34 a4cc145  Fase D — consulta RAG con repositorio
M35 bb79636  Fase E — señales con credencial del repo + dashboard por tenant
M36 4398891  Fase F — estado del repositorio visible en la UI
M37 5f7a27b  Fase G — limpieza y cierre
```

**Evolución verificada de la suite** (cada valor es una corrida real de QA de esa fase; los deltas coinciden con los tests nuevos declarados por fase en la sección 2):

```
105 ─(+8)─▶ 113 ─(+11)─▶ 124 ─(+7)─▶ 131 ─(+11)─▶ 142 ─(+5)─▶ 147 ─(+8)─▶ 155 ─(+0)─▶ 155
(M30)      (A)          (B)          (C)          (D)          (E)          (F)         (G)
```

**Número final confirmado hoy (2026-08-15):** `php artisan test` sobre el estado actual del repo → **155 tests / 433 assertions**, 100% verde, sobre la base de datos de test dedicada `kuestion_test`. El baseline 105 corresponde a la corrida verificada de M30 (commit `473ce06`, separación de la BD de test).
