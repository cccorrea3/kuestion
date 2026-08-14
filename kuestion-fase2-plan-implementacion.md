# Kuestion — Plan de Implementación · Fase 2 (Arquitectura interna)

> **Versión:** 1.1 | **Fecha:** 2026-08-14 | **Fuente:** `Plan_Mejora_Kuestion_v2.4.md` (cerrado, v2.4) | **Alcance:** Bloques 7–10 | **Estado:** documento de implementación (el CÓMO)
>
> **v1.1 (2026-08-14):** incorpora las resoluciones de producto/tecnología/ingeniería y los hallazgos de la revisión (ver §6). Cambios: relación `tenant_slug` ↔ `workspace_id` resuelta con acción hacia Kuaforia (§1.3, 8.4); API key compartida en MCP aceptada para el piloto y documentada como superficie de confianza (8.2/8.3); señales en el MCP server propio fuera de alcance definitivo (Bloque 9); contrato del puente MCP reclasificado como paso de verificación técnica.
>
> **Autocontenido:** este documento incluye el contexto, las restricciones y las decisiones pendientes del documento maestro que aplican a esta fase. No reabre decisiones cerradas (QUÉ/POR QUÉ); define cómo implementarlas. Las tareas se apoyan en herramientas de IA generadora (Opencode u otras): la sección 4 indica dónde reutilizar patrones existentes y cómo acotar el contexto por tarea.

---

## 1. Contexto de la fase

### 1.1 Qué cubre esta fase

| Bloque | Objetivo (del maestro) | Esfuerzo |
|---|---|---|
| **7 — Interfaz de proveedor RAG** | Extraer `RagProviderInterface`; `KuaforiaService` la implementa (2.1) | S (~1 d) |
| **8 — Señales estructuradas vía MCP** | Interfaz de señales (2.2), `KuaforiaMcpProvider` (2.3), enriquecimiento del job (2.4) | M-L (3–5 d + dependencia externa) |
| **9 — MCP Server propio de Kuestion** | Esqueleto MCP (STDIO) con tools de solo lectura + auth por token de agente (2.5) | M-L (3–4 d) |
| **10 — Modelo de datos multi-fuente** | Columnas `source_platform`/`external_id`/`last_external_check` + tabla `structured_signals` (2.6) | S (0.5–1 d) |

**Total estimado:** ~8–11 días hábiles. Secuencia interna obligatoria: **7 → 8** (y dentro de 8: 2.2 → 2.3 → 2.4). Bloques 9 y 10 son independientes y pueden ir en paralelo.

### 1.2 Restricciones del documento maestro aplicables a esta fase

1. **No se modifica la integración REST con Kuaforia en su esencia:**
   - El llamado `POST /api/consult/{tenant_slug}` sigue siendo síncrono.
   - El mecanismo de detección de cambios (hash SHA-256 + similitud coseno) se mantiene intacto.
   - El `CheckQuestionUpdatesJob` sigue siendo el responsable de la re-consulta periódica.
   - En esta fase el job se toca **solo** en 2.4 para **enriquecer** la notificación con señales; el enriquecimiento **debe degradarse con gracia** (si el proveedor de señales falla o no está disponible, el job sigue funcionando con el mecanismo actual sin interrupción).
2. **La vía MCP hacia Kuaforia (señales estructuradas) y el futuro MCP Server de Kuestion se construyen en paralelo al mecanismo REST actual:** no lo reemplazan ni lo modifican. No es una capa de baja prioridad: se prioriza dentro de la Fase 2 inmediatamente después del refactor base (Bloque 7). El puente HTTP MCP de Kuaforia ya está construido y funcionando (`POST /api/v1/mcp`, autenticación por API key).
3. **Exclusión de TenantTools:** no se usan operaciones de listado de tenants.

### 1.3 Decisiones pendientes del maestro aplicables a esta fase

- **Pendiente #2 — Tools MCP para señales (bloque 8):** el mapeo propuesto (`get_workspace_health`, `get_dependency_health_report`, `get_case`) se basa en el catálogo actual de Kuaforia. Durante la implementación se verificará que estas tools cubren las necesidades. Si se requiere una señal a nivel de caso individual (ej. `stale_case`), se evaluará si se puede obtener combinando `get_case` con lógica interna, o se solicitará a Kuaforia que la exponga como tool MCP. **Impacto en el diseño:** el proveedor normaliza las respuestas a arrays con forma documentada y el mapeo tool→método es configurable por config (ver 8.3), de modo que un cambio de catálogo no requiera refactor.
- **Resolución de revisión (2026-08-14) — relación `tenant_slug` ↔ `workspace_id` (Hallazgo 1):** no bloquea el Bloque 8. Se avanza con el diseño de degradación propuesto (`workspace_map` opcional) y, en paralelo, se le pide a Ingeniería de Kuaforia que el mecanismo de validación apikey→tenant (el mismo del Bloque 6, Fase 1) devuelva también el `workspace_id` por defecto del tenant (Kuaforia crea uno por defecto al alta de cada tenant). Eso evita que Kuestion mantenga una tabla de mapeo manual. Si algún día un tenant tiene varios workspaces reales, será una decisión de producto (¿cuál vigila Kuestion?) — no se diseña para ese caso todavía.

### 1.4 Estado real del código relevante (verificado en el repo)

| Aspecto | Realidad en el código |
|---|---|
| `KuaforiaService` | Clase concreta, registrada como singleton en `AppServiceProvider` (`$this->app->singleton(KuaforiaService::class)`). Inyectada por clase en `QuestionController` (constructor), `CreateQuestion::save`, `QuestionDetail::askFollowUp` y `CheckQuestionUpdatesJob::handle`. No hay interfaz. |
| `KuaforiaResponse` | DTO existente (`answerText`, `confidence`, `sources`, `conversationId`). |
| Job | Crea versión + notificación en una transacción; usa `ChangeDetector` directo. |
| Contracts / DTOs | No existe `app/Contracts`; no hay DTOs más allá de `KuaforiaResponse`. |
| `agente_tokens` | No existe la tabla ni el modelo (requeridos por 2.5). |
| `structured_signals` / columnas multi-fuente | No existen (requeridos por 2.6). |
| Puente MCP de Kuaforia | Asumido existente y funcional (`POST /api/v1/mcp`, auth por API key) — verificar contra el Kuaforia real al implementar 2.3. |
| Tests | 16 tests (27 assertions) pasan; `KuaforiaServiceTest` usa `Http::fake()`. |

### 1.5 Secuencia recomendada y dependencias finas

```
Bloque 7 (RagProviderInterface) ──→ Bloque 8 (señales MCP: 2.2 → 2.3 → 2.4)
Bloque 10 (migraciones inocuas) ── independiente, puede ir primero (calentamiento)
Bloque 9 (MCP Server propio)    ── independiente de 7/8; requiere migración agente_tokens
```

- **7 → 8:** el refactor de interfaz se hace primero para que el `KuaforiaMcpProvider` (que consume el mismo contrato de consulta) no introduzca acoplamiento a la clase concreta.
- **8:** la interfaz (2.2) se define y se testea sola; el proveedor (2.3) se prueba con `Http::fake()`; el job (2.4) se toca solo después de que el proveedor esté probado.
- **9 y 10:** paralelos; 10 es el de menor riesgo (migraciones sin uso) y sirve de calentamiento junto con 7.

---

## 2. Diseño técnico por bloque

### Bloque 7 — Interfaz de proveedor RAG — Esfuerzo S (~1 d)

**Criterios de aceptación (del maestro):**
- El código existente sigue funcionando sin cambios.
- Se puede inyectar un proveedor mock en tests.
- La interfaz es mínima y específica para el caso de uso de vigilancia.

**Desglose de tareas:**

| ID | Tarea | Archivos clave | Dep | Esf |
|----|-------|----------------|-----|-----|
| 7.1 | Crear `app/Contracts/RagProviderInterface.php` con un único método: `consult(string $question, ?string $conversationId = null): KuaforiaResponse`. | `app/Contracts/RagProviderInterface.php` (nuevo) | — | S |
| 7.2 | `KuaforiaService implements RagProviderInterface` (mantiene su firma actual con el parámetro opcional `$tenantSlug` — PHP permite parámetros opcionales extra en la implementación). | `app/Services/KuaforiaService.php` | 7.1 | S |
| 7.3 | Registrar el binding en `AppServiceProvider`: `$this->app->singleton(RagProviderInterface::class, KuaforiaService::class);` (mantener también el singleton de la clase concreta si algún consumer la pide por clase). | `app/Providers/AppServiceProvider.php` | 7.2 | S |
| 7.4 | Tipar por interfaz los consumidores: `QuestionController` (constructor), `CreateQuestion::save`, `QuestionDetail::askFollowUp`, `CheckQuestionUpdatesJob::handle`. | 4 archivos | 7.3 | S |
| 7.5 | Test double `tests/Fakes/FakeRagProvider.php` (implementa la interfaz con respuestas configurables) para reemplazar `Http::fake()` en feature tests que tocan Kuaforia. Opcional pero recomendado: tests más rápidos y sin red. `KuaforiaServiceTest` se mantiene para el cliente real. | `tests/Fakes/FakeRagProvider.php` (nuevo), tests | 7.4 | S |

**Decisiones de implementación:**
- La interfaz retorna el DTO existente `KuaforiaResponse` (no crear un DTO nuevo; el maestro exige interfaz mínima).
- `$tenantSlug` **no** entra en la interfaz (es detalle de implementación de Kuaforia); el job sigue pasándolo explícitamente.
- **Regresión:** cero cambio de comportamiento en `KuaforiaService` (solo se agrega `implements`); la suite completa (16 tests) debe pasar sin modificar ningún test existente.
- **Nota de implementación (2026-08-14, Bloque 7 implementado):**
  - **7.1:** `app/Contracts/RagProviderInterface.php` — un único método `consult(string $question, ?string $conversationId = null): KuaforiaResponse`, retornando el DTO existente (interfaz mínima, sin DTO nuevo). Docblock documenta que el tenant queda fuera de la interfaz (detalle de Kuaforia).
  - **7.2:** `KuaforiaService implements RagProviderInterface` — cero cambios de comportamiento; la firma conserva el parámetro opcional extra `$tenantSlug` (permitido por PHP).
  - **7.3:** binding `singleton(RagProviderInterface::class, KuaforiaService::class)` + se mantiene el singleton de la clase concreta (los consumidores de `resolveTenantFromApiKey` — `Register`, `Settings` — siguen pidiendo `KuaforiaService` por clase, ya que ese método no es parte de la interfaz).
  - **7.4:** consumidores tipados por interfaz: `QuestionController` (constructor), `CreateQuestion::save`, `QuestionDetail::askFollowUp` y `CheckQuestionUpdatesJob::handle`. `Register`/`Settings` se mantienen con la clase concreta (usan `resolveTenantFromApiKey`, fuera de la interfaz). Los tests del job siguen pasando `app(KuaforiaService::class)` a `handle()` sin cambios (KuaforiaService implementa la interfaz).
  - **7.5:** `tests/Fakes/FakeRagProvider.php` — test double con respuestas configurables (`respondWith`), excepción configurable (`throwWhenCalled`) y registro de llamadas (`$calls`). `RagProviderInterfaceTest` (3 tests): el binding resuelve a `KuaforiaService`; el flujo de `CreateQuestion::save` corre con el fake inyectado vía `app()->instance()` **sin red ni `Http::fake()`**; el fake conserva `conversationId`.
  - **QA:** suite completa 55 tests (155 assertions) — 52 previos sin modificar + 3 nuevos; `vendor/bin/pint` PASS. Pint además normalizó estilo pre-existente pendiente en `CreateQuestion` (early returns de una línea y separación de atributos) — solo estilo, sin cambio de lógica.

### Bloque 8 — Señales estructuradas vía MCP — Esfuerzo M-L (~3–5 d + dependencia externa)

*Pieza central de la Fase 2: fortalece la integración con Kuaforia sin tocar el mecanismo REST vigente.*

**Criterios de aceptación (del maestro):**
- La interfaz mapea a tools reales del catálogo de Kuaforia: `get_workspace_health`, `get_dependency_health_report`, `get_case`.
- El proveedor MCP funciona y devuelve señales estructuradas.
- La notificación enriquece el diff con metadatos de señales (sin modificar el comportamiento principal de hash + similitud).
- El código está preparado para ajustes si el catálogo de tools cambia.

**Desglose de tareas (secuencia 2.2 → 2.3 → 2.4):**

| ID | Tarea | Archivos clave | Dep | Esf |
|----|-------|----------------|-----|-----|
| 8.1 (2.2) | Crear `app/Contracts/StructuredSignalProviderInterface.php` con 3 métodos que devuelven `array` (forma documentada en docblocks): `getWorkspaceHealth(string $workspaceId): array`, `getDependencyHealthReport(string $workspaceId): array`, `getCaseDetails(string $caseId): array`. | `app/Contracts/StructuredSignalProviderInterface.php` (nuevo) | — | S |
| 8.2 (2.3) | Crear `app/Services/KuaforiaMcpProvider.php implements StructuredSignalProviderInterface`: cliente JSON-RPC 2.0 sobre `POST {mcp_url}/api/v1/mcp` con `Authorization: Bearer` (misma convención que `KuaforiaService`: `Http::timeout(15)->withToken(...)->post(...)`). Body `tools/call`: `{jsonrpc, id, method: "tools/call", params: {name, arguments}}`. Normalizar `result.content[].text` (JSON string si aplica) al array esperado. Errores → `KuaforiaMcpException` (nueva, en `app/Exceptions`, misma convención que `KuaforiaException`). | `app/Services/KuaforiaMcpProvider.php` (nuevo), `app/Exceptions/KuaforiaMcpException.php` (nuevo) | 8.1 | M |
| 8.3 | Config: `services.kuaforia.mcp_url` (default `base_url . '/api/v1/mcp'`), `mcp_api_key` (default la compartida), `mcp_tools` (mapeo nombre de tool → método de la interfaz), `workspace_map` (tenant_slug → workspace_id, opcional). El mapeo configurable cubre el criterio "preparado para ajustes si el catálogo cambia". | `config/services.php` | 8.2 | S |
| 8.4 (2.4) | Enriquecer `CheckQuestionUpdatesJob`: en el flujo de cambio detectado, **antes** de construir la notificación, intentar obtener señales (resolver `workspace_id` desde `workspace_map` por tenant_slug; si no hay mapeo → skip silencioso), llamar al proveedor y agregar la clave `signals` al payload de la notificación. Todo dentro de `try/catch (\Throwable)` → `Log::warning` + continuar. **La detección, la creación de versión y el payload base de la notificación no cambian.** | `app/Jobs/CheckQuestionUpdatesJob.php` | 8.2, 8.3 | M |
| 8.5 | Validar el mapeo de tools contra el catálogo real de Kuaforia (pendiente #2): si falta una señal a nivel de caso (`stale_case`), evaluar `get_case` + lógica interna o solicitar la tool a Kuaforia. | — | 8.2 | S |

**Decisiones de implementación:**
- **Arrays en lugar de DTOs** para el retorno de la interfaz: resiliencia a cambios del catálogo (criterio del maestro) y menor fricción al ajustar. Si el catálogo se estabiliza, se pueden extraer DTOs después (fuera de alcance).
- **Degradación con gracia obligatoria (decisión de resiliencia del maestro):** 2.4 nunca bloquea ni reintenta el job por fallo de señales; el timeout corto (15 s) del proveedor evita alargar el job.
- **2.4 no persiste señales** en `structured_signals`: el maestro define la tabla en 2.6 como "lista para almacenar señales futuras". La persistencia es evolución futura (usará esa tabla cuando se defina); no implementarla en esta fase.
- El payload de la notificación mantiene las claves actuales (`question_id`, `question_text`, `version_number`, `change_type`, `similarity`) y agrega `signals` — los consumidores (`NotificationBadge`, `markNotificationRead`) no se ven afectados.
- **Fuente del `workspace_id` (resolución de revisión, Hallazgo 1):** la fuente primaria será el `workspace_id` por defecto que devuelva el mecanismo de validación de Kuaforia (apikey→tenant, ver §1.3); `workspace_map` queda como **fallback** mientras eso no exista. El enriquecimiento sigue degradando con gracia si no hay mapeo ni respuesta.
- **Superficie de confianza — API key compartida en MCP (resolución de revisión, Hallazgo 2):** `mcp_api_key` usa por defecto la misma key compartida de la consulta REST. Se **acepta para el piloto** (mismo criterio que el REST vigente; no es una regresión de seguridad) y se documenta explícitamente como superficie a revisar el día que haya más de un tenant con datos sensibles conectado simultáneamente (más allá de Ispend). No urgente.
- **Nota de implementación (2026-08-14, Bloque 8 implementado):**
  - **8.1 (2.2):** `app/Contracts/StructuredSignalProviderInterface.php` — 3 métodos que devuelven `array` con forma documentada en docblocks (arrays en lugar de DTOs, resiliencia a cambios del catálogo).
  - **8.2 (2.3):** `app/Services/KuaforiaMcpProvider.php` — cliente JSON-RPC 2.0 `tools/call` sobre `POST {mcp_url}` con `Http::timeout(15)->withToken(...)->post(...)` (misma convención que `KuaforiaService`). El nombre de tool se resuelve desde `services.kuaforia.mcp_tools` (tool → método) vía `array_search` en `toolNameFor()`: un cambio de catálogo se ajusta en config, sin refactor. Normalización: concatena `result.content[].text`, decodifica JSON string si aplica, texto plano → `['text' => ...]`, sin contenido → `[]` con `Log::warning`. Errores → `KuaforiaMcpException` (nueva, misma convención que `KuaforiaException`): HTTP fallido, error de protocolo JSON-RPC (`body.error`) y `result.isError=true` con el mensaje de la tool. Argumentos por método: `workspace_id` para las 2 de workspace, `case_id` para `get_case` (nombres del catálogo actual, ajustables al confirmar contrato — pendiente #2).
  - **8.3:** config `services.kuaforia`: `mcp_url` (default `base_url . '/api/v1/mcp'`), `mcp_api_key` (default la key compartida — superficie de confianza, Hallazgo 2), `mcp_tools` (mapeo tool → método) y `workspace_map` (vacío por defecto; fallback mientras Kuaforia no devuelva el `workspace_id` por defecto en la validación apikey→tenant — Hallazgo 1). Binding `singleton(StructuredSignalProviderInterface::class, KuaforiaMcpProvider::class)` en `AppServiceProvider` (necesario para que el job encolado resuelva el proveedor real; el parámetro de `handle()` es opcional para no romper los tests existentes que llaman `handle()` con un solo argumento).
  - **8.4 (2.4):** `CheckQuestionUpdatesJob::handle(RagProviderInterface $kuaforia, ?StructuredSignalProviderInterface $signals = null)` — el enriquecimiento corre **antes de la transacción** (decisión de implementación: no mantener el lock de fila durante una llamada HTTP de hasta 15 s). `collectSignals()` resuelve el workspace desde `workspace_map` por tenant_slug; sin mapeo → skip silencioso (null). Todo el bloque va en `try/catch (\Throwable)` → `Log::warning` + continuar: un fallo de señales **nunca** interrumpe ni reintenta el job. `AnswerChangedNotification` ganó el parámetro opcional `?array $signals = null`; `toDatabase()` solo agrega la clave `signals` cuando no es null — sin señales el payload es byte a byte el de antes (los consumidores filtran por `data->question_id`, no se ven afectados). Las señales recolectadas son las 2 de nivel workspace (`getWorkspaceHealth` + `getDependencyHealthReport`); `getCaseDetails` queda disponible para señales a nivel de caso (evaluación de 8.5), el job no tiene case_id.
  - **8.5 (pendiente #2):** no bloqueado — el mapeo configurable (`mcp_tools`) ya cubre el ajuste de catálogo; queda pendiente confirmar el contrato real del puente (nombres de tools y argumentos) contra Kuaforia. Los tests simulan el catálogo actual.
  - **Mock local:** `kuaforia-mock.php` ahora responde `/api/v1/mcp` (JSON-RPC `tools/call` con las 3 tools y señales de prueba) además de la consulta REST — smoke local verificado end-to-end con `KuaforiaMcpProvider` real (tinker contra `php -S`, 3 tools normalizadas).
  - **QA:** suite completa 65 tests (183 assertions) — 55 previos sin modificar + 10 nuevos (7 del proveedor: contrato JSON-RPC + Bearer, 3 tools desde config, texto plano, HTTP error, JSON-RPC error, isError, tool renombrada vía config; 3 del job: signals presentes, degradación a payload base con proveedor caído, skip sin workspace_map con cero llamadas MCP verificadas por `Http::assertSentCount(1)`). `vendor/bin/pint` PASS. **Hallazgo de QA:** el parámetro `$signals` debe entrar en el `use` del closure de `chunk()` (PHP no lo captura automáticamente).

### Bloque 9 — MCP Server propio de Kuestion — Esfuerzo M-L (~3–4 d)

**Criterios de aceptación (del maestro):**
- Un agente externo (Claude Code) puede listar preguntas de un usuario autenticado.
- Las herramientas devuelven datos en formato estructurado (JSON).
- El token se valida contra la tabla `agente_tokens`.

**Desglose de tareas:**

| ID | Tarea | Archivos clave | Dep | Esf |
|----|-------|----------------|-----|-----|
| 9.1 | Migración `agente_tokens`: `id` uuid PK, `user_id` uuid (FK `users.uuid`, cascade), `name` string, `token_hash` string(60) (bcrypt), `scopes` json default `["read"]`, `last_used_at` nullable, `expires_at` nullable, timestamps. Modelo `AgentToken` (belongsTo `User`). | migración nueva, `app/Models/AgentToken.php` (nuevo) | — | S |
| 9.2 | Command `agent-token:create {user} {name}`: genera token plano (prefijo `kqt_` + `Str::random(32)`), guarda `Hash::make`, imprime el token **una sola vez** (no recuperable; regenerar si se pierde). | `app/Console/Commands/CreateAgentToken.php` (nuevo) | 9.1 | S |
| 9.3 | Protocolo MCP STDIO: `app/Console/Commands/McpServe.php` — lee stdin línea a línea (JSON-RPC newline-delimited, transporte stdio estándar de MCP), escribe stdout. Soporta: `initialize` (handshake), `notifications/initialized`, `ping`, `tools/list`, `tools/call`. Token vía `--token=` o env `KUESTION_AGENT_TOKEN`; validar con `Hash::check` contra `agente_tokens` + `expires_at`; scoping por `user_id` del token. | `app/Console/Commands/McpServe.php` (nuevo) | 9.1, 9.2 | M |
| 9.4 | Separar el dispatcher en clase pura `app/Services/Mcp/McpServer.php` (`handleMessage(array $message): array`) para testear `tools/call` sin proceso. | `app/Services/Mcp/McpServer.php` (nuevo) | 9.3 | M |
| 9.5 | Tools (solo lectura, scoped por `user_id` del token, output JSON en `result.content[].text`): `list_questions` (`status?`, `tag?`, `search?`), `get_question_details` (`question_id` → pregunta + versión actual), `list_unreviewed_changes` (`limit?`). | `app/Services/Mcp/McpServer.php` | 9.4 | M |
| 9.6 | Documentación MCP: generar token, configurar Claude Code (`mcpServers` → `command: php artisan mcp:serve --token=...`). | docs/README | 9.2–9.5 | S |

**Decisiones de implementación:**
- **Hand-rolled mínimo** (sin SDK PHP de MCP): consistente con la convención del proyecto ("crear, no instalar") y suficiente para un esqueleto con los 4 métodos del protocolo. Alternativa documentada: `php-mcp/server` si el protocolo crece (se evalúa si el esqueleto no alcanza).
- Autenticación: token de agente (no la API key compartida), validado contra `agente_tokens` en cada `tools/call` (y en `initialize` para fallar temprano).
- `scopes` se define como `["read"]` desde el inicio aunque hoy solo haya tools de lectura (el maestro fija "solo lectura"); deja la puerta para scopes futuros sin migración.
- **No exponer señales estructuradas de Kuaforia como tools del MCP server propio (resolución de revisión):** duplicaría el MCP de Kuaforia y rompe el principio "un MCP, un agente, por plataforma" del ecosistema. Un agente externo que quiera señales de Kuaforia le habla directo al MCP de Kuaforia (que ya las expone). Fuera de alcance definitivo (ver §6).
- **Nota de implementación (2026-08-14, Bloque 9 implementado):**
  - **9.1:** migración `2026_08_14_000008_create_agent_tokens_table` — uuid PK, `user_id` uuid FK a `users.uuid` cascade, `name`, `token_hash` string(60), `scopes` json, `last_used_at`, `expires_at`, timestamps. **Desviación:** `scopes` sin `default` en la columna — MySQL < 8.0.13 no permite default en JSON (error 1101); el default `["read"]` lo aplica Eloquent vía `$attributes` del modelo `AgentToken` (nullable en BD, default en la capa de aplicación). Modelo `AgentToken` (HasUuids, belongsTo User, `isExpired()`, `hasScope()`).
  - **9.2:** `agent-token:create {user} {name}` — busca por uuid o email, genera `kqt_`+`Str::random(32)`, guarda `Hash::make`, imprime el token **una sola vez** con advertencia de no recuperable.
  - **9.3:** `mcp:serve {--token=}` — lee stdin línea a línea (JSON-RPC newline-delimited), escribe stdout. Token por `--token=` o env `KUESTION_AGENT_TOKEN`. **Autenticación (decisión de implementación):** `Hash::check` se paga **una vez por sesión** (bcrypt no es buscable por hash; se itera los tokens vigentes no expirados — tabla de pocos agentes); en **cada mensaje** se re-valida existencia (`AgentToken::find`) y expiración contra la tabla, y se actualiza `last_used_at`. Token inválido/expirado → error JSON-RPC `-32001`. Parse error → `-32700`. Notificaciones → sin respuesta.
  - **9.4/9.5:** `app/Services/Mcp/McpServer.php` — clase pura `handleMessage(array): ?array` (null para `notifications/*`), testeable sin proceso. Soporta `initialize` (handshake con `protocolVersion` pedido, capabilities, serverInfo), `ping` (result `{}` con `stdClass`), `tools/list` (3 tools con `inputSchema`), `tools/call`. Tools de solo lectura scoped por `user_id` del token: `list_questions` (status/tag/search con `scopeSearch` del Bloque 4, máx. 50), `get_question_details` (pregunta + versión actual; pregunta ajena/inexistente → `isError=true`), `list_unreviewed_changes` (limit default 20 máx. 100). Errores JSON-RPC: método desconocido `-32601`, tool/params inválidos `-32602`. Output JSON en `result.content[].text` (`JSON_UNESCAPED_UNICODE`).
  - **9.6:** `docs/mcp-server.md` — generar token, config de Claude Code (`.mcp.json` con `command: php artisan mcp:serve --token=...` o env `KUESTION_AGENT_TOKEN`), tabla de tools, prueba manual del protocolo y notas de seguridad (revocación inmediata al borrar el `AgentToken`).
  - **QA:** suite completa 77 tests (222 assertions) — 65 previos + 12 nuevos (10 del dispatcher: initialize/ping/notificaciones/tools-list/scoping por usuario/filtros/detalles con versión actual/rechazo de pregunta ajena/cambios sin revisar/errores RPC; 2 de integración stdio vía `proc_open`: handshake + tools/call end-to-end y token inválido → `-32001`). Smoke real: `agent-token:create` + `mcp:serve` con pipe de 5 mensajes (initialize, notification, list_questions, question inexistente, ping) — respuestas correctas. `vendor/bin/pint` PASS.
  - **Hallazgo de QA (bug pre-existente corregido):** el `down()` de `add_uuid_to_users_table` usaba `dropIndex(['uuid'])` → generaba `users_uuid_index`, pero el índice creado por `unique()` es `users_uuid_unique` — cualquier `migrate:rollback` fallaba con `Can't DROP 'users_uuid_index'`. Lo detectó el rollback de cierre de `DatabaseMigrations` en los tests de integración. Fix: `dropUnique('users_uuid_unique')` (solo toca el down; los entornos ya aplicaron el up). Otro hallazgo: los tests de integración con `proc_open` **no pueden usar `RefreshDatabase`** (transacción abierta invisible para el proceso hijo) → se usó `DatabaseMigrations`.

### Bloque 10 — Modelo de datos para contrato mínimo multi-fuente — Esfuerzo S (0.5–1 d)

**Criterios de aceptación (del maestro):**
- Las migraciones se ejecutan sin errores.
- El código existente ignora estas columnas (no se usan aún).
- La tabla `structured_signals` está lista para almacenar señales futuras.

**Desglose de tareas:**

| ID | Tarea | Archivos clave | Dep | Esf |
|----|-------|----------------|-----|-----|
| 10.1 | Migración `questions`: `source_platform` string(20) default `'kuaforia'` (convención del proyecto: strings para enums, no enum nativo), `external_id` string(64) nullable, `last_external_check` timestamp nullable. Índices: `(user_id, source_platform)` y `(external_id)`. | migración nueva | — | S |
| 10.2 | Migración `structured_signals`: `id` uuid PK, `question_id` uuid (FK cascade), `signal_type` string(50), `payload` json, `detected_at` timestamp, `created_at`. Índice `(question_id, signal_type, detected_at)`. | migración nueva | — | S |
| 10.3 | Verificar inocuidad: no agregar las columnas nuevas a `fillable`/`casts` de `Question` (el código existente debe ignorarlas); `php artisan migrate:fresh --seed` + suite completa pasan. | — (verificación) | 10.1, 10.2 | S |

**Decisiones de implementación:**
- `source_platform` como string con default (no `enum` nativo de MySQL): consistente con `status`/`review_frequency`.
- No crear `unique(user_id, external_id)` (el maestro no lo pide); anotar como evolución posible cuando se use `external_id` de verdad.
- **Nota de implementación (2026-08-14, Bloque 10 implementado):**
  - **10.1:** migración `2026_08_14_000006_add_multi_source_columns_to_questions_table` — `source_platform` string(20) NOT NULL default `'kuaforia'` (after `user_id`), `external_id` string(64) nullable, `last_external_check` timestamp nullable; índices `(user_id, source_platform)` y `(external_id)`. `down()` revierte índices y columnas.
  - **10.2:** migración `2026_08_14_000007_create_structured_signals_table` — uuid PK, `question_id` uuid FK cascade a `questions.id`, `signal_type` string(50), `payload` json, `detected_at` timestamp, `created_at` timestamp; índice compuesto `(question_id, signal_type, detected_at)`. Misma convención que `answer_versions`.
  - **10.3 (verificado):** `Question` **no** expone las columnas nuevas en `fillable` ni `casts` (revisión de código + dump en tinker). `php artisan migrate:fresh --seed --force` corre sin errores (seeder idempotente, `updateOrCreate`). Esquema verificado con `SHOW COLUMNS`/`SHOW INDEX` (default `'kuaforia'`, índices compuestos correctos). Insert manual en `structured_signals` + `forceDelete` de la pregunta → cascade elimina la señal (criterio 3.1). Suite completa 65 tests (183 assertions) sin cambios — el bloque es inocuo.

---

## 3. Verificación (QA/Review)

### 3.1 Mapa de criterios de aceptación → verificación

| Criterio (bloque) | Verificación automatizada | Verificación manual |
|---|---|---|
| Código existente funciona sin cambios (2.1) | Suite completa (16 tests) pasa sin modificar tests existentes; `git diff` muestra solo los archivos esperados. | Smoke del flujo crear→job→notificar. |
| Proveedor mock inyectable en tests (2.1) | Test unit/feature nuevo inyectando `FakeRagProvider` (ej. crear pregunta con respuesta configurada). | — |
| Interfaz mínima (2.1) | Revisión de código: un método, retorna `KuaforiaResponse`. | — |
| Interfaz mapea a tools reales (2.2/2.3) | Test del proveedor con `Http::fake()`: assert body JSON-RPC con `tools/call` y los 3 nombres (`get_workspace_health`, `get_dependency_health_report`, `get_case`). | Confirmar contra el catálogo real de Kuaforia (pendiente #2). |
| El proveedor MCP devuelve señales estructuradas (2.3) | `KuaforiaMcpProviderTest`: fake de respuesta → normalización correcta al array documentado; respuesta malformada/error HTTP → `KuaforiaMcpException`. | Llamada real al puente `POST /api/v1/mcp` (tinker o script). |
| Notificación enriquecida sin modificar hash+similitud (2.4) | Test del job en 3 casos: (a) proveedor responde → notificación `data` incluye `signals`; (b) proveedor lanza excepción → versión y notificación base idénticas a hoy (degradación explícita); (c) sin `workspace_map` → cero llamadas al proveedor y notificación base. | Job manual con proveedor caído: el flujo actual no se interrumpe. |
| Preparado para cambios de catálogo (2.4) | El mapeo tool→método vive en config (`mcp_tools`): test que simula un nombre de tool distinto y verifica que se resuelve por config. | — |
| Agente externo lista preguntas (2.5) | `McpServerTest` (unit, sin proceso): `tools/list` devuelve las 3 tools con `inputSchema`; `tools/call list_questions` responde JSON scoped por `user_id` del token. Test del command: alimentar stdin con `initialize` + `tools/call` y verificar stdout JSON-RPC válido. | Conectar Claude Code local (`mcpServers`) y ejecutar las 3 herramientas. |
| Tools devuelven JSON estructurado (2.5) | Assert del contenido de `result.content[].text` parseable como JSON con los campos esperados. | Idem anterior. |
| Token validado contra `agente_tokens` (2.5) | Tests: token inexistente → error; token expirado (`expires_at` pasado) → error; usuario A no ve preguntas de usuario B. | — |
| Migraciones sin errores (2.6) | `php artisan migrate:fresh --seed` sin errores; `SHOW INDEX`/`DESCRIBE` confirman columnas e índices. | — |
| Código existente ignora las columnas (2.6) | Suite completa pasa; `Question` no expone las columnas nuevas en `fillable`/`casts`. | — |
| `structured_signals` lista para el futuro (2.6) | La tabla existe con FK + índice; insert manual de una fila de prueba y delete (cascade) al borrar la pregunta. | — |

### 3.2 Plan de regresión — alerta roja

**Lo que NO debe romperse en esta fase. Cualquier cambio en estos puntos es una alerta roja y debe detener el merge:**

1. **`POST /api/consult/{tenant_slug}`**: URL, método, payload, auth Bearer compartida, timeout 120 s, circuit breaker, parseo tolerante. (Bloque 7 solo agrega `implements`; no toca el comportamiento.)
2. **`ChangeDetector`**: hash SHA-256, umbral 0.8, tests unit.
3. **`CheckQuestionUpdatesJob`**: frecuencia de re-consulta, clasificación, transacción de versión, payload base de la notificación. (2.4 agrega un paso best-effort **antes** de la notificación; test explícito de degradación garantiza que con el proveedor caído el resultado es idéntico al actual.)
4. **Aislamiento por `current_user_id()`** (y en jobs, resolución desde `$question->user`).
5. **Suite actual**: 16 tests (27 assertions) pasan después de cada bloque.
6. **UI existente**: sin cambios en esta fase (los bloques son de backend/arquitectura).

### 3.3 Validación aislada por bloque

- **7**: suite completa + test con `FakeRagProvider`.
- **8**: proveedor testeado solo (8.2/8.3) antes de tocar el job; el cambio del job (8.4) se valida con los 3 casos de 3.1.
- **9**: protocolo testeado sin proceso (9.4) + prueba manual con Claude Code.
- **10**: migraciones + suite (inocuo).
- Cierre de fase: suite completa + smoke end-to-end + `git diff` acotado (verificar REST/hash/job intactos salvo el enriquecimiento).

---

## 4. Eficiencia de código/tokens

**Reutilizar patrones existentes (no generar lógica nueva):**

| Tarea | Reutilizar |
|---|---|
| 8.2 (proveedor MCP) | El patrón HTTP de `KuaforiaService` (`Http::timeout(...)->withToken(...)->post(...)`) y el manejo de errores → `KuaforiaException` como modelo para `KuaforiaMcpException`. |
| 8.4 (enriquecimiento) | El flujo actual del job; solo se agrega el bloque de señales antes de `notify()` (mismo lugar donde hoy se arma el payload). |
| 9 (MCP server) | Convención de comandos Artisan (`app/Console/Commands/`), migraciones con uuid PK (misma que `questions`), `Http`/`Str` helpers del framework. |
| 10 (migraciones) | Convención de columnas string para enums (`status`, `review_frequency`); FK cascade como en `answer_versions`/`question_relations`. |
| Tests | `Http::fake()` (patrón de `KuaforiaServiceTest`/`QuestionApiTest`) para el proveedor MCP y el resolver. |

**División en sub-tareas verificables (preferir cambios chicos):**

- **8 en 3 pasos**: interfaz (8.1, testeable sola) → proveedor + config (8.2–8.3, testeable con fake) → enriquecimiento del job (8.4, testeable con los 3 casos). No tocar el job hasta que el proveedor esté probado.
- **9 en 3 pasos**: tabla + command (9.1–9.2) → protocolo (9.3–9.4) → tools (9.5–9.6). Cada paso verificable.

**Acotar el contexto para la IA generadora:**

- Trabajar **por bloque** pasando solo los archivos del bloque; este plan por fase es autocontenido (no incluir el maestro ni los docs de referencia en los prompts).
- En 8.4, el cambio al job debe ser **mínimo** (solo el bloque de enriquecimiento + `use` de la interfaz); pedir el diff acotado a ese método.
- No renombrar nada existente (solo agregar `implements` y tipar por interfaz en 7.4).
- El MCP server (9.4) se diseña como clase pura para poder testear sin levantar proceso (menos fricción y contexto en cada iteración).

---

## 5. Salida para revisión (formato de cierre de la fase)

Al cerrar la fase, entregar un **documento nuevo `.md`** (p.ej. `kuestion-fase2-salida-revision.md`) con, **por bloque**:

1. **Resumen ejecutivo**: qué se hizo (tareas completadas con sus IDs), cómo se verificó.
2. **Evidencia por criterio de aceptación**: tabla `criterio → cómo se comprobó (test/commando/captura) → resultado`. No basta "listo"; mostrar la comprobación.
3. **Desviaciones**: qué quedó distinto de lo planeado y la razón (p.ej., mapeo final de tools del catálogo real, si se usó `get_case` + lógica interna para `stale_case`, si se eligió un SDK MCP en lugar del hand-rolled).
4. **Riesgos no previstos** en el plan maestro, detectados durante la implementación.
5. **Preguntas abiertas nuevas** surgidas en la implementación.

Plantilla por bloque (igual que Fase 1):

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

Las preguntas abiertas de la v1.0 fueron resueltas en revisión, junto con dos hallazgos de la discusión. Resumen e impacto en este plan:

| # | Pregunta / hallazgo (v1.0) | Resolución | Impacto en el plan |
|---|---|---|---|
| 1 | `tenant_slug` ↔ `workspace_id` (Hallazgo 1) | **No bloquear el Bloque 8.** Avanzar con la degradación propuesta (`workspace_map` opcional). En paralelo, pedir a Ingeniería de Kuaforia que el mecanismo de validación apikey→tenant devuelva también el `workspace_id` por defecto del tenant (Kuaforia crea uno por defecto al alta; la mayoría de los tenants nuevos tendrá un solo workspace). Evita que Kuestion mantenga un mapeo manual. Varios workspaces por tenant → decisión de producto futura (¿cuál vigila Kuestion?), no se diseña ahora. | §1.3 y 8.4: `workspace_map` pasa a ser **fallback**; la fuente primaria será el `workspace_id` devuelto por Kuaforia (cuando exista). |
| 2 | Contrato exacto del puente MCP | **No es una decisión**: es un paso de verificación técnica durante 2.3. No requiere input de producto. | Sin cambios; la normalización (8.2) se ajusta según la respuesta real del puente. |
| 3 | Exponer señales en el MCP Server propio (2.5) | **Fuera de alcance definitivo.** Duplicaría el MCP de Kuaforia y rompe el principio "un MCP, un agente, por plataforma" del ecosistema; un agente externo habla directo con Kuaforia para señales. | Confirmado en el Bloque 9 (nota de decisión). |
| 4 | API key compartida en MCP (Hallazgo 2) | **Aceptar para el piloto**, mismo criterio que la consulta REST (no es regresión de seguridad). Documentar como superficie de confianza a revisar cuando haya más de un tenant con datos sensibles conectado simultáneamente. No urgente. | Nota explícita en 8.2/8.3. |

---

*Documento generado a partir de `Plan_Mejora_Kuestion_v2.4.md` (v2.4, cerrado). Próxima acción: bloques 7 y 10 (calentamiento), luego 8 en su secuencia 2.2 → 2.3 → 2.4, y 9 en paralelo; coordinar el pendiente #2 y la relación tenant_slug ↔ workspace_id con Kuaforia.*
