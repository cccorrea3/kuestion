# Plan de Implementación — Ola 1, Punto 1: Conector Kuestion↔QBK

*Equipo de Kuestion · Agosto 2026*
*Documento de entrada: OLA_1_Puntos_1_y_2.md*
*Contratos confirmados por QuBeKa: ola1-punto1-preguntas-abiertas.md*

---

## 1. RESUMEN DE ALCANCE

### Qué voy a construir

Un nuevo conector `qbk` dentro del sistema de conectores RAG de Kuestion, análogo al ya existente para Kuaforia. Esto permite que Kuestion trate a QuBeKa como una fuente de conocimiento intercambiable con Kuaforia, usando las interfaces ya definidas (`RagProviderInterface`, `IdentityResolverInterface`, `StructuredSignalProviderInterface`).

**Contratos confirmados por QuBeKa:**
- Motor de Consulta: `POST {QUBKA_API_URL}/query` con body `{"question": "..."}` — sin `workspace_id`; se resuelve desde el token del agente vía middleware `CheckWorkspace` de QuBeKa (ver Corrección v1.1 más abajo en esta sección, y Fase 3, tarea 3.1).
- Resolución de Identidad: `GET {QUBKA_API_URL}/agent/me`
- Autenticación: `Authorization: Bearer {plainTextToken}` (Sanctum, scope `api:read` default)
- Forma de respuesta: sección 1.5 del documento de origen + campo `texto_preview` en sources
- Confianza: fijo 0.5 (con resultados) / 0.0 (sin resultados)

### Lo que NO construyo (mi lado)

- El Motor de Consulta de QBK — lo construye QuBeKa (ya tiene la infraestructura: `OllamaProvider`, `AiProviderFactory`).
- La exposición MCP de QBK — explícitamente fuera de alcance.
- Búsqueda semántica en QBK — es roadmap de QuBeKa.
- Selector de tipo de conector en la UI — YAGNI, el `connector_type` se setea automáticamente.

### Corrección v1.1 — workspace_id resuelto desde token

> QuBeKa confirmó (corrección de contradicción entre `RESPUESTAS_OLA1_KUESTION.md` y `plan-motor-consulta-qbk-v1.md`): el `workspace_id` **se resuelve SIEMPRE desde el token del agente** via middleware `CheckWorkspace`. **No se envía en el body** del `POST /api/v1/query`. Si se envía, se ignora sin error.
>
> Body del request: `{"question": "texto de la pregunta"}` — solo la pregunta.
>
> Esto simplifica la Fase 3: `QbkService::consult()` no necesita enviar `workspace_id` en el body.

---

## 2. FASES Y TAREAS

### Fase 1 — Configuración y registro del conector

**Objetivo:** Registrar `qbk` en el sistema de conectores para que el flujo de conexión existente lo reconozca, sin cambiar nada del comportamiento actual.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| 1.1 | Agregar entrada `qbk` en `config/kuestion.connectors.php` con `display_name = 'QuBeKa'`, `description`, `auth_fields` (token de agente con key `api_token`), `identity_resolver`, `rag_provider`, `signal_provider = null`. Agregar `QUBKA_API_URL` a `config/services.php` y `.env.example`. | Ninguna | Archivo de config modificado, `ConnectorRegistry` resuelve las clases correctas para `qbk`. |
| 1.2 | Crear stubs vacíos de `QbkService` (implementa `RagProviderInterface`, lanza `KuaforiaException` en `consult()` por ahora) y `QbkIdentityResolver` (implementa `IdentityResolverInterface`, lanza excepción por ahora). | 1.1 | Dos clases stub que no rompen nada. |
| 1.3 | Test: `ConnectorRegistry` resuelve correctamente las clases de `qbk` (misma batería que el test existente de Kuaforia). | 1.1, 1.2 | Test verde que valida el registro. |

**Entregable verificable:** `ConnectorRegistry` resuelve las clases de `qbk`. Los tests existentes no cambian.

**Validación:** `php artisan test --filter=ConnectorRegistryTest` + suite completa sin regresiones.

---

### Fase 2 — Routing multi-conector

**Objetivo:** que el `QuestionChecker`, `CreateQuestion` y `QuestionDetail` resuelvan el servicio RAG correcto según el `connector_type` del repositorio, en vez de usar el singleton global que hoy devuelve siempre Kuaforia.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| 2.1 | Agregar método `ConnectorRegistry::ragProviderFor(string $connectorType): RagProviderInterface` que resuelva el servicio correcto dado un tipo. | Ninguna (el registry ya existe) | Método nuevo que instancia la clase del conector correcto. |
| 2.2 | Agregar método `ConnectorRegistry::identityResolverFor(string $connectorType): IdentityResolverInterface` (análogo). | Ninguna | Método nuevo. |
| 2.3 | Modificar `QuestionChecker` para que resuelva el servicio correcto según el `connector_type` del repositorio, en vez de inyectar `RagProviderInterface` global. El constructor recibe `ConnectorRegistry` y el `check()` resolve por `$question->repository->connector_type`. | 2.1 | `QuestionChecker` usa el servicio del conector correcto. |
| 2.4 | Modificar `CreateQuestion` y `QuestionDetail` para que usen el servicio del repositorio de la pregunta (no el global). | 2.1 | Componentes Livewire adaptados. |
| 2.5 | Modificar el binding en `AppServiceProvider` — el singleton de `RagProviderInterface` se mantiene como default (Kuaforia) para backward compat, pero los callers principales ya no lo usan para la consulta. | 2.3, 2.4 | Binding existente intacto; callers principales ya resuelven por repo. |
| 2.6 | Tests: crear un repo con `connector_type = 'qbk'` (usando el stub), crear una pregunta asociada, y verificar que `QuestionChecker` usa `QbkService` (no `KuaforiaService`). | 2.1–2.5 | Test que valida el routing por tipo. |
| 2.7 | Test de regresión: verificar que un repo con `connector_type = 'kuaforia'` sigue usando `KuaforiaService`. | 2.5 | Test existente sigue verde. |

**Entregable verificable:** con dos repos (uno `kuaforia`, uno `qbk`), el job horario y el botón "Comprobar ahora" usan el servicio correcto según el repo de la pregunta.

**Validación:** tests de routing + suite completa + smoke manual con un repo stub `qbk`.

---

### Fase 3 — QbkService: implementación de `RagProviderInterface`

**Objetivo:** que `QbkService::consult()` llame al Motor de Consulta de QBK y devuelva un `KuaforiaResponse` con los campos mapeados.

**Contrato confirmado (corregido v1.1):**
```
POST {QUBKA_API_URL}/query
Authorization: Bearer {token_del_repo}
Body: {"question": "texto"}
Response: {"answer": "...", "confidence": 0.5, "sources": [...], "found": true}
```

> **Nota:** el body solo contiene la pregunta. El workspace se resuelve del token via middleware de QuBeKa. No se envía `workspace_id`.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| 3.1 | Implementar `QbkService::consult()` — HTTP POST a `{QUBKA_API_URL}/query` con `Authorization: Bearer {credential['api_token']}`, body `{"question": $question}`. **No se envía workspace_id** (se resuelve desde el token via middleware de QuBeKa). Parseo de la respuesta al formato `KuaforiaResponse`. | Fase 2 completada | Método funcional con contrato real. |
| 3.2 | `workspace_id` para metadata interna: el `QbkIdentityResolver` lo resuelve al validar el token (Fase 4) y se persiste en `repositories.resolved_workspace_id` para uso interno de Kuestion. **No se envía en el body del POST /query** — QuBeKa lo resuelve desde el token. | 3.1, Fase 4 | `workspace_id` persistido para metadata; consulta sin workspace en body. |
| 3.3 | Manejo de errores: 401 → `KuaforiaException` con código 401 (patrón D4); 5xx/timeout → reintento con circuit breaker; `found: false` → respuesta vacía (el `QuestionChecker` ya maneja esto). | 3.1 | Errores manejados consistentemente con Kuaforia. |
| 3.4 | Mapping de `sources`: el formato de QBK es compatible con el DTO (`node_id`, `tipo`, `estado_validacion`, `fecha_ultima_validacion`, `texto_preview`). Mantener shape nativo de QBK — el `ChangeDetector` solo hashea el `answerText`, no las sources. | 3.1 | Sources mapeados correctamente. |
| 3.5 | Circuit breaker aislado: usar cache key `qbk:failures` (independiente de `kuaforia:failures`). Mismo patrón: 3 fallos → pausa 60s. | 3.3 | Circuit breaker por conector. |
| 3.6 | Mock local para desarrollo: script PHP stub que devuelva la forma de la sección 1.5 (similar a `kuaforia-mock.php`). | 3.1 | Mock funcional. |
| 3.7 | Tests unitarios: `QbkService` con HTTP fake, cubriendo: respuesta exitosa, 401, 5xx, timeout, `found: false`. | 3.1–3.6 | Tests verdes. |

**Entregable verificable:** `QbkService::consult()` llama al endpoint de QBK, parsea la respuesta, y devuelve un `KuaforiaResponse` correcto. El circuit breaker de QBK es independiente del de Kuaforia.

**Validación:** tests unitarios + smoke contra el mock local.

---

### Fase 4 — QbkIdentityResolver: implementación de `IdentityResolverInterface`

**Objetivo:** que `QbkIdentityResolver::resolveIdentity()` resuelva la identidad del workspace de QBK desde un token de agente.

**Contrato confirmado:**
```
GET {QUBKA_API_URL}/agent/me
Authorization: Bearer {token}
Response: {"workspace_id": 123, "workspace_nombre": "...", "user_id": 45, ...}
```

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| 4.1 | Implementar `QbkIdentityResolver::resolveIdentity()` — HTTP GET a `{QUBKA_API_URL}/agent/me` con `Authorization: Bearer {credential['api_token']}`. Parsear `workspace_id` y `workspace_nombre` al `ResolvedIdentity`. | Fase 2 completada | Método funcional con contrato real. |
| 4.2 | Reutilizar `ResolvedIdentity` — el DTO es agnóstico del conector (`tenantSlug` = workspace_id de QBK, `tenantName` = workspace_nombre, `workspaceId` = workspace_id numérico como string). | 4.1 | Misma clase DTO. |
| 4.3 | Manejo de errores: 401 → excepción con código 401; 404 → workspace eliminado (excepción genérica); otros errores → excepción de conexión. | 4.1 | Errores consistentes. |
| 4.4 | Tests unitarios: respuesta exitosa, 401, 404, error de conexión. | 4.1–4.3 | Tests verdes. |

**Entregable verificable:** `QbkIdentityResolver::resolveIdentity(['api_token' => '...'])` devuelve un `ResolvedIdentity` con `workspace_id` y `workspace_nombre`. El flujo de conexión de repositorios funciona con el stub.

**Validación:** tests unitarios + flujo manual de conexión de un repo `qbk` en `/settings`.

---

### Fase 5 — Adaptación de la UI para fuente y confianza

**Objetivo:** que el usuario sepa de qué fuente viene la respuesta y cuál es su nivel de confianza.

**Contrato de confianza:** 0.5 (con resultados) / 0.0 (sin resultados). QBK declara esto como fijo hasta tener búsqueda semántica.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| 5.1 | En el detalle de la pregunta (`question-detail.blade.php`), mostrar el nombre de la fuente conectada (`Kuaforia` o `QuBeKa`) junto a la respuesta. | Fase 2 completada | Badge/etiqueta visible con el nombre del conector. |
| 5.2 | Cuando `confidence <= 0.5`, mostrar un tooltip sutil: "Búsqueda basada en texto — los resultados pueden no ser exhaustivos". No es un warning prominente, es un indicador informativo. | 5.1 | Tooltip visible en hover, sin alarmar al usuario. |
| 5.3 | En la tarjeta de la pregunta en el feed (`question-feed.blade.php`), mostrar la fuente conectada como metadata secundaria (tag pequeño junto al título). | 5.1 | Tag de fuente en el feed. |
| 5.4 | Tests Livewire: verificar que el nombre del conector aparece en el detalle y en el feed para un repo `qbk`. | 5.1–5.3 | Tests verdes. |

**Entregable verificable:** al ver una pregunta conectada a QBK, el usuario ve "QuBeKa" como fuente y un tooltip informativo de confianza baja. Las preguntas de Kuaforia no muestran nada nuevo.

**Validación:** tests Livewire + inspección visual con un repo stub `qbk`.

---

### Fase 6 — Validación del ChangeDetector y cierre

**Objetivo:** confirmar que el `ChangeDetector` funciona sin cambios con respuestas de QBK, y cerrar la implementación con QA completo.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| 6.1 | Test E2E: crear una pregunta con repo `qbk`, simular primera respuesta (hash A), simular segunda respuesta distinta (hash B), verificar que el detector crea la versión 2 y notifica. | Fase 2–4 completadas | Test que valida el flujo completo de vigilancia con QBK. |
| 6.2 | Verificar que el job horario funciona con repos `qbk` (el gate `isDue` y el flujo de re-consulta no dependen del conector). | 6.1 | Job procesa preguntas `qbk` sin cambios. |
| 6.3 | Verificar que el botón "Comprobar ahora" funciona con repos `qbk`. | 6.1 | Botón funciona con el nuevo conector. |
| 6.4 | Test de regresión completa: suite completa debe pasar sin cambios en los tests existentes de Kuaforia. | Todas las fases | Suite verde, sin regresiones. |
| 6.5 | Pint: verificar estilo de código. | Todas las fases | Pint PASS. |

**Entregable verificable:** flujo completo de vigilancia funciona con un repo `qbk`. La suite completa pasa. No hay regresiones en Kuaforia.

**Validación:** suite completa + pint + smoke E2E contra el mock de QBK.

---

## 3. DUDAS Y BLOQUEOS

### Todos los puntos bloqueantes quedaron resueltos

Las respuestas de QuBeKa cerraron todos los puntos que estaban abiertos:
- **B1** (endpoint de consulta): `POST /api/v1/query` — confirmado.
- **B2** (endpoint de identidad): `GET /api/v1/agent/me` — confirmado.
- **NB1–NB5**: todos resueltos con contratos concretos.

**Único punto pendiente (no bloqueante):** QuBeKa aún no tiene el Motor de Consulta construido. Los endpoints están definidos pero no implementados. Esto significa que:
- Las Fases 1–2 y 5 se pueden cerrar sin depender de QuBeKa.
- Las Fases 3–4 se cierran con mock funcional; la validación E2E real queda pendiente hasta que QuBeKa tenga su Motor listo.
- El plan no se bloquea — se construye con contrato confirmado y mock.

---

## 4. ESFUERZO ESTIMADO

| Fase | Esfuerzo estimado | Incertidumbre |
|---|---|---|
| **Fase 1** — Config y registro | S (0.5 d) | Baja — configuración + stubs. |
| **Fase 2** — Routing multi-conector | M (2–3 d) | **Alta** — cambio delicado en flujo crítico. |
| **Fase 3** — QbkService | M (2–2.5 d) | Media-Baja — contrato concreto, patrón repetido de Kuaforia. |
| **Fase 4** — QbkIdentityResolver | S (0.5–1 d) | Baja — patrón repetido, contrato concreto. |
| **Fase 5** — UI fuente/confianza | S (1 d) | Baja — cambios de UI acotados. |
| **Fase 6** — Validación y cierre | S (0.5 d) | Baja — tests de cierre. |
| **TOTAL** | **M (7–9 d)** | La Fase 2 es la de mayor incertidumbre por su impacto en el flujo crítico. |

**Reducción de incertidumbre:** con los contratos confirmados por QuBeKa, las Fases 3 y 4 bajan de "media" a "media-baja" — el contrato es concreto y el patrón es repetido de Kuaforia. La única incertidumbre real es la Fase 2 (routing multi-conector).

---

## 5. FUERA DE ALCANCE

| Elemento | Por qué queda fuera |
|---|---|
| **Motor de Consulta de QBK** | QuBeKa lo construye con `OllamaProvider` + `AiProviderFactory`. |
| **Exposición MCP de QBK** | Fuera de alcance del punto 1. |
| **Búsqueda semántica en QBK** | Roadmap de QuBeKa. |
| **Selector de tipo de conector en la UI** | YAGNI — el `connector_type` se setea automáticamente. |
| **Estructuración automática de contenido** | Ola 1, punto 3. |
| **Experiencia de revisión humana** | Ola 1, punto 4. |
| **Rate limiting compartido** | QBK usa `throttle:api` (60 req/min). Kuestion ajusta si necesita más volumen. |
| **Migración de datos existentes** | No hay preguntas `qbk` que migrar. |
