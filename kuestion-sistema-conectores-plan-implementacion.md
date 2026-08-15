# Kuestion — Sistema de Conectores RAG: Plan de Implementación

**Versión:** 2.0 | **Fecha:** 2026-08-15
**Fuente:** `docs/kuestion-sistema-conectores-referencia.md` v1.1 (diseño cerrado) + validación contra el código actual + **respuestas consolidadas del equipo (ítems de `kuestion-sistema-conectores-preguntas-abiertas.md`) aplicadas en esta versión.**

---

## 0. Contexto y reglas de alcance

Este plan traduce el diseño cerrado del Sistema de Conectores RAG (documento de referencia v1.1) a un plan de implementación ejecutable por fases y tareas. **No reabre decisiones de diseño**: el modelo de repositorios, la ficha Kuaforia, las 12 decisiones de UX y las 10 decisiones cerradas son contrato. Lo que queda a criterio técnico (nombres, estructura de migraciones, organización de archivos) se resuelve acá.

### 0.1 Regla de oro (alerta roja)

- **El mecanismo REST/hash NO se toca en su esencia:** `POST /api/consult/{tenant_slug}`, `ChangeDetector` (SHA-256 + similitud coseno), `QuestionController`, `CreateQuestion`, `QuestionDetail::askFollowUp` y `CheckQuestionUpdatesJob` son comportamiento existente. Los cambios son **de origen de datos** (de dónde sale el tenant/credencial), no del mecanismo de consulta/detección.
- Cualquier `git diff` que toque la lógica de `ChangeDetector` o la transacción del job (numeración de versiones, `lockForUpdate`, `response_hash`) es una alerta roja.

### 0.2 Orden de implementación y dependencias

```
Fase A (datos) ──► Fase B (interfaces/identidad) ──► Fase C (flujo conexión)
                                                    └──► Fase D (consulta con repositorio)
                                                          └──► Fase E (señales + dashboard)
                                                                └──► Fase F (estado en UI)
                                                                      └──► Fase G (limpieza)
```

Dependencias internas:
- A → B → C (el flujo de conexión usa el resolver formalizado de B).
- A → D (la consulta necesita `repositories` y `questions.repository_id`).
- D → E (el job enriquece con señales usando la credencial del repositorio de la pregunta).
- B + D → F (los estados `invalid`/`revoked` se generan en D y se muestran en F).
- G es la última (elimina columnas de `users` una vez que nada más las lee).

### 0.3 Dependencia externa (no bloqueante)

De los ítems de `kuestion-sistema-conectores-preguntas-abiertas.md`, **solo 2 siguen en coordinación con Kuaforia**, ninguno bloqueante (ver §0.4):

- **P1** (ref. §8.1): si la `kfr_` del usuario autentica `POST /api/consult/{tenant_slug}`. Mientras tanto: key compartida (G6 condicional).
- **P2** (ref. §8.2): `workspace_id` por defecto en `get_client_context`. **Confirmado por Kuaforia que hoy NO viene en la respuesta** (ver P3) → el fallback `workspace_map` se mantiene (G7 condicional a futuro).

La **P3 (contrato de `get_client_context`) quedó RESUELTA** con contrato completo confirmado por Ingeniería de Kuaforia (detalle en B2) — las Fases A y B pueden arrancar de inmediato sin esperar nada externo.

### 0.4 Decisiones consolidadas del equipo (todas cerradas)

| ID | Decisión | Dónde aterriza |
|---|---|---|
| A1 | Identidad 100% vía MCP (`get_client_context`); vía REST (`/api/validate-api-key`, `/api/v1/cli/health`) descartada y eliminada en Fase G | B2, G4 |
| A2 | Clases se quedan en `app/Services/`; no crear `app/Connectors/` hasta un segundo conector real | A4 |
| A3 | Clase dedicada `App\Services\IdentityResolver` implementa `IdentityResolverInterface` | B2 |
| A4 | `resolveIdentity(array $credential): ResolvedIdentity` adoptado; `resolveTenantFromApiKey(string): array` queda como wrapper público | B2/B3 |
| A5 | `get_client_context` NO entra al mapeo genérico `mcp_tools` (ese mapeo es para señales); `IdentityResolver` lo maneja directo | B2 |
| B1 | `KuaforiaKeyPrompt` evalúa `$user->repositories->isEmpty()` | C4 |
| B2 | Onboarding lee `resolved_tenant_name` del primer repositorio; sin repos → "Conectá tu fuente de conocimiento" | C5 |
| B3 | `services.kuaforia.tenants` se elimina en Fase G; el nombre sale de `repositories.resolved_tenant_name` | G4 |
| B4 | La validación en vivo ya existe — se adapta (MCP + `tenant_name (tenant_slug)` + persiste en repos), no se crea de cero | C1/C3 |
| P1 | (Kuaforia) Key compartida mientras tanto — no bloquea | G6 |
| P2 | (Kuaforia) `workspace_map` como fallback mientras tanto — no bloquea | G7 |
| P3 | **Resuelta** — contrato completo de `get_client_context` (ver B2) | B2/B4 |
| P4 | Backfill defensivo en A2; entorno sin datos reales confirmado | A2 |
| P5 | Se permite desconectar el único repo activo → cae en estado "0 repos activos" (bloqueo de creación + onboarding feed vacío) | C2 |
| P6 | No se construye el selector de conector (YAGNI); config ya preparada | A4 |
| P7 | Nombre autogenerado: `"{display_name} - {tenant_name}"` truncado a 100 caracteres | C1 |
| P8 | `tenant_resolution` se elimina en Fase G; sin flag de fallback (fallback a endpoint no confirmado no es red de seguridad real) | G4 |
| P9 | `last_used_at` se actualiza en creación de pregunta y en el job; no en cada follow-up | D2, D4 |
| P10 | Circuit breaker independiente del estado `invalid` del repo (por servicio, no por repo); 401 no cuenta para el breaker | D4 |
| P11 | El selector solo muestra repos `active`; sin aviso adicional (el mensaje de bloqueo §6.12 cubre el caso todos-invalid) | D2 |
| P12 | Resaltado vía `?highlight=<repo_id>` + anillo/borde visual | C2, F2 |
| P13 | **Corregida:** el dashboard de equipo NO mezcla tenants — se restringe al tenant del repo `is_default` del usuario; sin selector de tenant | E3 |
| P14 | Sin relación con `structured_signals` en esta implementación (evolución futura del Bloque 8) | — (fuera de alcance) |

---

## Fase A — Modelo de datos de repositorios

**Objetivo:** crear la tabla `repositories`, agregar `repository_id` a `questions`, modelo Eloquent y registro de conectores en config.

**Prioridad:** P0 — todo depende de esto.

### A1. Migración `create_repositories_table`

- **Objetivo:** crear la tabla `repositories` según §3.1 del documento de referencia (UUID PK, `user_id` FK cascade, `connector_type`, `name` nullable, `credential` encrypted:array, `resolved_tenant_slug`, `resolved_tenant_name`, `resolved_workspace_id`, `status` enum string, `is_default`, `last_validated_at`, `last_used_at`, timestamps).
- **Aspectos técnicos:**
  - `credential` como `longText` (JSON cifrado). El cast `encrypted:array` de Eloquent serializa el array a JSON y lo cifra (misma técnica que `users.kuaforia_api_key` que ya usa `'encrypted'`).
  - `status` como `string(20)` con default `'active'` — **no enum nativo de MySQL** (decisión cerrada §9.5: agregar conector no debe requerir migración).
  - Índices: `(user_id, status)` y `(user_id, connector_type)`.
  - Convención de FKs del proyecto: `user_id` → `users.uuid` (igual que `questions.user_id`).
- **QA/Review:**
  - `php artisan migrate --force` en dev y `migrate:fresh --seed` en test.
  - Verificar esquema con `SHOW CREATE TABLE repositories` (índices, FKs).
  - Test de cascade: borrar usuario → se borran sus repositorios.
  - **Automático:** feature test crea un `Repository` con factory, verifica que `credential` guardado en BD está cifrado (no se ve la key en claro) y que el cast lo descifra al leer.

### A2. Migración `add_repository_id_to_questions_table`

- **Objetivo:** agregar `repository_id` (UUID, FK → `repositories(id)`, `onDelete('restrict')`, NOT NULL) según §3.2.
- **Aspectos técnicos:**
  - **Problema de backfill:** la columna es NOT NULL y hay preguntas existentes (en dev hoy 0, pero el ambiente de test puede tener). Estrategia: agregar la columna **nullable** → backfill (asignar a un repositorio por defecto del usuario, o crear uno implícito) → volver NOT NULL. (Decisión **P4 confirmada**: backfill defensivo; entorno sin datos reales verificado por el equipo.)
  - `onDelete('restrict')`: preserva el historial de preguntas (decisión cerrada §9.3).
- **QA/Review:**
  - `migrate:fresh --seed` pasa; esquema con FK `restrict` verificado.
  - Test de restricción: borrar un repositorio con preguntas → error de FK (no cascade).
  - Test de backfill: pregunta existente sin repo → queda asignada al repo por defecto del usuario.

### A3. Modelo `Repository` + relaciones

- **Objetivo:** `app/Models/Repository.php` con `HasUuids`, `fillable` acotado, casts (`credential => encrypted:array`, `is_default => boolean`, timestamps), relaciones `user()` (belongsTo), `questions()` (hasMany). En `User`: `repositories()` hasMany. En `Question`: `repository()` belongsTo.
- **Aspectos técnicos:**
  - Convención: mismo patrón que `Question`/`AnswerVersion` (UUID como PK, `booted()` con `Str::uuid()` o `HasUuids`).
  - No agregar `tenant_slug` al modelo `User` — se elimina en Fase G.
- **QA/Review:**
  - Test: `$user->repositories`, `$question->repository`, `$repository->questions`.
  - Test: el cast `credential` cifra en reposo (verificado en A1).

### A4. Registro de conectores en config

- **Objetivo:** crear `config/kuestion.connectors.php` con la ficha de Kuaforia según §1.3 (display_name, description, auth_fields, identity_resolver, rag_provider, signal_provider). (Decisión **A2 confirmada**: las clases se quedan en `app/Services/` — no se crea `app/Connectors/` hasta que exista un segundo conector real; reubicar sería churn sin valor.) El registro queda: `identity_resolver` → `App\Services\IdentityResolver` (clase nueva de B2), `rag_provider` → `App\Services\KuaforiaService`, `signal_provider` → `App\Services\KuaforiaMcpProvider`. (Decisión P6: no se construye el selector de tipo de conector — la config ya queda preparada para un segundo conector.)
- **Aspectos técnicos:**
  - La config nueva se carga con `Config::get('kuestion.connectors')`; se mergea con `config/kuestion.php` existente (`'connectors' => [...]`) o archivo aparte — decisión: **archivo aparte** `config/kuestion.connectors.php` para que el registro sea explícito y no ensucie la config principal.
- **QA/Review:**
  - `php artisan config:clear` + tinker: `config('kuestion.connectors.kuaforia.display_name') === 'Kuaforia'`.
  - **Automático:** test que lee la config y verifica que las 3 claves de clases (`identity_resolver`, `rag_provider`, `signal_provider`) apuntan a clases que implementan las interfaces correctas (`is_subclass_of` / `instanceof` al resolver). (Ejecutado: `rag_provider`/`signal_provider` verificados con `is_subclass_of`; `identity_resolver` solo como FQCN string — el `instanceof` se valida en B5, cuando la clase exista.)

### Notas de implementación (Fase A ejecutada — M31)

**Archivos (8 nuevos + 2 modificados):**
- **A1** `2026_08_15_000001_create_repositories_table.php` — uuid PK, `user_id` FK → `users.uuid` cascade, `connector_type` default 'kuaforia', `name` nullable, `credential` longText (JSON cifrado con cast `encrypted:array`), `resolved_tenant_slug/name/workspace_id`, `status` string(20) default 'active', `is_default`, `last_validated_at`, `last_used_at`; índices `(user_id, status)` y `(user_id, connector_type)`.
- **A2** `2026_08_15_000002_add_repository_id_to_questions_table.php` — columna nullable + FK `restrictOnDelete` + backfill defensivo (asigna huérfanas al repo `is_default` del usuario, o el más antiguo si no hay default).
- **A3** `app/Models/Repository.php` (HasUuids, cast `credential => encrypted:array`, `is_default => boolean`, relaciones `user()`/`questions()`) + `database/factories/RepositoryFactory.php` (`user_id` → `users.uuid`); `User::repositories()`, `Question::repository()` + `repository_id` en `fillable`.
- **A4** `config/kuestion.connectors.php` — ficha Kuaforia (display_name, description, auth_fields con hint §6.1, `identity_resolver` → `App\Services\IdentityResolver` [Fase B], `rag_provider` → `KuaforiaService`, `signal_provider` → `KuaforiaMcpProvider`).

**Desviaciones de este plan (con razón):**
1. **`repository_id` queda NULLABLE en A2; el NOT NULL se difiere a D2.** Razón: en Fase A los flujos de creación de preguntas (`CreateQuestion::save`, `QuestionController::store`) todavía no setean `repository_id` (eso es Fase D, con el selector de repos). Forzar NOT NULL ahora rompería la creación de preguntas entre fases y ~15 archivos de tests — el propio plan decía que el NOT NULL "en la práctica" ocurre en D2. El backfill defensivo (P4) y la FK restrict (decisión §9.3) se mantienen tal cual.
2. **`RepositoryFactory` se crea en Fase A** (el plan la ubicaba en G2) porque los tests de A1/A2 la necesitan; G2 solo agrega el cambio de `UserFactory` (quitar `tenant_slug`).
3. **Test de `identity_resolver` diferido a B5** (en A4 la clase dedicada no existe todavía).

**QA/Review ejecutado:**

| Criterio (plan) | Cómo se verificó | Resultado |
|---|---|---|
| A1 cascade: borrar usuario → repos borrados | `test_repositories_are_cascade_deleted_with_user` | ✅ |
| A1 credential cifrada en reposo + cast descifra | `test_credential_is_encrypted_at_rest_and_decrypted_by_cast` (raw en BD ≠ key en claro) | ✅ |
| A2 FK restrict (historial preservado) | `test_deleting_repository_with_questions_is_restricted` (QueryException) | ✅ |
| A2 backfill defensivo (P4) | `RepositoryMigrationTest` con `DatabaseMigrations`: rollback de A2 → pregunta huérfana → re-migrate → asignada al repo `is_default` | ✅ |
| A3 relaciones | `test_repository_relationships` (user / repositories / repository / questions) | ✅ |
| A4 config de conectores | `ConnectorRegistryTest`: display_name, auth_fields, `rag_provider`/`signal_provider` implementan interfaces; smoke tinker (`config(...) === 'Kuaforia'`, `is_subclass_of` true) | ✅ |
| Regresión (alerta roja) | Suite completa: **113 tests (310 assertions)** — 105 previos + 8 nuevos; sin tocar REST/hash/job | ✅ |
| Estilo | `vendor/bin/pint` PASS | ✅ |

---

## Fase B — Formalización de interfaces: IdentityResolver

**Objetivo:** crear `IdentityResolverInterface` + `ResolvedIdentity`, implementarlos en una **clase dedicada `App\Services\IdentityResolver`** (resolución 100% vía MCP con `get_client_context`, contrato P3 confirmado) y conectar el registro de conectores al contenedor.

**Prioridad:** P0 — C depende de esto. **Sin dependencia externa** (P3 resuelta; el mock B4 se construye contra el contrato real, no como aproximación).

### B1. `IdentityResolverInterface` + DTO `ResolvedIdentity`

- **Objetivo:** según §4.3: `resolveIdentity(array $credential): ResolvedIdentity` con `tenant_slug`, `tenant_name`, `workspace_id`, `raw`. (Decisión **A4**: esta es la firma adoptada.)
- **Aspectos técnicos:**
  - DTO inmutable simple (clase con constructor promovido, `readonly` properties).
  - El método recibe `array $credential` (la forma de `repositories.credential`), no la key suelta — desacopla del formato de almacenamiento.
- **QA/Review:**
  - **Automático:** test unit del DTO (valores que entran, salen).

### B2. Clase dedicada `App\Services\IdentityResolver` (contrato P3 aplicado)

- **Objetivo:** (decisión **A3**) nueva clase `App\Services\IdentityResolver implements IdentityResolverInterface`, que resuelve identidad **100% vía MCP** con la tool `get_client_context`. La vía REST (`/api/validate-api-key` y `/api/v1/cli/health`) queda descartada (A1).
- **Contrato confirmado (P3, Ingeniería de Kuaforia):**
  - **Endpoint:** `POST /api/v1/mcp` (landlord, sin subdominio), JSON-RPC `tools/call`, tool name `get_client_context`.
  - **Respuesta exitosa:** `result.content[0].text` es un **STRING JSON** (no objeto anidado directo) con la forma `{"success": true, "data": {"tenant": {"slug": "...", "name": "..."}, "scopes": [...], "mcp_user_id": null, "expires_at": "...", "knowledge_workspace": {...}}}`. Usar `data.tenant.slug` y `data.tenant.name`.
  - **Errores:** HTTP 401 con **JSON plano** (rompe el sobre JSON-RPC — no viene como `error` JSON-RPC): `{"success":false,"error":"Invalid or expired API key"}` o `{"success":false,"error":"Missing API key"}` (sin Bearer).
  - **No existe el caso "key sin tenant"**: toda key válida tiene tenant (constraint NOT NULL del lado de Kuaforia).
  - **`workspace_id`: confirmado que hoy NO viene** en la respuesta (P2) → `ResolvedIdentity->workspace_id` queda null y el fallback `workspace_map` sigue vigente.
- **Aspectos técnicos:**
  - Reutilizar el patrón JSON-RPC ya construido en `KuaforiaMcpProvider` (`Http::timeout(...)->withToken(...)->post(...)` con `tools/call`) — **reutilizar, no duplicar** (ver §Eficiencia).
  - **Nota de implementación (P3):** el parseo debe esperar un **string JSON dentro de `content[0].text`** (decodificar dos niveles), y el manejo de 401 debe contemplar que ese caso **rompe el sobre JSON-RPC** (viene plano).
  - La URL del MCP es fija a nivel de despliegue (`config('services.kuaforia.mcp_url')`), sin `{slug}` (decisión §9.6).
  - `get_client_context` **NO se agrega al mapeo genérico `mcp_tools`** (decisión **A5**: ese mapeo es para señales; la identidad la maneja esta clase directo).
- **QA/Review:**
  - **Automático:** test con `Http::fake()` del puente MCP: body JSON-RPC correcto (`tools/call`, name `get_client_context`); **`content[0].text` como string JSON anidado** → normaliza `data.tenant.slug`/`data.tenant.name`; 401 con JSON plano → excepción con mensaje de key inválida; respuesta sin `data.tenant` → excepción clara.
  - **Manual:** smoke contra `kuaforia-mock.php` extendido (B4).
  - Regresión: `KuaforiaServiceTest` existente sigue pasando (el wrapper B3 mantiene firma).

### B3. Wrapper de compatibilidad en `KuaforiaService`

- **Objetivo:** (decisiones **A3/A4**) `KuaforiaService::resolveTenantFromApiKey(string $apiKey): array` se mantiene como **wrapper público** que delega en `App\Services\IdentityResolver::resolveIdentity(['api_key' => $apiKey])`, para no romper los llamadores actuales (`Register`, `Settings`) hasta Fase C. `KuaforiaService` **no** implementa `IdentityResolverInterface` (la implementa la clase dedicada).
- **QA/Review:**
  - Regresión: `KuaforiaServiceTest` existente sigue pasando (el wrapper mantiene firma y forma de retorno).
  - Test: el wrapper delega correctamente y normaliza igual que antes (`tenant_slug`, `workspace_id?`).

### B4. Extender `kuaforia-mock.php` con `get_client_context`

- **Objetivo:** el mock local solo tiene consulta REST + tools de señales. Agregar la tool `get_client_context` (JSON-RPC `tools/call`) con el **contrato real de P3** (no aproximación): `content[0].text` como **string JSON** con `data.tenant.slug`/`data.tenant.name`, y 401 con JSON plano para keys inválidas.
- **Aspectos técnicos:** mismo bloque `if ($path === '/api/v1/mcp')` — agregar `get_client_context` al `match ($name)` existente.
- **QA/Review:** smoke manual: `php -S 127.0.0.1:8080 kuaforia-mock.php` + tinker con `resolveIdentity`.

### B5. Conectar el registro de conectores al contenedor

- **Objetivo:** en `AppServiceProvider`, el binding deja de ser hardcodeado a Kuaforia y lee del registro de conectores: resolver las 3 interfaces desde `config('kuestion.connectors.<tipo>')`.
- **Aspectos técnicos:**
  - Patrón: un `ConnectorRegistry` (clase liviana) que, dado `connector_type`, devuelve las clases configuradas. Mantener los singletons actuales como default para Kuaforia (no romper tests).
  - En la config (A4): `identity_resolver` → `App\Services\IdentityResolver` (B2); `rag_provider`/`signal_provider` → clases existentes en `app/Services/` (A2).
  - Los consumidores actuales piden `RagProviderInterface::class` — el binding sigue resolviendo Kuaforia (único conector), pero ahora leyendo la config.
- **QA/Review:**
  - **Automático:** test que `app(RagProviderInterface::class)` es `KuaforiaService`, `app(IdentityResolverInterface::class)` es `App\Services\IdentityResolver`, `app(StructuredSignalProviderInterface::class)` es `KuaforiaMcpProvider`.
  - Suite completa verde.

### Notas de implementación (Fase B ejecutada — M32)

**Archivos (6 nuevos + 6 modificados):**
- **B1** `app/Contracts/IdentityResolverInterface.php` + `app/Services/ResolvedIdentity.php` — DTO inmutable `readonly` (tenantSlug, tenantName?, workspaceId?, raw).
- **B2** `app/Services/IdentityResolver.php` — contrato P3 aplicado: `POST /api/v1/mcp` landlord, JSON-RPC `tools/call` con `get_client_context`; `content[0].text` como **string JSON** (doble decode); 401 con **JSON plano** → `KuaforiaMcpException(..., 401)`; sin `data.tenant` → excepción clara; `workspace_id` null (P2). La tool NO entra a `mcp_tools` (A5).
- **B3** `KuaforiaService::resolveTenantFromApiKey()` → wrapper que delega en `IdentityResolverInterface` y **convierte `KuaforiaMcpException` a `KuaforiaException`**; la vía REST de validación quedó eliminada del código (la config `tenant_resolution` se limpia en G4).
- **B4** `kuaforia-mock.php` — tool `get_client_context` con el contrato real (content[0].text string JSON con data.tenant) + 401 plano para keys que no empiezan con `kfr_`.
- **B5** `app/Services/ConnectorRegistry.php` (ficha por type + `classFor(interface)`) + `AppServiceProvider`: bindings de las 3 interfaces leen del registro; `KuaforiaService` sigue singleton por clase.
- **Tests actualizados:** `TenantConnectionTest` (fakes de `/api/validate-api-key` → contrato MCP P3), `ConnectorRegistryTest` (+2: resolución del contenedor + registry), `KuaforiaServiceTest` (+2: delegación + 401→KuaforiaException).

**Desviaciones de este plan (con razón):**
1. **El wrapper B3 convierte la excepción a `KuaforiaException`** (el plan no lo especificaba). Razón: `Register`/`Settings` capturan `KuaforiaException` para mostrar el error en la UI; propagar `KuaforiaMcpException` rompería el manejo de error del registro/settings.
2. **`TenantConnectionTest` se actualizó en esta fase** (el plan lo ubicaba en G3): B3 cambia la vía de resolución, por lo que los fakes del endpoint REST viejo dejaron de matchear — actualizarlos ahora es parte de B.

**QA/Review ejecutado:**

| Criterio (plan) | Cómo se verificó | Resultado |
|---|---|---|
| B1 DTO | `ResolvedIdentityTest` (2 tests: valores + defaults null) | ✅ |
| B2 body JSON-RPC correcto (tools/call, get_client_context, Bearer de la credencial) | `test_resolve_identity_sends_jsonrpc_tools_call_with_credential_key` | ✅ |
| B2 content[0].text string JSON → normaliza data.tenant.slug/name | Mismo test + smoke real contra el mock (tinker: `Ispend (ispend)`, workspace null) | ✅ |
| B2 401 plano (rompe el sobre JSON-RPC) | `test_resolve_identity_handles_flat_401_json` (code 401 + mensaje) + smoke curl HTTP 401 | ✅ |
| B2 sin data.tenant → excepción clara | `test_resolve_identity_throws_without_tenant_in_response` | ✅ |
| B3 wrapper mantiene firma/forma | `KuaforiaServiceTest`: delega y normaliza (`tenant_slug`, `workspace_id`) | ✅ |
| B3 401 → KuaforiaException (UI intacta) | `test_resolve_tenant_from_api_key_converts_401_to_kuaforia_exception` + `TenantConnectionTest` verde (4 tests) | ✅ |
| B5 bindings desde el registro | `ConnectorRegistryTest::test_container_resolves_interfaces_from_registry` (3 interfaces) + `test_registry_returns_ficha_and_resolves_classes` | ✅ |
| Regresión (alerta roja) | Suite completa: **124 tests (344 assertions)** — 113 previos + 11 nuevos; REST/hash/job intactos | ✅ |
| Estilo | `vendor/bin/pint` PASS | ✅ |

---

## Fase C — Flujo de conexión del usuario (Bloque 6 → repositorios)

**Objetivo:** el registro y `/settings` crean y gestionan `repositories` en lugar de escribir columnas en `users`. Aplica las decisiones de UX §6.1, 6.2, 6.3, 6.10, 6.11.

**Prioridad:** P1.

### C1. `Register` crea un repositorio

- **Objetivo:** al registrarse, en lugar de guardar `users.tenant_slug` + `users.kuaforia_api_key`, crear el primer `Repository` (`name` autogenerado con la fórmula de **P7**: `"{display_name} - {tenant_name}"` truncado a 100 caracteres — p.ej. "Kuaforia - Ispend"; `status=active`, `is_default=true`, `credential={'api_key': ...}`) (§7.1, flujo 5.1).
- **Aspectos técnicos:**
  - `Register::updatedKuaforiaApiKey()` ya resuelve el tenant en vivo (debounce 700ms) — se **adapta** (nota B4), no se crea de cero: resuelve vía `IdentityResolverInterface` (MCP), muestra `tenant_name (tenant_slug)` (§6.2) y persiste en `repositories`.
  - En `register()`: dentro de la transacción de creación del usuario, crear el repositorio (garantiza que usuario sin repo no exista).
  - El flag `team_dashboard_access` y `email_notifications` de `users` se mantienen (no dependen del modelo de conectores).
- **QA/Review:**
  - **Automático:** test de registro → usuario creado + 1 repositorio `active`/`is_default` con `name` "Kuaforia - Ispend" y `credential` cifrada; `users.tenant_slug`/`users.kuaforia_api_key` **no** se escriben (null).
  - Regresión: `TenantConnectionTest` existente se actualiza (ver G3) — el criterio "no romper tests previos" aplica a la lógica REST/hash, no a estas columnas que el propio diseño elimina.

### C2. `/settings`: gestión de repositorios

- **Objetivo:** sección "Conexión con Kuaforia" pasa a listar repositorios con su estado; permite editar credencial (re-valida con `resolveIdentity`), desconectar (→ `revoked`, con confirmación §6.10) y ver tenant resuelto (§6.2). Con un solo repositorio activo, se mantiene el formulario plano sin lista ni nombre (§6.3).
- **Aspectos técnicos:**
  - `Settings` gana métodos: `updateRepositoryCredential()`, `disconnectRepository()` (decisión **P5**: **se permite desconectar el único repositorio activo** → queda `revoked` y el sistema cae en el estado "0 repos activos" ya diseñado: bloqueo en creación + onboarding del feed vacío) y resaltado del repo afectado vía `?highlight=<repo_id>` + anillo/borde visual (decisión **P12**).
  - Nombre autogenerado solo visible/editable con >1 repositorio (§6.3, 5.3).
  - Los mensajes de error distinguen 401 (key inválida → sugerir actualizar) de 503 (servicio no disponible) (§6.11).
- **QA/Review:**
  - **Automático:** test: editar key válida → `credential` actualizada + `resolved_tenant_slug` refrescado; key inválida → error, sin cambios; desconectar → `status=revoked` tras confirmar.
  - **Manual (UX):** flujo 5.1 y 5.2 en navegador con mock: primera conexión sin campo nombre; segundo repositorio muestra nombres.

### C3. Ayuda contextual y confirmación de tenant

- **Objetivo:** §6.1 (enlace "¿Cómo obtengo mi API key?" específico al conector) y §6.2 (mostrar `tenant_name (tenant_slug)` tras validar — "✅ Conectado a Ispend (ispend)").
- **Aspectos técnicos:** el enlace de ayuda sale del registro de conectores (`auth_fields[0].hint` + link en la ficha); el texto de éxito usa `ResolvedIdentity->tenant_name` y `tenant_slug` juntos.
- **QA/Review:** **manual** en navegador; **automático** donde aplique (assert del texto renderizado con `Livewire::test`).

### C4. `KuaforiaKeyPrompt` adaptado

- **Objetivo:** (decisión **B1**) el banner opcional (6.7 de Fase 1) hoy muestra si `blank($user->kuaforia_api_key)`. Pasa a evaluar "el usuario no tiene repositorios" (`$user->repositories->isEmpty()`).
- **QA/Review:** test: usuario sin repos → banner visible; con repos → oculto.

### C5. Onboarding post-registro con repositorio

- **Objetivo:** (decisión **B2**) `resources/views/auth/onboarding.blade.php` deja de leer `users.tenant_slug` + `services.kuaforia.tenants`; lee `resolved_tenant_name` del **primer repositorio** del usuario (o el `is_default`). Sin repositorios → mensaje genérico **"Conectá tu fuente de conocimiento"** con enlace a `/settings`.
- **QA/Review:** **automático:** test: usuario con repo → muestra el `tenant_name` del repo; sin repos → mensaje genérico + enlace. **Manual:** flujo de registro en navegador con mock.

### Notas de implementación (Fase C ejecutada — M33)

**Archivos (10 modificados + 3 nuevos):**
- **C1** `Register`: boot con `IdentityResolverInterface` + `ConnectorRegistry`; `updatedKuaforiaApiKey` resuelve con `resolveIdentity` y muestra `tenant_name (slug)` (§6.2); `register()` crea usuario + primer repositorio en la misma transacción (nombre P7 "Kuaforia - Ispend" vía `Repository::defaultName()`); **ya no escribe `users.tenant_slug`/`kuaforia_api_key`**.
- **C2** `Settings`: reescrito con gestión de repositorios — computed `repositories` (invalidada con `unset($this->repositories)`); `saveRepository` (crea el primero si no hay / actualiza el seleccionado o el default; 401 → key inválida; revive `invalid`/`revoked` a `active`); `startDisconnect`/`cancelDisconnect`/`disconnectRepository` (P5); `toggleEdit` por repo; `highlightId` desde `?highlight=` (P12). Vista: formulario plano si 0 repos o 1 activo (§6.3); lista con nombre/tenant/badge de estado + acciones si hay varios o alguno inactivo.
- **C3** `x-connector-help` (hint + link `help_url` desde la ficha) en registro y settings; el registro muestra "Conectado a Ispend (ispend)".
- **C4** `KuaforiaKeyPrompt` evalúa `repositories()->doesntExist()` (B1); vista con **root tag siempre** (Livewire 4 exige root).
- **C5** onboarding lee `resolved_tenant_name` del primer repo; sin repos → "Conectá tu fuente de conocimiento" + link a `/settings`.
- **Config:** `help_url` (null) agregado a la ficha Kuaforia.
- **`Repository::defaultName(displayName, tenantName)`** — helper compartido (P7, truncado 100) usado por Register y Settings.
- **Tests:** `TenantConnectionTest` reescrito para repositorios (6 tests) + `KuaforiaKeyPromptTest` (3) + `OnboardingTest` (2) nuevos.

**Desviaciones de este plan (con razón):**
1. **`KuaforiaKeyPrompt` renderiza siempre un root tag** (antes `@if ($visible)` sin raíz): Livewire 4 falla al renderizar un componente sin root cuando `visible=false` (expuesto por los tests nuevos). El banner se oculta con el atributo `hidden` en vez de no renderizar.
2. **Invalidación de la computed con `unset($this->repositories)`** en lugar de `forgetComputed()` (no existe en Livewire 4; el hook `__unset` invalida la caché).
3. **`TenantConnectionTest` se reescribió en C** (el plan lo ubicaba en G3): es el QA de C1/C2 — los asserts pasan de columnas de `users` a `repositories`.
4. **`help_url` sin URL real** (null en la ficha): el enlace "¿Cómo obtengo mi API key?" se renderiza solo cuando exista el link oficial de Kuaforia.

**QA/Review ejecutado:**

| Criterio (plan) | Cómo se verificó | Resultado |
|---|---|---|
| C1 registro crea repo (active, is_default, name P7, credential cifrada) | `test_register_creates_user_with_default_repository`: repo "Kuaforia - Ispend", `users.tenant_slug`/`kuaforia_api_key` null, credential cifrada en reposo | ✅ |
| C1 key inválida bloquea registro | `test_register_rejects_invalid_key` (resolvedTenantSlug null + keyError) | ✅ |
| C2 editar key válida → credential + tenant refrescados | `test_settings_updates_repository_credential` | ✅ |
| C2 key inválida → error sin cambios | `test_settings_rejects_invalid_key_without_changes` | ✅ |
| C2 desconexión (P5: único activo permitido) | `test_settings_disconnects_the_only_active_repository` → `revoked` | ✅ |
| C2 primera conexión desde settings | `test_settings_creates_first_repository_when_user_has_none` | ✅ |
| C3 UX (nombre + slug, ayuda desde ficha) | Vista registro: "Conectado a Ispend (ispend)" + `x-connector-help` | ✅ |
| C4 prompt por repos (B1) | `KuaforiaKeyPromptTest` (3 tests: visible sin repos, oculto con repos, dismiss persistente) | ✅ |
| C5 onboarding por repos (B2) | `OnboardingTest` (2 tests: nombre del repo / mensaje genérico + link) | ✅ |
| Regresión (alerta roja) | Suite completa: **131 tests (369 assertions)** — 124 previos + 7 nuevos; REST/hash/job intactos | ✅ |
| Estilo/vistas | `vendor/bin/pint` PASS + `php artisan view:cache` OK | ✅ |

---

## Fase D — Consulta RAG con repositorio (Bloque 3/7 impacto)

**Objetivo:** la consulta (`consult()`), creación de preguntas, follow-up y job resuelven tenant/credencial desde el repositorio de la pregunta/usuario, y el job distingue 401 vs 503 para actualizar `status`.

**Prioridad:** P1.

### D1. `consult()` resuelve tenant desde el repositorio

- **Objetivo:** `KuaforiaService::consult()` deja de depender de `auth()->user()->tenant_slug` para el fallback. Recibe el tenant explícito (ya acepta `?string $tenantSlug`) — los llamadores lo obtienen del repositorio.
- **Aspectos técnicos:** la firma no cambia (ya tiene el parámetro opcional). El fallback interno `$tenantSlug ??= auth()->user()?->tenant_slug` se reemplaza por excepción clara si no llega tenant (el job y los llamadores siempre lo pasan). La key de consulta sigue siendo la compartida hasta resolver 8.1 (G6).
- **QA/Review:**
  - **Automático:** test: `consult('pregunta', tenantSlug: 'X')` construye la URL con `X` (ya existe `test_consult_builds_url_with_tenant_slug` — se mantiene); sin tenant → excepción con mensaje claro.
  - Regresión: REST/hash intacto (diff acotado al fallback).

### D2. `CreateQuestion` + `QuestionController::store` con repositorio

- **Objetivo:** §5.4/6.6/6.7: con 1 repo activo → sin selector, se usa implícito; con 2+ → selector obligatorio con `is_default` preseleccionado; con 0 → mensaje de bloqueo con enlace a `/settings` (§6.5/6.12). La pregunta creada guarda `repository_id`.
- **Aspectos técnicos:**
  - `CreateQuestion` gana prop `repositoryId` (default: el `is_default` activo); `save()` pasa el tenant del repo seleccionado a `consult()`, setea `repository_id` en el `Question::create` y actualiza `last_used_at` (decisión **P9**).
  - `QuestionController::store`: mismo criterio (request trae `repository_id` validado contra los repos activos del usuario) + `last_used_at`.
  - `repository_id` es nullable desde A2 (desviación documentada en Fase A); **D2 lo pasa a NOT NULL** (migración `change`) en el mismo commit donde los flujos de creación lo setean siempre.
- **QA/Review:**
  - **Automático:** test: 1 repo → pregunta con ese `repository_id`; 2 repos → selector valida; 0 repos → bloqueo con mensaje y enlace; repo `invalid`/`revoked` no aparece (decisión **P11**: solo `active` en el selector, sin aviso adicional — el mensaje de bloqueo §6.12 cubre el caso todos-invalid).
  - Regresión: `CreateQuestionTest`/`QuestionApiTest` existentes (se actualizan los que crean preguntas para incluir repo — G3).

### D3. `QuestionDetail::askFollowUp` con el repositorio de la pregunta

- **Objetivo:** el follow-up consulta con el tenant del `repository` de la pregunta (no del usuario). **No actualiza `last_used_at`** (decisión **P9**: solo creación de pregunta y job).
- **QA/Review:** test: follow-up usa tenant del repo de la pregunta; regresión del flujo de follow-up.

### D4. `CheckQuestionUpdatesJob`: 401 vs 503 → `status` del repositorio

- **Objetivo:** §7.4 + §6.11: el job distingue el código HTTP de la consulta. 401 → repo a `invalid` (key revocada) + notificación; 503/timeout → repo sigue `active`, reintento con backoff existente. El tenant y la credencial salen del repositorio de la pregunta (`$question->repository`), no de `$question->user->tenant_slug`.
- **Aspectos técnicos:**
  - `KuaforiaException` ya transporta `code` (default 502). `KuaforiaService::consult()` debe lanzar con el status real (`throw new KuaforiaException($msg, $response->status())`).
  - En el job: `try/catch` alrededor de `consult()` → `catch (KuaforiaException $e)` con `$e->getCode() === 401` → `$repository->update(['status' => 'invalid'])` + `last_validated_at` + `last_used_at` (decisión **P9**); otros códigos → log + continue (comportamiento actual). (Decisión **P10**: el 401 marca el repo `invalid` pero **no cuenta para el circuit breaker** — el breaker es por servicio, no por repo, y se mantiene independiente.)
  - Mantener: `lockForUpdate`, numeración de versiones, `response_hash`, notificación dentro de la transacción — **intactos**.
- **QA/Review:**
  - **Automático:** test con `Http::fake()`: respuesta 401 → repo `invalid`; 503 → repo sigue `active` y el job reintenta (backoff configurable en test); payload de versión/notificación idéntico al actual.
  - Regresión: `CheckQuestionUpdatesJobTest` existente (se actualiza para crear repo — G3); el diff del job NO debe tocar la transacción de versiones.

### Notas de implementación (Fase D ejecutada — M34)

**Archivos (12 modificados + 1 migración + 3 tests nuevos):**
- **D1** `KuaforiaService::consult()` — sin fallback a `auth()->user()->tenant_slug` (excepción clara si no llega tenant); **401 no cuenta para el circuit breaker** (P10: el breaker es por servicio, no por repo); la excepción transporta el status HTTP real para que el job distinga 401 de otros códigos.
- **Interfaz** `RagProviderInterface::consult` gana `?string $tenantSlug = null` (3er param opcional) + `FakeRagProvider` actualizado (registra `tenant_slug`).
- **Migración** `2026_08_15_000003_make_repository_id_not_null_on_questions_table` — aplica el NOT NULL diferido de A2: **drop FK → change → re-add FK** (MySQL error 1832: no permite MODIFY una columna con FK). `QuestionFactory` con default `repository_id => Repository::factory()` (los tests que necesitan pertenencia lo pasan explícito).
- **D2** `CreateQuestion` — `repositoryId` (default: `is_default` activo), computed `repositories` (solo `active`, P11), bloqueo con 0 activos (§6.5/6.12, vista con aviso + link a /settings), selector con 2+ (vista), consulta con el tenant del repo, `repository_id` en el create + `last_used_at` (P9). `QuestionController::store` — `resolveActiveRepository()` (el pedido validado contra los repos activos o el default; 0 activos → 422), tenant + `repository_id` + `last_used_at`. `StoreQuestionRequest` + `repository_id` nullable.
- **D3** `QuestionDetail::askFollowUp` — tenant del `repository` de la pregunta; sin repo activo → mensaje de reparación; **no** actualiza `last_used_at` (P9).
- **D4** `CheckQuestionUpdatesJob` — `with('user', 'repository')`; tenant de `$question->repository->resolved_tenant_slug`; `catch (KuaforiaException)` con `code === 401` → repo `invalid` + `last_validated_at` + `last_used_at` (P9); otros códigos → log + continue (backoff existente); `last_used_at` al consultar con éxito. **La transacción de versiones (`lockForUpdate`, numeración, `response_hash`, notificación) quedó intacta.**
- **Tests:** `CreateQuestionRepositoryTest` (4: single active, selector 2+ con preselección y selección, P11 solo active, bloqueo 0), `CheckQuestionUpdatesJobStatusTest` (4: 401 → invalid, 503 → active, 401 no pausa el breaker, tenant del repo en la URL), `QuestionDetailFollowUpTest` (2: tenant del repo + bloqueo inactivo, sin last_used_at), `QuestionApiTest` +1 (422 sin repos activos), `RagProviderInterfaceTest` actualizado (repo + assert tenant + repository_id + last_used_at).

**Desviaciones de este plan (con razón):**
1. **`RagProviderInterface::consult` gana `?string $tenantSlug`** (el doc de referencia decía "tenant fuera de la interfaz"): los consumidores están tipados por interfaz (`CreateQuestion`, `QuestionController`, `QuestionDetail`, el job) y con el modelo de repositorios necesitan pasar el tenant resuelto — agregar un param opcional preserva la testabilidad con `FakeRagProvider` sin romper llamadores.
2. **`repository_id` NOT NULL se aplicó en D2** (como documentaba A2 → D2): con los flujos de creación seteándolo siempre, la columna pasa a ser obligatoria.

**Hallazgo de QA (no previsto):** `RepositoryMigrationTest` (backfill) rompía la suite al agregarse la migración 000003: `migrate:rollback --step 1` revertía la migración equivocada (el NOT NULL), la re-migración fallaba ("Data truncated") y dejaba la BD de test corrupta (sin FK + datos commiteados) que contaminaba los tests siguientes (RepositoryTest y TeamDashboardTest). Se corrigió con `--step 2` (revierte A2 + D2).

**QA/Review ejecutado:**

| Criterio (plan) | Cómo se verificó | Resultado |
|---|---|---|
| D1 consult con tenant explícito; sin tenant → excepción | `KuaforiaServiceTest` existente (URL con tenant + excepción) | ✅ |
| D1 401 no cuenta para el breaker (P10) | `test_401_does_not_trigger_circuit_breaker_pause` (3 corridas → sin pause) | ✅ |
| D2 1 repo → pregunta con ese repository_id + last_used_at (P9) | `CreateQuestionRepositoryTest` + `RagProviderInterfaceTest` (assert repo id, tenant, last_used_at) | ✅ |
| D2 2+ repos → selector valida (preselección + selección) | `test_selector_preselects_default_and_validates_selection` (tenant qubeka al elegir el 2°) | ✅ |
| D2 0 repos → bloqueo con mensaje y enlace | `test_blocks_without_active_repositories` + `QuestionApiTest` (422) | ✅ |
| D2 P11: solo active en el selector | `test_selector_only_offers_active_repositories` (1 de 3) | ✅ |
| D3 follow-up con tenant del repo de la pregunta | `QuestionDetailFollowUpTest::test_follow_up_uses_question_repository_tenant` (qubeka) | ✅ |
| D4 401 → repo invalid (+ last_validated_at + last_used_at) | `test_job_marks_repository_invalid_on_401` | ✅ |
| D4 503 → repo sigue active, reintento | `test_job_keeps_repository_active_on_service_error` | ✅ |
| D4 payload/transacción de versiones intacta | `CheckQuestionUpdatesJobTest` + `CheckQuestionUpdatesJobSignalsTest` verdes sin cambios | ✅ |
| Regresión (alerta roja) | Suite completa: **142 tests (402 assertions)** — 131 previos + 11 nuevos; REST/hash/job intactos | ✅ |
| Estilo/vistas | `vendor/bin/pint` PASS + `php artisan view:cache` OK | ✅ |

---

## Fase E — Señales y dashboard con repositorios (Bloque 8/12 impacto)

**Objetivo:** `KuaforiaMcpProvider` usa la credencial del repositorio (cierra Hallazgo 2), el job enriquece con el `workspace_id` resuelto del repo, y `TeamDashboard` agrupa por `repositories.resolved_tenant_slug`.

**Prioridad:** P2 (D4 debe estar para no pisar el job).

### E1. `KuaforiaMcpProvider` acepta credencial

- **Objetivo:** §7.2: los métodos de señales reciben la credencial del repositorio (o se instancia por repo) en lugar de usar `mcp_api_key` global. **Cierra el Hallazgo 2** (superficie de confianza de la key compartida).
- **Aspectos técnicos:**
  - Opción recomendada: cada método gana un parámetro `array $credential` (o el provider se construye con la credencial). Mantener un constructor sin credencial que use la config global como fallback (no romper tests existentes de `KuaforiaMcpProviderTest`).
  - La URL del MCP sigue siendo fija (config).
- **QA/Review:**
  - **Automático:** test: señales envían `Authorization: Bearer <key del repo>` (assert del header), no la compartida; sin credencial → fallback a config (regresión).

### E2. Job enriquece con `resolved_workspace_id` del repo

- **Objetivo:** §7.2: `collectSignals()` recibe la credencial y el workspace del repositorio de la pregunta. Si `resolved_workspace_id` está, se usa; si no, fallback `workspace_map` (como hoy); degradación con gracia intacta.
- **Aspectos técnicos:** el job ya resuelve `$question->repository` en D4 — reutilizar esa carga. `try/catch (\Throwable)` + `Log::warning` se mantienen.
- **QA/Review:** **automático:** test: repo con `resolved_workspace_id` → señales llamadas con ese id y la key del repo; sin workspace → skip silencioso (mismo comportamiento actual).

### E3. `TeamDashboard` agrupa por el tenant del repo `is_default` del usuario

- **Objetivo:** (decisión **P13 — corregida**) §7.3: el agregado pasa de `User::where('tenant_slug', ...)` a "repositorios con el mismo `resolved_tenant_slug` cruzando usuarios", **restringido al tenant del repositorio `is_default` del usuario actual**. Si el usuario tiene repos de varios `resolved_tenant_slug`, **NO se mezclan métricas de tenants distintos** en un solo número (mostraría organizaciones reales distintas combinadas sin pedido explícito). Sin selector de tenant en esta versión (agregable a futuro).
- **Aspectos técnicos:**
  - `$defaultRepo = auth()->user()->repositories()->where('is_default', true)->first()` → `Repository::where('resolved_tenant_slug', $defaultRepo->resolved_tenant_slug)->pluck('user_id')` → preguntas de esos usuarios (o join directo `questions → repositories`).
  - Sin repo `is_default` (0 repos activos) → el dashboard degrada con el mensaje de conexión (mismo patrón de §6.5), no falla.
  - El gate `team_dashboard_access` se mantiene en `users`.
- **QA/Review:**
  - **Automático:** test: 2 usuarios con repos del mismo `resolved_tenant_slug` → el dashboard del usuario A muestra las preguntas de ambos; usuario con otro slug → aislado; **usuario con 2 repos de tenants distintos → solo muestra el del `is_default` (no mezcla)**; sin repo → degradación con mensaje; gate 403 intacto.

---

## Fase F — Estado del repositorio visible en la UI (UX §6.4, 6.9)

**Objetivo:** los usuarios ven el estado de su conexión sin entrar a `/settings`: badge en preguntas existentes y alerta en el header.

**Prioridad:** P2.

### F1. Badge de estado del repo en preguntas (feed + detalle)

- **Objetivo:** §6.9/5.5: `active` → nada; `invalid` → badge "Conexión inactiva" con enlace a `/settings`; `revoked` → badge "Desconectado" sin acción de reparación.
- **Aspectos técnicos:** reutilizar el patrón de badge existente (`x-badge` con variantes de color — ya se usa en el timeline/feed para "sin revisar"). Cargar `repository` con la pregunta (eager load) para no N+1.
- **QA/Review:**
  - **Automático:** test: pregunta con repo `invalid` renderiza el badge con enlace; `revoked` el badge sin acción; `active` sin badge.
  - Regresión: `question-card`/`question-detail` (sin tocar su lógica).

### F2. Indicador en el header

- **Objetivo:** §6.4: cuando hay un repo `invalid`, badge de advertencia junto al menú de usuario; clic → `/settings` con el repo afectado resaltado.
- **Aspectos técnicos:** componente Livewire liviano (o dentro del layout) que consulta `auth()->user()->repositories()->where('status','invalid')->exists()`; oculto si no hay. Pasar `?highlight=<repo_id>` a `/settings` para el resaltado (Fase C2).
- **QA/Review:**
  - **Automático:** test: usuario con repo `invalid` → el header renderiza el indicador; sin `invalid` → no.
  - **Manual:** navegador con mock (marcar repo inválido en BD y recargar).

---

## Fase G — Limpieza, tests y cierre

**Objetivo:** eliminar columnas obsoletas de `users`, actualizar factories/seeders/tests, limpiar config y resolver condicionalmente las preguntas de Kuaforia.

**Prioridad:** P2 (última).

### G1. Migración: eliminar `users.tenant_slug` y `users.kuaforia_api_key`

- **Objetivo:** §3.3. Una vez que ningún código lee esas columnas (C, D, E, F hechas), eliminarlas.
- **Aspectos técnicos:** migración `dropColumn` de ambas (con `down()` que las restaura — nullable). Limpiar `fillable`/casts de `User`. **Verificar con grep que no queda ningún `->tenant_slug` / `->kuaforia_api_key` en `app/` antes de migrar.**
- **QA/Review:** `migrate:fresh --seed` verde; suite completa verde.

### G2. Factories y seeders

- **Objetivo:** (decisión **B5**) `UserFactory` deja de setear `tenant_slug` (default 'ispend'); crear `RepositoryFactory`; `AdminUserSeeder` **crea un repositorio para el admin** (con su key de Kuaforia).
- **QA/Review:** suite completa verde (los tests que usan `User::factory()` con tenant implícito ahora crean repo explícito).

### G3. Actualizar tests existentes

- **Objetivo:** `TenantConnectionTest`, `TeamDashboardTest`, `RagProviderInterfaceTest`, `CheckQuestionUpdatesJobTest`, `CreateQuestionTest`, `QuestionApiTest` y cualquier test que referencie `tenant_slug`/`kuaforia_api_key` de `users` pasan a usar repositorios (factory).
- **Aspectos técnicos:** patrón: `$repo = Repository::factory()->create(['user_id' => $user->uuid])`; los asserts dejan de mirar `users.*` y miran `repositories.*`.
- **QA/Review:** suite completa **100% verde sin tests en `@group skip`**; `git diff --stat tests/` acotado a los archivos esperados.

### G4. Limpieza de config

- **Objetivo:** (decisiones **A1, B3, P8, P2**) eliminar `services.kuaforia.tenant_resolution` (P8: sin flag de fallback — un fallback a un endpoint no confirmado no es una red de seguridad real), eliminar `services.kuaforia.tenants` (B3: el nombre sale siempre de `repositories.resolved_tenant_name`) y **eliminar la vía REST de validación de identidad** (`/api/validate-api-key`; también `/api/v1/cli/health`) de `KuaforiaService` (A1). `workspace_map` **se mantiene** como fallback documentado (P2: `workspace_id` no viene hoy en `get_client_context`); se eliminará solo cuando Kuaforia lo devuelva (G7).
- **QA/Review:** tinker verifica `config()` limpio; suite verde.

### G5. Documentación

- **Objetivo:** actualizar `docs/kuestion-referencia-plataforma.md` (esquema de BD: `repositories`, `questions.repository_id`, `users` sin tenant/key) y `docs/auth-multi-user-feature.md` si corresponde. Documentar el flujo de conectores en `README` o doc de referencia.
- **QA/Review:** revisión manual de los docs (sin tests).

### G6. (Condicional) Pregunta 8.1: `kfr_` del usuario para consultas REST

- **Objetivo:** si Kuaforia confirma que la `kfr_` autentica `POST /api/consult/{tenant_slug}`, `consult()` usa la credencial del repositorio y **se elimina la key compartida** (`services.kuaforia.api_key` deja de usarse para consultas).
- **QA/Review:** test con `Http::fake()`: header `Authorization: Bearer <key del repo>`; sin key compartida configurada, la consulta funciona.

### G7. (Condicional) Pregunta 8.2: `workspace_id` por defecto

- **Objetivo:** (decisiones **P2/P3**) si en el futuro `get_client_context` devuelve `workspace_id`, `resolveIdentity` lo persiste en `repositories.resolved_workspace_id` y el job lo usa directamente; **se elimina el fallback `workspace_map`**. **Hoy confirmado que NO viene en la respuesta (P3)** — esta tarea queda condicional a una actualización futura de Kuaforia, no al arranque de esta implementación.
- **QA/Review:** test: repo con `resolved_workspace_id` → señales sin `workspace_map`; config `workspace_map` eliminada.

---

## Resumen de fases y esfuerzo

| Fase | Contenido | Prioridad | Esfuerzo | Depende de |
|---|---|---|---|---|
| **A** | Modelo de datos (repositories + repository_id + modelo + config conectores) | P0 | M (~2–3 d) | — |
| **B** | IdentityResolverInterface + clase dedicada IdentityResolver (contrato P3) + wrapper + registro en contenedor | P0 | M (~2 d) | A |
| **C** | Flujo de conexión (Register, /settings, UX 6.1/6.2/6.3/6.10/6.11, KuaforiaKeyPrompt, onboarding) | P1 | L (~3–4 d) | A, B |
| **D** | Consulta con repositorio (consult, create, follow-up, job 401/503) | P1 | L (~3–4 d) | A |
| **E** | Señales con credencial del repo + dashboard por tenant del repo `is_default` (P13) | P2 | M (~2 d) | D |
| **F** | Badge de estado en preguntas + indicador de header | P2 | M (~1.5–2 d) | D |
| **G** | Limpieza (drop columnas, vía REST identidad, factories, tests, config, docs) + condicionales 8.1/8.2 | P2 | M (~2–3 d) | C, D, E, F |

**Total estimado: ~16–20 días de desarrollo** + coordinación externa con Kuaforia (no bloqueante salvo G6/G7).

---

## Plan de pruebas de regresión (alerta roja)

| Comportamiento que NO debe romperse | Cómo se verifica |
|---|---|
| `POST /api/consult/{tenant_slug}` síncrono + parsing `{answer, confidence, sources, conversation_id}` | `KuaforiaServiceTest` + smoke con mock; diff de `KuaforiaService::consult()` acotado al fallback de tenant |
| `ChangeDetector` (SHA-256 + similitud coseno) — hash y diff | `ChangeDetectorTest` y `DiffTest` sin cambios |
| Transacción del job: `lockForUpdate`, numeración de versiones, `response_hash`, notificación dentro de transacción | `CheckQuestionUpdatesJobTest` + diff del job revisado línea por línea (solo origen de tenant/credencial + catch 401) |
| Filtros del feed, búsqueda FULLTEXT, tags, relaciones, feedback, timeline | Suite completa (105+ tests) verde en cada fase |
| Aislamiento por usuario (scoped `current_user_id()`) | Tests de scope existentes; excepción documentada: TeamDashboard cruza por el `resolved_tenant_slug` del repo `is_default` del usuario (P13) |

**Método por fase:** cada fase se cierra con suite completa verde + `git diff --stat` acotado a los archivos esperados + smoke manual contra `kuaforia-mock.php` donde aplique. Nada se da por cerrado sin su evidencia.

---

## Eficiencia de código/tokens (reutilización)

- **Patrón JSON-RPC:** la implementación de `get_client_context` (B2) reutiliza el patrón ya probado de `KuaforiaMcpProvider` (tools/call + normalize + excepciones). **No** crear un segundo cliente HTTP MCP desde cero.
- **DTO:** `ResolvedIdentity` es el único DTO nuevo; las señales siguen devolviendo `array` (decisión §4.5 — no reabrir).
- **Bindings:** el `ConnectorRegistry` (B5) es la única pieza de "infraestructura" nueva; el resto es mover datos de origen.
- **Badges UI:** reutilizar `x-badge` y los estilos de estado ya existentes (F1/F2) — no inventar nuevos componentes de estado.
- **División de tareas:** cada tarea de este plan es chica y verificable en aislamiento (una migración, un método, un test). No hay cambios grandes de una sola vez — el único bloque "grande" es C2 (gestión en /settings), divisible en sub-tareas: listar → editar credencial → desconectar.
- **Contexto mínimo para la IA generadora:** al implementar cada tarea, pasar solo el archivo destino + el archivo de referencia relevante (este plan + doc de referencia §correspondiente), no todo el repo.

---

## Salida esperada por fase (formato de revisión)

Al cerrar cada fase, generar el resumen con:
1. **Resumen ejecutivo:** qué se hizo y cómo se verificó (con IDs de tarea).
2. **Evidencia por criterio de aceptación:** tabla criterio → comprobación → ✅ (tests + smoke + diff).
3. **Desviaciones** respecto a este plan (con razón).
4. **Riesgos no previstos** y **preguntas abiertas nuevas** (ir al documento de preguntas).
5. Estado de la suite (tests/assertions) al inicio y al cierre de la fase.
