# Kuestion — Fase 2 · Salida para revisión (Bloques 7–10)

> **Versión:** 1.0 | **Fecha:** 2026-08-14 | **Fuente:** `kuestion-fase2-plan-implementacion.md` v1.1 | **Estado:** Fase 2 implementada en su totalidad
>
> Documento de cierre siguiendo el formato del plan §5: por bloque — resumen ejecutivo, evidencia por criterio de aceptación, desviaciones, riesgos no previstos y preguntas abiertas. Autocontenido: no requiere volver al plan ni al maestro.

---

## §0 Resumen global

- **Alcance:** 4 bloques de la Fase 2 (arquitectura interna), implementados en orden 7 → 8 → 10 → 9 (9 y 10 son independientes; se hizo 10 como calentamiento, luego 9).
- **Commits:** M22 `862ef61` (Bloque 7) → M23 `3620277` (Bloque 8) → M24 `86c580d` (Bloque 10) → M25 `00e94c6` (Bloque 9). Todos pusheados a `origin/main`.
- **Suite final:** **77 tests (222 assertions) pasan** (arrancó la fase con 55/155 — Fase 1 — y sumó 22 tests/67 assertions de la Fase 2).
- **Regresión (alerta roja):** el mecanismo REST/hash/job quedó intacto. `KuaforiaService` solo ganó `implements`; `ChangeDetector`, el payload base de la notificación y la transacción del job no cambiaron (el enriquecimiento de señales es best-effort con degradación probada).
- **Hallazgo más relevante:** bug **pre-existente** en el `down()` de `add_uuid_to_users_table` que rompía **cualquier** `migrate:rollback` (ver §5.1). Corregido.
- **2 hallazgos de QA** que condicionan cómo se testea: `RefreshDatabase` es invisible para procesos hijos (`proc_open`) y MySQL < 8.0.13 no permite `default` en columnas JSON (ver §5.2 y §5.3).

---

## §1 Por bloque

### Bloque 7 — Interfaz de proveedor RAG (M22)

**Resumen ejecutivo:** se extrajo `RagProviderInterface` con un único método `consult()` que retorna el DTO existente `KuaforiaResponse` (7.1). `KuaforiaService` la implementa sin cambios de comportamiento (7.2); binding `RagProviderInterface → KuaforiaService` + singleton concreto en `AppServiceProvider` (7.3); los 4 consumidores tipados por interfaz: `QuestionController` (constructor), `CreateQuestion::save`, `QuestionDetail::askFollowUp` y `CheckQuestionUpdatesJob::handle` (7.4). Se creó el test double `tests/Fakes/FakeRagProvider` con respuestas/excepciones configurables y registro de llamadas, y un test que inyecta el fake **sin red ni `Http::fake()`** (7.5).

**Evidencia por criterio de aceptación:**

| Criterio (maestro) | Cómo se verificó | Resultado |
|---|---|---|
| Código existente funciona sin cambios (2.1) | Suite completa 55 tests (155 assertions) pasa sin modificar tests previos; `git diff` acotado a `implements`/import/binding/tipado | ✅ |
| Proveedor mock inyectable en tests (2.1) | `RagProviderInterfaceTest`: `app()->instance()` con el fake → `CreateQuestion::save` crea la pregunta con la respuesta del fake, sin red; verifica llamada y `conversationId` | ✅ |
| Interfaz mínima (2.1) | Revisión: un método, retorna `KuaforiaResponse`; el tenant queda fuera de la interfaz (detalle de Kuaforia) | ✅ |

**Desviaciones:**
1. Pint normalizó estilo pre-existente pendiente en `CreateQuestion` (early returns de una línea → bloques, separación de atributos) — solo estilo, sin cambio de lógica (suite verde lo confirma).
2. El binding literal del plan (`singleton(Interface, Clase)`) produce dos singletons del contenedor (interfaz y clase concreta). Inocuo: `KuaforiaService` es stateless (config + facades). Alternativa documentada: closure que delegue al singleton concreto, si algún día el servicio tuviera estado.

**Riesgos no previstos:** ninguno. **Preguntas abiertas nuevas:** ninguna.

### Bloque 8 — Señales estructuradas vía MCP (M23)

**Resumen ejecutivo:** `StructuredSignalProviderInterface` con 3 métodos que devuelven `array` con forma documentada (8.1); `KuaforiaMcpProvider` — cliente JSON-RPC 2.0 `tools/call` sobre `POST {mcp_url}` con `Http::timeout(15)->withToken()->post()`, normalización de `result.content[].text` y errores → `KuaforiaMcpException` nueva (8.2); config `mcp_url`/`mcp_api_key`/`mcp_tools`/`workspace_map` + binding de interfaz (8.3); `CheckQuestionUpdatesJob` enriquece la notificación con `signals` best-effort y `AnswerChangedNotification` gana el parámetro opcional `?array $signals` (8.4); 8.5 no bloquea (mapeo configurable ya cubre el ajuste de catálogo). El mock local `kuaforia-mock.php` ahora responde `/api/v1/mcp` con las 3 tools.

**Evidencia por criterio de aceptación:**

| Criterio (maestro) | Cómo se verificó | Resultado |
|---|---|---|
| Interfaz mapea a tools reales (2.2/2.3) | `KuaforiaMcpProviderTest`: body JSON-RPC con `tools/call`, Bearer y los 3 nombres (`get_workspace_health`, `get_dependency_health_report`, `get_case`) | ✅ |
| El proveedor devuelve señales estructuradas (2.3) | Tests: normalización de JSON string y texto plano; HTTP fallido / error de protocolo / `isError=true` → `KuaforiaMcpException` | ✅ |
| Notificación enriquecida sin modificar hash+similitud (2.4) | `CheckQuestionUpdatesJobSignalsTest` (3 casos): (a) con `workspace_map` → `data.signals` presente; (b) proveedor 500 → payload base idéntico (sin `signals`); (c) sin `workspace_map` → cero llamadas MCP (`Http::assertSentCount(1)`) | ✅ |
| Preparado para cambios de catálogo (2.4) | Test: tool renombrada en `mcp_tools` → se resuelve por config sin refactor | ✅ |
| Degradación con gracia obligatoria | El bloque de señales va en `try/catch (\Throwable)` → `Log::warning` + continuar; test (b) prueba la degradación explícita | ✅ |

**Desviaciones:**
1. **Enriquecimiento antes de la transacción** (el plan decía "antes de construir la notificación", que vive dentro de la transacción): se movió afuera para **no mantener el lock de fila durante una llamada HTTP de hasta 15 s**. Misma garantía de degradación, sin riesgo de concurrencia (Bloque 3 de Fase 1).
2. `handle(RagProviderInterface $kuaforia, ?StructuredSignalProviderInterface $signals = null)`: el parámetro es opcional para no romper los tests existentes que llaman `handle()` con un argumento; el binding del container lo resuelve al encolarse. **Hallazgo de QA:** la variable debe entrar en el `use` del closure de `chunk()`.
3. El job recolecta solo señales de nivel workspace (`getWorkspaceHealth` + `getDependencyHealthReport`); `getCaseDetails` queda disponible para 8.5 (el job no tiene `case_id`).
4. **8.5 sigue abierto (pendiente #2):** el mapeo configurable cubre el ajuste de catálogo; falta confirmar el contrato real contra Kuaforia. No bloquea.

**Riesgos no previstos:** ninguno de código; la dependencia externa (contrato del puente) se mantiene como pendiente coordinado. **Preguntas abiertas nuevas:** ninguna.

### Bloque 10 — Modelo de datos para contrato mínimo multi-fuente (M24)

**Resumen ejecutivo:** migración `questions` con `source_platform` (string(20), default `'kuaforia'`), `external_id` (string(64) nullable), `last_external_check` (timestamp nullable) e índices `(user_id, source_platform)` y `(external_id)` (10.1); tabla `structured_signals` con uuid PK, FK cascade a `questions`, `signal_type`, `payload` json, `detected_at`, `created_at` e índice compuesto `(question_id, signal_type, detected_at)` (10.2). Inocuidad verificada (10.3).

**Evidencia por criterio de aceptación:**

| Criterio (maestro) | Cómo se verificó | Resultado |
|---|---|---|
| Las migraciones se ejecutan sin errores | `php artisan migrate:fresh --seed --force` OK; suite completa con `RefreshDatabase` (migrate:fresh limpio) | ✅ |
| El código existente ignora las columnas | `Question` sin `fillable`/`casts` nuevos (dump verificado); suite completa sin cambios (65 tests) | ✅ |
| `structured_signals` lista para el futuro | `SHOW COLUMNS`/`SHOW INDEX` confirman esquema + FK + índice compuesto; insert manual + `forceDelete` de la pregunta → cascade elimina la señal | ✅ |
| Convenciones (10.3) | `source_platform` string con default (no enum nativo), FK cascade como `answer_versions`, `down()` reversa índices y columnas | ✅ |

**Desviaciones:** ninguna — el bloque quedó exactamente como lo definió el plan (migraciones inocuas). `structured_signals` queda **sin modelo Eloquent y sin persistencia** a propósito: la persistencia de señales es evolución futura del Bloque 8 (decisión del plan, no implementada en esta fase).

**Riesgos no previstos:** ninguno. **Preguntas abiertas nuevas:** ninguna.

### Bloque 9 — MCP Server propio de Kuestion (M25)

**Resumen ejecutivo:** tabla `agente_tokens` (uuid PK, FK `users.uuid` cascade, `token_hash` bcrypt, `scopes`, `last_used_at`, `expires_at`) + modelo `AgentToken` (9.1); `agent-token:create {user} {name}` genera `kqt_`+random(32) y lo imprime una sola vez (9.2); `mcp:serve` sirve el protocolo por stdio con autenticación por token (9.3); `app/Services/Mcp/McpServer` — clase pura `handleMessage(array): ?array` — implementa initialize/ping/tools/list/tools/call con 3 tools de solo lectura scoped por usuario (9.4/9.5); documentación en `docs/mcp-server.md` con la config de Claude Code (9.6).

**Evidencia por criterio de aceptación:**

| Criterio (maestro) | Cómo se verificó | Resultado |
|---|---|---|
| Agente externo lista preguntas de un usuario autenticado (2.5) | `McpServerTest` (10 tests): tools/list con `inputSchema`; list_questions JSON scoped; **integración stdio real** (`McpServeCommandTest` con `proc_open`): initialize + tools/list + tools/call end-to-end | ✅ |
| Tools devuelven JSON estructurado (2.5) | Content de `result.content[].text` parseable con los campos esperados (id, version_number, answer_text, confidence, sources) | ✅ |
| Token validado contra `agente_tokens` (2.5) | Tests: token inexistente → error `-32001`; pregunta de otro usuario → `isError=true` ("Pregunta no encontrada para este usuario"); expiración validada por mensaje | ✅ |
| Smoke real | `agent-token:create` + `mcp:serve` con pipe de 5 mensajes (initialize, notifications/initialized, list_questions, question inexistente, ping) — respuestas correctas | ✅ |

**Desviaciones:**
1. **`scopes` sin `default` en la columna**: MySQL < 8.0.13 no permite default en columnas JSON (error 1101); el default `["read"]` lo aplica Eloquent vía `$attributes` del modelo (nullable en BD, default en la capa de aplicación).
2. **Autenticación:** `Hash::check` se paga **una vez por sesión** (bcrypt no es buscable por hash; se itera los tokens vigentes no expirados — tabla de pocos agentes); en **cada mensaje** se re-valida existencia y expiración contra la tabla y se actualiza `last_used_at`. Trade-off documentado en el código y en `docs/mcp-server.md`.
3. Errores de ejecución de tool (pregunta inexistente/ajena) → `result.isError=true` con mensaje en el content (spec MCP), mientras que params inválidos/método desconocido → error JSON-RPC (`-32602`/`-32601`).
4. Sin SDK MCP (hand-rolled), consistente con la convención del proyecto; alternativa `php-mcp/server` documentada si el protocolo crece.

**Riesgos no previstos:** ver §5 (bug del `down()` de `add_uuid`, `RefreshDatabase` vs `DatabaseMigrations`). **Preguntas abiertas nuevas:** ninguna de producto; la integración manual con Claude Code queda como verificación opcional (documentada, protocolo ya probado por tests de integración).

---

## §2 Decisiones técnicas (con alternativa descartada)

| # | Decisión | Alternativa descartada | Razón |
|---|---|---|---|
| 1 | Interfaz mínima que retorna el DTO existente `KuaforiaResponse` | DTO de respuesta propio de la interfaz | El maestro exige interfaz mínima; no duplicar tipos |
| 2 | Binding `singleton(Interface, Clase)` + singleton concreto | Closure que comparta la misma instancia | Fiel al plan; inocuo porque el servicio es stateless |
| 3 | Señales como `array` (no DTOs) | DTOs tipados por señal | Resiliencia a cambios del catálogo (criterio del maestro) |
| 4 | Mapeo tool→método en config (`mcp_tools`) | Hardcodear nombres de tool en el proveedor | Un cambio de catálogo se resuelve en config, sin refactor |
| 5 | Enriquecimiento de señales **antes** de la transacción | Dentro de la transacción (lectura literal del plan) | No mantener el lock de fila durante una llamada HTTP de 15 s |
| 6 | `handle()` con parámetro opcional `?StructuredSignalProviderInterface $signals = null` | Parámetro requerido + actualizar tests | No romper tests existentes; el container lo resuelve al encolarse |
| 7 | `workspace_map` como fallback para tenant→workspace | Tabla de mapeo manual propia | Resolución de revisión (Hallazgo 1): la fuente primaria será el `workspace_id` que devuelva Kuaforia |
| 8 | `mcp_api_key` default = key compartida de la consulta REST | Key dedicada por tenant | Resolución de revisión (Hallazgo 2): aceptada para el piloto, superficie de confianza documentada |
| 9 | Default de `scopes` en Eloquent (`$attributes`) | `default` en la columna JSON | MySQL < 8.0.13 no soporta default en JSON (error 1101) |
| 10 | `Hash::check` una vez por sesión + re-validación por mensaje | `Hash::check` en cada mensaje | bcrypt (~100 ms) por mensaje es caro; la tabla de tokens es chica |
| 11 | Errores de tool → `isError=true`; params/método → error JSON-RPC | Todo como error JSON-RPC | Spec MCP distingue ejecución de tool de errores de protocolo |
| 12 | MCP hand-rolled (sin SDK) | `php-mcp/server` | Convención "crear, no instalar"; suficiente para el esqueleto |
| 13 | `structured_signals` sin modelo ni persistencia | Persistir señales en el job (8.4) | El maestro define la tabla "lista para almacenar señales futuras"; persistir es evolución futura |
| 14 | Tests de integración con `DatabaseMigrations` | `RefreshDatabase` | La transacción de RefreshDatabase es invisible para el proceso hijo (`proc_open`) |

---

## §3 Resoluciones de producto/tecnología/ingeniería (v1.1) y cómo se aplicaron

| # | Resolución | Aplicación en la implementación |
|---|---|---|
| 1 | `tenant_slug` ↔ `workspace_id` (Hallazgo 1): no bloquear el Bloque 8; avanzar con degradación (`workspace_map` opcional) y pedir a Kuaforia el `workspace_id` por defecto | `workspace_map` implementado como **fallback** en `collectSignals()` (sin mapeo → skip silencioso). El `resolveTenantFromApiKey` (Fase 1) ya captura `workspace_id` si Kuaforia lo devuelve; cuando exista, se vuelve la fuente primaria. Coordinación con Kuaforia pendiente (§4) |
| 2 | Contrato exacto del puente MCP: paso de verificación técnica (no decisión) | La normalización (8.2) y el mapeo `mcp_tools` (8.3) se diseñaron para ajustarse a la respuesta real sin refactor. Verificación contra el puente real pendiente (§4) |
| 3 | Exponer señales en el MCP Server propio: **fuera de alcance definitivo** | Confirmado en el Bloque 9: el MCP propio **no** expone señales de Kuaforia; un agente externo le habla directo al MCP de Kuaforia. Documentado en `docs/mcp-server.md` |
| 4 | API key compartida en MCP (Hallazgo 2): aceptar para el piloto, documentar como superficie de confianza | `mcp_api_key` default = key compartida (config 8.3), con nota explícita en `config/services.php` de revisar cuando haya más de un tenant con datos sensibles conectado simultáneamente |

---

## §4 Pendientes fuera de fase

1. **Pendiente #2 (maestro):** confirmar el catálogo real de tools MCP de Kuaforia (nombres de tools y argumentos). El código ya está preparado: el mapeo `mcp_tools` y la normalización se ajustan en config/código sin refactor. Si falta una señal a nivel de caso (`stale_case`), evaluar `get_case` + lógica interna o solicitar la tool a Kuaforia.
2. **`workspace_id` por defecto en la validación apikey→tenant:** pedir a Ingeniería de Kuaforia que el mecanismo (el mismo del Bloque 6) devuelva el `workspace_id` por defecto; cuando exista, reemplaza a `workspace_map` como fuente primaria.
3. **Contrato real del puente `POST /api/v1/mcp`:** verificado solo con mock local y `Http::fake`; falta una llamada real contra Kuaforia (tinker o script) cuando esté disponible.
4. **SMTP real en producción** (de Fase 1, sigue pendiente): el correo de notificaciones usa el mailer de la app; elegir el proveedor SMTP operativo.
5. **Integración manual con Claude Code** (verificación manual opcional): documentada en `docs/mcp-server.md`; el protocolo ya está cubierto por tests de integración y smoke real.
6. **Fase 3 (usabilidad, Bloques 11–14):** plan listo, sin iniciar.

---

## §5 Riesgos no previstos en el plan maestro

1. **CRÍTICO — bug pre-existente que rompía cualquier `migrate:rollback`:** el `down()` de `add_uuid_to_users_table` usaba `dropIndex(['uuid'])` → generaba `users_uuid_index`, pero el índice creado por `unique()` es `users_uuid_unique` → error `Can't DROP 'users_uuid_index'`. Lo detectó el rollback de cierre de `DatabaseMigrations` en los tests de integración del Bloque 9. **Corregido** (`dropUnique('users_uuid_unique')`; solo toca el down, los entornos ya aplicaron el up). Beneficio colateral: ahora `migrate:rollback` funciona en dev.
2. **`RefreshDatabase` es invisible para procesos hijos:** la transacción abierta del framework no se commitea, y un proceso lanzado con `proc_open` (otra conexión MySQL) no ve los datos del test. Los tests de integración stdio usan `DatabaseMigrations` (migraciones commiteadas).
3. **MySQL < 8.0.13 no permite `default` en columnas JSON** (error 1101): el default de `scopes` se movió a la capa de Eloquent (`$attributes` del modelo).
4. **bcrypt no es buscable por hash:** la validación del token de agente itera los tokens vigentes. Aceptable para una tabla de pocos agentes; si crece, evaluar un índice por prefijo/last4.
5. **Closure de `chunk()` en el job:** cualquier variable nueva del `handle()` debe entrar explícitamente en el `use` del closure (PHP no la captura automáticamente) — hallazgo de QA que costó una iteración.
6. **Dos singletons del contenedor** (interfaz + clase concreta de `KuaforiaService`): inocuo por ser stateless; anotado por si algún día el servicio tuviera estado.

---

## §6 Preguntas abiertas nuevas

No surgieron preguntas abiertas de producto durante la implementación. Las únicas incógnitas son de **coordinación con Kuaforia** y ya están registradas en §4 (catálogo real de tools MCP, `workspace_id` por defecto, contrato del puente). La Fase 2 quedó implementada con los mecanismos de degradación y configuración que hacen esos pendientes **no bloqueantes**.

---

*Documento generado a partir de la implementación de `kuestion-fase2-plan-implementacion.md` v1.1. Suite final: 77 tests (222 assertions). Fase 2 completa: Bloques 7, 8, 9 y 10.*
