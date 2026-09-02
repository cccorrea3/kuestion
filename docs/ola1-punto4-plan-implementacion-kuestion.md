# Plan de Implementación — Ola 1, Punto 4: Gate humano de revisión desde Kuestion

*Equipo de Kuestion · Agosto 2026*
*Documento de entrada: OLA_1_Punto_4.md*
*Contratos confirmados por QuBeKa: ola1-punto4-preguntas-abiertas.md*

---

## 1. RESUMEN DE ALCANCE

### Qué voy a construir

La experiencia de revisión humana para aportes que el usuario hizo desde Kuestion. Cuando alguien escribe un aporte (punto 3) y la sesión es **simple** (≤2 nodos, confianza ≥0.5, sin conflictos), Kuestion muestra una confirmación liviana **sin salir de la app**. Si es **compleja**, redirige a la pantalla de Revisión Humana de QBK.

**Decisión cerrada:** autoconfirmación del mismo autor es válida (sección 1.3 del documento).

**Contratos confirmados por QuBeKa:**
- Detalle: `GET {QUBKA_API_URL}/sesiones-analisis/{sessionId}`
- Aprobar: `POST .../approve` (acepta `textos_ajustados` opcional)
- Rechazar: `POST .../reject`
- Criterio simple: ≤2 nodos + confianza ≥0.5 + sin conflictos
- Redirección compleja: `https://{QUBKA_URL}/analisis/{sessionId}/revision` (ya existe)

### Lo que NO construyo

- La pantalla completa de Revisión Humana de QBK — ya existe. Kuestion solo redirige.
- La clasificación automática (punto 3) — ya construida.
- La promoción de nodos al grafo activo — lo hace QBK.
- La política de retención de sesiones rechazadas — decisión pendiente de QBK.

---

## 2. FASES Y TAREAS

### Fase 1 — Servicios de QBK: detalle, aprobar, rechazar

**Objetivo:** construir los métodos en `QbkContributionService` que llaman a los tres endpoints de QBK.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| 1.1 | Agregar `getSession(int $sessionId): array` a `QbkContributionService` — llama a `GET /api/v1/sesiones-analisis/{sessionId}` y devuelve el array con `session_id`, `status`, `is_simple`, `pregunta_previa`, `nodes`, `resumen`, `created_at`, `workspace_nombre`. | Ninguna (endpoint confirmado por QBK) | Método funcional. |
| 1.2 | Agregar `approve(int $sessionId, ?array $textosAjustados = null): array` — llama a `POST .../approve` con body opcional `{"textos_ajustados": {...}}`. Devuelve `success`, `session_id`, `status`, `nodos_creados`, `enlaces_creados`. | Ninguna | Método funcional. |
| 1.3 | Agregar `reject(int $sessionId): array` — llama a `POST .../reject`. Devuelve `success`, `session_id`, `status`. | Ninguna | Método funcional. |
| 1.4 | Manejo de errores para los tres métodos: 401 → credencial inválida; 403 → sin permisos; 404 → sesión no encontrada; 422 → validación; 5xx → error de servicio. | 1.1–1.3 | Errores manejados. |
| 1.5 | Tests unitarios: los tres métodos con respuesta exitosa y cada código de error. | 1.1–1.4 | Tests verdes. |

**Entregable verificable:** puedo obtener el detalle de una sesión, aprobarla (con o sin ajustes) y rechazarla via API.

**Validación:** tests unitarios + mock de los endpoints.

---

### Fase 2 — Pantalla de confirmación liviana

**Objetivo:** que el usuario vea qué se propone en su aporte y pueda aprobar/ajustar/descartar sin salir de Kuestion.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| 2.1 | Crear componente Livewire `ContributionReview` con vista, ruta `GET /contributions/{sessionId}/review`. El componente carga el detalle vía `QbkContributionService::getSession()`. | Fase 1 completada | Componente con datos cargados. |
| 2.2 | Mostrar el contenido propuesto en **lenguaje natural**: texto de cada nodo en lenguaje claro, sin etiquetas técnicas. Mostrar `pregunta_previa` como contexto si existe ("Tu aporte responde a: ¿...?"). | 2.1 | Vista con contenido visible. |
| 2.3 | Botones: **Aprobar** (verde), **Descartar** (rojo), **Editar texto** (outline). Editar permite modificar el texto de los nodos antes de aprobar. | 2.1 | 3 botones funcionales. |
| 2.4 | `approve()`: llama a `QbkContributionService::approve()` con textos ajustados si los hay. Muestra confirmación ("Tu aporte fue guardado en tu base de conocimiento"). Redirige al feed o a la pregunta relacionada. | 1.2, 2.1 | Aprobación funcional. |
| 2.5 | `reject()`: llama a `QbkContributionService::reject()`. Muestra confirmación ("Tu aporte fue descartado"). Redirige al feed. | 1.3, 2.1 | Rechazo funcional. |
| 2.6 | `edit()`: formulario inline con los textos de los nodos editables. Al aprobar, envía los textos ajustados. Si el ajuste es estructural (cambiar tipo, reasignar relaciones), redirigir a QBK (sección 4.3 del documento). | 2.3 | Edición de texto funcional. |
| 2.7 | Verificar `is_simple` al cargar: si la sesión es compleja, no mostrar la confirmación — redirigir a QBK (`https://{QUBKA_URL}/analisis/{sessionId}/revision`). | 2.1 | Redirección para sesiones complejas. |
| 2.8 | TestsLivewire: sesión simple se muestra, aprobar funciona, rechazar funciona, editar funciona, sesión no encontrada muestra error, sesión compleja redirige a QBK. | 2.1–2.7 | Tests verdes. |

**Entregable verificable:** `/contributions/{sessionId}/review` muestra el contenido, permite aprobar/ajustar/descartar. Sesiones complejas redirigen a QBK.

**Validación:** tests Livewire + smoke manual contra mock.

---

### Fase 3 — Indicador de pendientes

**Objetivo:** que el usuario sepa que tiene aportes pendientes de confirmar.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| 3.1 | Guardar `session_id` en `contribution_drafts` (ya creado en el punto 3) cuando el aporte sea exitoso. Campo `qbk_session_id` (nullable) en la tabla, que se completa con el `session_id` de la respuesta de QBK. | Fase 1 del punto 3 completada | Draft con `qbk_session_id` guardado. |
| 3.2 | Crear método `pendingContributions()` en `ContributionDraft` que filtre drafts con `qbk_session_id NOT NULL` y `status = 'sent'` (o crear un nuevo estado `pending_review`). Necesito decidir el estado: ¿reutilizo `sent` o creo `pending_review`? **Decisión:** crear estado `pending_review` — es más explícito y no colisiona con `sent` (que ya se usa para aportes que no tienen sesión pendiente). | 3.1 | Query de pendientes funcional. |
| 3.3 | Agregar badge en el header (junto a las notificaciones existentes): "N aportes pendientes de confirmar". Al clickear, llevar al detalle de la sesión más reciente o a una vista filtrada. | 3.2 | Badge visible. |
| 3.4 | Después de aprobar/rechazar, actualizar el estado del draft a `reviewed`. | 2.4, 2.5 | Estado actualizado. |
| 3.5 | Tests: badge muestra conteo correcto, approve actualiza el estado, draft pendiente se crea al aportar. | 3.1–3.4 | Tests verdes. |

**Entregable verificable:** el usuario ve "1 aporte pendiente" en el header. Al clickear, llega a la revisión. Después de aprobar/rechazar, el badge desaparece.

**Validación:** tests + inspección visual.

---

### Fase 4 — QA, regresión y cierre

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| 4.1 | Verificar flujo completo: aportar → session_id guardado → ir a revisar → aprobar → verificar que QBK recibió la promoción. | Todas las fases | Flujo E2E funcional. |
| 4.2 | Verificar que "Preguntar" y "Aportar" (puntos 1 y 3) no se tocaron. Tests existentes pasan. | Todas las fases | Regresión confirmada. |
| 4.3 | Verificar que sesiones complejas redirigen a QBK correctamente. | Fase 2 | Redirección funcional. |
| 4.4 | Suite completa + pint. | Todas las fases | Verde. |

---

## 3. PRUEBAS FUNCIONALES Y DE INTEGRACIÓN

Los tests unitarios y de integración con mocks validan que el código hace lo que dice — pero no que el flujo completo tenga sentido de punta a punta para una persona real. Para cada fase, se incluye un checklist de prueba funcional que recorre el flujo como lo haría un usuario.

**Importante sobre mocks vs. servicio real:** QuBeKa aún no construyó los endpoints REST de detalle/aprobar/rechazar. Las pruebas contra el servicio real solo serán posibles cuando esos endpoints existan. Mientras tanto, se valida contra mocks y se documenta explícitamente qué queda pendiente de prueba con servicio real.

---

### Fase 1 — Servicios de QBK (detalle, approve, reject)

| # | Prueba funcional | Cómo se verifica | Mock o Real |
|---|---|---|---|
| P1.1 | `getSession()` devuelve el detalle correcto de una sesión existente con nodos, relaciones y `pregunta_previa`. | Llamada al servicio + aserción del array devuelto (nodos, status, is_simple, pregunta_previa). | Mock (QPK aún no tiene el endpoint REST) |
| P1.2 | `approve()` sin `textos_ajustados` envía el body correcto y devuelve `nodos_creados > 0`. | Llamada al servicio + aserción del body enviado (sin campo `textos_ajustados`) + respuesta. | Mock |
| P1.3 | `approve()` con `textos_ajustados` envía el body completo con el mapa de nodos ajustados. | Llamada al servicio + aserción del body enviado (con `textos_ajustados`) + respuesta. | Mock |
| P1.4 | `reject()` envía una petición vacía y devuelve `status: rechazada`. | Llamada al servicio + aserción de respuesta. | Mock |
| P1.5 | Error 401 → se lanza `KuaforiaException` con mensaje "Credencial inválida". | Llamada al servicio con token inválido + catch de excepción. | Mock (HTTP 401) |
| P1.6 | Error 404 → se lanza excepción con mensaje "Sesión no encontrada". | Llamada con ID inexistente + catch. | Mock |
| P1.7 | Error 5xx → se lanza excepción con mensaje de error de servicio. | Mock que devuelve HTTP 500 + catch. | Mock |

**Pendiente de prueba con servicio real:** cuando QuBeKa publique los endpoints, repetir P1.1–P1.7 con la URL real y verificar que la respuesta tiene la estructura exacta del contrato (`ola1-punto4-preguntas-abiertas.md`).

---

### Fase 2 — Pantalla de confirmación liviana

| # | Prueba funcional | Cómo se verifica | Mock o Real |
|---|---|---|---|
| P2.1 | Navegar a `/contributions/{sessionId}/review` carga el componente y muestra el contenido propuesto (texto de cada nodo en lenguaje claro, sin etiquetas técnicas). | Abrir la URL en el navegador + inspección visual del contenido. | Mock |
| P2.2 | Si la sesión tiene `pregunta_previa`, se muestra "Tu aporte responde a: ¿...?" arriba del contenido. | Verificar en la vista que el contexto aparece. | Mock |
| P2.3 | **Botón Aprobar:** al hacer clic, se llama a `approve()` de QBK, se muestra "Tu aporte fue guardado en tu base de conocimiento" y se redirige al feed. | Hacer clic en Aprobar + verificar mensaje de confirmación + verificar redirección. | Mock |
| P2.4 | **Botón Descartar:** al hacer clic, se llama a `reject()`, se muestra "Tu aporte fue descartado" y se redirige al feed. | Hacer clic en Descartar + verificar mensaje + redirección. | Mock |
| P2.5 | **Botón Editar texto:** al hacer clic, se muestran los textos de los nodos en campos editables. Al aprobar, se envían los textos ajustados a `approve()`. | Editar el texto de un nodo + aprobar + verificar que el body enviado contiene `textos_ajustados`. | Mock |
| P2.6 | Sesión **compleja** (is_simple = false): el componente redirige a `https://{QUBKA_URL}/analisis/{sessionId}/revision` sin mostrar la pantalla de confirmación. | Crear mock con `is_simple: false` + navegar a review + verificar redirección. | Mock |
| P2.7 | Sesión no encontrada (404): se muestra un mensaje de error claro ("Sesión no encontrada o expirada"). | Navegar con ID inexistente + verificar mensaje de error. | Mock |
| P2.8 | Botones deshabilitados durante la carga (estado `analyzing`): mientras se procesa approve/reject, los botones muestran spinner y no se pueden clickear dos veces. | Hacer clic en Aprobar + verificar que el botón se deshabilita durante la petición. | Mock |

**Pendiente de prueba con servicio real:** cuando los endpoints existan, repetir P2.3–P2.5 con aportes reales para verificar que la promoción de nodos funciona de punta a punta (aportar → aprobar → nodo visible en QuBeKa).

---

### Fase 3 — Indicador de pendientes

| # | Prueba funcional | Cómo se verifica | Mock o Real |
|---|---|---|---|
| P3.1 | Al completar un aporte exitoso (status `saved`), se guarda un draft con `qbk_session_id` y estado `pending_review`. | Verificar en la BD que el draft tiene los campos correctos después de un aporte exitoso. | Mock |
| P3.2 | El badge en el header muestra "N aportes pendientes de confirmar" con el conteo correcto. | Aportar 1 vez → badge muestra "1 aporte pendiente". Aportar 2 veces → "2 aportes pendientes". | Mock |
| P3.3 | Al clickear el badge, se navega a la pantalla de revisión de la sesión más reciente. | Hacer clic en el badge + verificar redirección. | Mock |
| P3.4 | Después de aprobar o rechazar una sesión, el badge se actualiza (si quedaban pendientes, el conteo baja; si no quedaban, el badge desaparece). | Aprobar 1 sesión pendiente + verificar que el badge desaparece. | Mock |
| P3.5 | Un draft con `qbk_session_id` en estado `sent` (no `pending_review`) NO se muestra en el badge — solo los pendientes. | Verificar que drafts en estado `sent` no aparecen en el conteo. | Mock |

**Pendiente de prueba con servicio real:** verificar que el `session_id` recibido al aportar realmente corresponde a una sesión visible en QuBeKa y que al aprobar desde Kuestion, la sesión aparece como "aprobada" en QBK.

---

### Fase 4 — QA, regresión y cierre

| # | Prueba funcional | Cómo se verifica |
|---|---|---|
| P4.1 | **Flujo E2E completo:** crear un aporte desde Kuestion → verificar que aparece en el badge como pendiente → ir a revisión → aprobar → verificar que QuBeKa recibió la promoción (nodo visible en QBK). | Flujo manual con Kuestion y QBK abiertos, paso a paso. |
| P4.2 | **Regresión "Preguntar":** hacer una pregunta desde Kuestion con QuBeKa conectado → verificar que la respuesta se muestra correctamente (found: true / found: false). | Flujo manual + verificación de tests existentes de CreateQuestion. |
| P4.3 | **Regresión "Aportar" (punto 3):** hacer un aporte nuevo → verificar que la clasificación de QBK funciona (nodos Q/SQ/H/N-K creados en sandbox). | Flujo manual + verificación de tests existentes de ContributeAporte. |
| P4.4 | **Sesiones complejas redirigen a QBK:** crear una sesión compleja mock (>2 nodos o confianza <0.5) → verificar que Kuestion redirige a la URL de revisión de QBK. | Crear mock + verificar redirección en el navegador. |
| P4.5 | **Múltiples sesiones pendientes:** aportar 3 veces → verificar que el badge muestra "3 aportes pendientes" → aprobar 2 → badge muestra "1" → aprobar la última → badge desaparece. | Flujo manual secuencial. |
| P4.6 | **Sesión ya procesada:** si el usuario intenta revisar una sesión que ya fue aprobada o rechazada, Kuestion muestra un mensaje claro ("Esta sesión ya fue procesada") en vez de mostrar los botones de acción. | Navegar a review con session_id de una sesión ya procesada + verificar mensaje. |
| P4.7 | **Suite completa:** correr `php artisan test` y confirmar que todos los tests (unitarios + integración) pasan sin regresiones respecto al estado anterior del Punto 4. | `php artisan test` |

**Nota sobre prueba con servicio real en Fase 4:** la prueba E2E del P4.1 requiere que los endpoints de QuBeKa existan y estén funcionales. Si no existen aún, se valida con mock y se documenta como "pendiente de validación E2E con servicio real" en el cierre.

---

### Resumen de disponibilidad de servicio real por fase

| Fase | Servicio real disponible | Qué se prueba con mock | Qué queda pendiente de prueba real |
|---|---|---|---|
| **Fase 1** | ❌ No (endpoints no construidos por QBK) | Los 7 tests del servicio (P1.1–P1.7) | Repetir contra servicio real cuando exista |
| **Fase 2** | ❌ No (depende de Fase 1) | La pantalla completa (P2.1–P2.8) | Aprobar/rechazar con datos reales |
| **Fase 3** | ❌ No (depende de Fase 1) | Badge y estados de draft (P3.1–P3.5) | Verificar session_id real en badge |
| **Fase 4** | ⚠️ Parcial (si QBK ya tiene los endpoints) | Regresión de puntos anteriores (P4.2–P4.6) | Flujo E2E completo (P4.1) |

---

## 4. DUDAS Y BLOQUEOS

### Todos los puntos bloqueantes quedaron resueltos

- **B1** (detalle de sesión): `GET /api/v1/sesiones-analisis/{sessionId}` — confirmado, con contrato completo.
- **B2** (aprobar/rechazar): `POST .../approve` + `POST .../reject` — confirmados, con soporte para `textos_ajustados`.
- **B3** (criterio simple/compleja): ≤2 nodos + confianza ≥0.5 + sin conflictos — confirmado.
- **NB1** (`pregunta_previa`): se incluye en el endpoint de detalle — confirmado.

**Único punto pendiente (no bloqueante):** QBK necesita construir los tres endpoints (el `AnalisisService` y `PromocionService` ya existen, pero los endpoints REST no). No bloquea el desarrollo — se construye con contrato confirmado y mock.

---

## 5. ESFUERZO ESTIMADO

| Fase | Esfuerzo estimado | Incertidumbre |
|---|---|---|
| **Fase 1** — Servicios de QBK (detalle, approve, reject) | S (1 d) | Baja — contrato concreto, patrón repetido. |
| **Fase 2** — Pantalla de confirmación liviana | M (2–2.5 d) | Media — la UI es directa, pero la integración de estados (simple/compleja) y la edición de texto agregan complejidad. |
| **Fase 3** — Indicador de pendientes | S (0.5–1 d) | Baja — reutiliza `contribution_drafts` del punto 3. |
| **Fase 4** — QA y cierre | S (0.5 d) | Baja — regresión + suite completa. |
| **TOTAL** | **M (4–5 d)** | La Fase 2 es la de mayor incertidumbre por la integración de estados y edición. |

**Reducción vs. estimación anterior:** con los contratos confirmados (incluido el criterio simple/compleja y el soporte de `textos_ajustados`), la incertidumbre baja. El total se mantiene en 4–5d pero con menos riesgo.

---

## 6. FUERA DE ALCANCE

| Elemento | Por qué queda fuera |
|---|---|
| **Pantalla completa de Revisión Humana de QBK** | Ya existe. Kuestion solo redirige. |
| **Política de retención de sesiones rechazadas** | Decisión pendiente de QBK. |
| **Notificación por email de aportes pendientes** | QBK ya tiene su centro de notificaciones. Kuestion muestra indicador propio. |
| **Edición estructural de nodos** (cambiar tipo, reasignar relaciones) | Si el usuario necesita eso, se redirige a QBK. |
| **Multi-revisor** (otro miembro apruebe) | Posibilidad adicional para equipos, no requisito. Queda para cuando exista el rol en QBK. |
| **Construcción de los endpoints en QBK** | QuBeKa los construye. Kuestion los consume. |
