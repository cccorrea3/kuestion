# Plan de Implementación — Ola 1, Punto 3: Aportar conocimiento desde Kuestion

*Equipo de Kuestion · Agosto 2026*
*Documento de entrada: OLA_1_Punto_3.md*
*Contratos confirmados por QuBeKa: ola1-punto3-preguntas-abiertas.md*

---

## 1. RESUMEN DE ALCANCE

### Qué voy a construir

Transformar la pantalla de entrada de Kuestion (hoy: un campo de texto + botón "Consultar y guardar") en una interfaz con **dos acciones explícitas**: **Preguntar** y **Aportar**.

- **Preguntar** = el flujo ya existente (consulta al Motor de Consulta de QBK, muestra respuesta con fuentes, opcionalmente "Vigilar esta pregunta"). Sin cambios funcionales, solo refactor de UI para separar las dos acciones.
- **Aportar** = flujo nuevo: envía el texto al servicio de clasificación de QuBeKa, muestra estado de espera, y confirma con un mensaje liviano ("Gracias, esto quedó guardado y pendiente de revisión") sin exponer la estructura de nodos.

Además, construyo el mecanismo de resiliencia: si el servicio de QuBeKa falla al recibir el aporte, guardo el texto localmente y ofrezco reintentar — el aporte nunca se pierde.

**Contratos confirmados por QuBeKa:**
- Endpoint: `POST {QUBKA_API_URL}/contribute`
- Auth: `Authorization: Bearer {token}` con scope `api:read` + `api:write`
- Body: `{"texto": "...", "origen": "kuestion", "pregunta_previa": "..."}` (opcional)
- **No** incluye `workspace_id` en el body (se resuelve desde el token)
- Síncrono (~2-5s), respuesta con `session_id`, `status`, `resumen`
- Límite: 2000 chars

### Lo que NO construyo

- La **Capa 2** (aporte que responde a Q/SQ/H existente sin evidencia) — explícitamente fuera de alcance.
- La **revisión humana** (punto 4) — vive del lado de QBK.
- La **edición de la propuesta** desde Kuestion — no se muestra el grafo.
- **Selector de repositorio** en "Aportar" — si solo hay uno, no se muestra.

---

## 2. FASES Y TAREAS

### Fase 1 — Refactor de la interfaz: dos acciones explícitas

**Objetivo:** separar la pantalla de entrada en dos modos claros (Preguntar / Aportar) sin cambiar la lógica de fondo todavía.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| 1.1 | Crear componente Livewire `ContributeAporte` con su vista, separado de `CreateQuestion`. El componente tiene: campo de texto, botón "Aportar", estados `idle`/`analyzing`/`saved`/`error`. | Ninguna | Componente vacío con estados y vista base. |
| 1.2 | Modificar la vista de entrada para mostrar **dos botones** debajo del campo de texto: "Preguntar" (accent, llama al flujo existente) y "Aportar" (outline, llama al flujo nuevo). Cuando solo hay un repo, no se muestra selector. | 1.1 | Vista con dos botones, cada uno con su acción. |
| 1.3 | Agregar ruta `GET /contribute` apuntando al nuevo componente. Ruta separada de `/questions/create` — es más limpio y mantiene los flujos desacoplados. | 1.1 | Ruta registrada en `routes/web.php`. |
| 1.4 | Agregar link "Aportar" en el feed vacío (junto a "Escribe tu primera pregunta") y en el botón "Nueva" del header (dos opciones o dropdown). | 1.2 | Navegación actualizada. |
| 1.5 | Test: verificar que la vista renderiza los dos botones y que cada uno dispara la acción correcta. | 1.1–1.4 | Tests de vista Livewire verdes. |

**Entregable verificable:** al entrar a `/contribute`, el usuario ve el form de aportar. `/questions/create` sigue funcionando igual que antes.

**Validación:** tests Livewire + inspección visual.

---

### Fase 2 — Servicio de clasificación: `QbkContributionService`

**Objetivo:** construir el servicio que llama al endpoint de clasificación de QuBeKa y maneja la respuesta.

**Contrato confirmado:**
```
POST {QUBKA_API_URL}/contribute
Authorization: Bearer {token}
Body: {"texto": "...", "origen": "kuestion", "pregunta_previa": "..."}
Response: {"session_id": 42, "status": "pendiente_revision", "resumen": "..."}
```

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| 2.1 | Crear `App\Services\QbkContributionService` con método `contribute(string $texto, ?string $preguntaPrevia = null): array`. Nota: el `workspace_id` NO va en el body — se resuelve desde el token del repositorio. | Fase 1 completada | Servicio funcional. |
| 2.2 | Obtener el token del repositorio activo del usuario: `$repo->credential['api_token']`. El servicio lo usa para el header `Authorization: Bearer`. | 2.1 | Token resuelto desde el repo. |
| 2.3 | Manejo de errores: 401 → excepción (credencial inválida); 403 → excepción (sin permiso write); 422 → excepción (validación); 5xx/timeout → excepción con mensaje de reintento. | 2.1 | Errores manejados. |
| 2.4 | Tests unitarios: respuesta exitosa, 401, 403, 5xx, timeout, respuesta con campos faltantes. | 2.1–2.3 | Tests verdes. |

**Entregable verificable:** `QbkContributionService::contribute()` llama al endpoint, parsea la respuesta, y devuelve `['session_id' => 42, 'status' => 'pendiente_revision', 'resumen' => '...']`.

**Validación:** tests unitarios + mock local del endpoint.

---

### Fase 3 — Integración del componente `ContributeAporte`

**Objetivo:** conectar el componente Livewire con el servicio, mostrando los estados de espera y confirmación.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| 3.1 | Implementar `ContributeAporte::submit()` — valida el texto (min:10, max:2000), obtiene el repo activo, llama a `QbkContributionService::contribute()`, y maneja la respuesta. | Fase 2 completada | Método funcional con flujo completo. |
| 3.2 | Implementar estados de UI: `idle` (form vacío), `analyzing` (spinner + "Analizando tu aporte..."), `saved` (confirmación: "Gracias, esto quedó guardado y pendiente de revisión" + botón "Hacer otro aporte"), `error` (mensaje + botón "Reintentar" si hay draft). | 3.1 | 4 estados de UI funcionales. |
| 3.3 | Capturar `pregunta_previa`: si el usuario llegó a "Aportar" inmediatamente después de una búsqueda sin resultados en `CreateQuestion`, pasar el texto como query param (`?prev=...`). | 3.1 | Contexto enviado cuando aplica. |
| 3.4 | Placeholder contextual: "Aportar" usa "Ej: El job falla porque el batch del banco no llega antes de las 6am" (en vez de una pregunta). | 3.1 | Placeholder correcto. |
| 3.5 | TestsLivewire: envío exitoso (mock), fallo del servicio (verificar draft + retry), validación de campo vacío/largo. | 3.1–3.4 | Tests verdes. |

**Entregable verificable:** escribir un texto, clickear "Aportar" → "Analizando..." → "Gracias, pendiente de revisión". Si falla → "No pudo enviarse" + reintentar.

**Validación:** tests Livewire + smoke contra mock.

---

### Fase 4 — Persistencia de drafts y retry

**Objetivo:** que ningún aporte se pierda por un fallo del servicio de QuBeKa.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| 4.1 | Crear migración `create_contribution_drafts_table`: `id`, `user_id` (FK), `repository_id` (FK nullable), `texto` (text), `pregunta_previa` (text nullable), `status` (enum: pending_retry, sent, failed), `attempts` (int default 0), `last_error` (text nullable), `timestamps`. | Ninguna | Migración aplicada. |
| 4.2 | Crear modelo `ContributionDraft` con relationships y scopes (`pending`, `forUser`). | 4.1 | Modelo funcional. |
| 4.3 | Integrar en `ContributeAporte::submit()`: si el servicio falla, crear draft con estado `pending_retry`. Si responde bien, marcar como `sent`. | 4.1, 4.2, Fase 3 | Drafts se crean ante fallos. |
| 4.4 | Botón "Reintentar" en la UI: si hay draft pendiente, mostrar el texto precargado con botón de reintento. | 4.3 | Retry funcional. |
| 4.5 | Job de limpieza: eliminar drafts con más de 7 días y estado `pending_retry`/`failed`. | 4.1 | Limpieza automática. |
| 4.6 | Tests: draft se crea ante fallo, retry funciona, limpieza elimina viejos. | 4.1–4.5 | Tests verdes. |

**Entregable verificable:** fallo del servicio → draft guardado → reintentar → envío exitoso. Drafts viejos se limpian.

**Validación:** tests + inspección de tabla `contribution_drafts`.

---

### Fase 5 — Conexión "sin resultados" → "Aportar"

**Objetivo:** cerrar el flujo donde alguien pregunta, no encuentra nada, y le ofrecemos aportar la respuesta.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| 5.1 | En `CreateQuestion`, después de que la consulta devuelva `found: false` (respuesta vacía o sin fuentes), mostrar banner: "No encontramos información sobre esto. ¿Querés aportar lo que sabés?" con botón que lleve a `/contribute?prev={texto}`. | Fase 3 completada | Banner contextual. |
| 5.2 | En `ContributeAporte`, al recibir `?prev=...`, mostrar el texto de la pregunta previa en un campo informativo (no editable). | 3.3 | Contexto visible. |
| 5.3 | Test: verificar que después de búsqueda sin resultados se muestra el banner y el link lleva a `/contribute` con el parámetro correcto. | 5.1, 5.2 | Tests verdes. |

**Entregable verificable:** flujo "pregunto → no encuentro nada → aporto la respuesta" funciona de punta a punta.

**Validación:** tests + flujo manual completo.

---

### Fase 6 — QA, regresión y cierre

**Objetivo:** validar que todo funciona sin romper lo existente.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| 6.1 | Verificar que el flujo de "Preguntar" (CreateQuestion) no cambió. Tests existentes pasan sin cambios. | Todas las fases | Regresión confirmada. |
| 6.2 | Verificar que el sistema de conectores sigue funcionando (routing multi-conector del Punto 1). | Todas las fases | Routing intacto. |
| 6.3 | Test E2E: escribir aporte → enviar → verificar respuesta/confirmación. | Todas las fases | Flujo E2E funcional. |
| 6.4 | Suite completa + pint. | Todas las fases | Verde. |

**Entregable verificable:** dos acciones claras, ambas funcionan, no se pierden datos, sin regresiones.

**Validación:** suite completa + pint + smoke E2E.

---

## 3. DUDAS Y BLOQUEOS

### Todos los puntos bloqueantes quedaron resueltos

- **B1** (endpoint de clasificación): `POST /api/v1/contribute` — confirmado.
- **B2** (scope de token): `api:read` + `api:write` — ya existe, no hace falta scope nuevo.
- **NB1–NB4**: todos resueltos con contratos concretos.

**Único punto pendiente (no bloqueante):** QuBeKa necesita construir el endpoint `POST /api/v1/contribute` (el `AnalisisService` ya existe, pero el endpoint y el campo `pregunta_previa` no). Esto no bloquea el desarrollo — se construye con contrato confirmado y mock.

---

## 4. ESFUERZO ESTIMADO

| Fase | Esfuerzo estimado | Incertidumbre |
|---|---|---|
| **Fase 1** — Refactor UI dos acciones | S-M (1–1.5 d) | Baja — refactor de UI, patrón conocido. |
| **Fase 2** — Servicio de clasificación | S (0.5–1 d) | Baja — contrato concreto, patrón repetido de Kuaforia/QBK. |
| **Fase 3** — Integración componente | M (1.5–2 d) | Media — estados de UI + integración + captura de contexto. |
| **Fase 4** — Persistencia y retry | S (1 d) | Baja — migración + modelo + integración. |
| **Fase 5** — Conexión "sin resultados" → "Aportar" | S (0.5 d) | Baja — banner contextual. |
| **Fase 6** — QA y cierre | S (0.5 d) | Baja — regresión + suite completa. |
| **TOTAL** | **M (5–6.5 d)** | La Fase 3 es la de mayor incertidumbre por la integración de estados. |

**Reducción vs. estimación anterior:** con los contratos confirmados (sincronía, workspace no en body, resumen generado por QuBeKa), las Fases 2 y 3 bajan de complejidad. El total baja de 5.5–7d a 5–6.5d.

---

## 5. FUERA DE ALCANCE

| Elemento | Por qué queda fuera |
|---|---|
| **Capa 2** (aporte que responde a Q/SQ/H existente) | Explícitamente fuera de alcance. |
| **Revisión humana** (punto 4) | Otro documento, otro alcance. |
| **Edición de la propuesta desde Kuestion** | No se muestra el grafo ni la estructura. |
| **Selector de repositorio en "Aportar"** | Si solo hay un repo, no se muestra. |
| **Fusión con nodos casi-duplicados** | Capacidad separada, futura. |
| **Notificación de revisión completada** | El punto 4 definirá si hay notificación. |
| **Historial de aportes del usuario** | No se construye vista "mis aportes". |
| **Construcción del endpoint en QuBeKa** | QuBeKa lo construye con `AnalisisService` + endpoint nuevo. |
