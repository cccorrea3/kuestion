# Plan de Implementación — Ola 3, Punto 1.1: Advertencia de consecuencias al aprobar subconjunto

*Equipo de Kuestion · Septiembre 2026*
*Documento de entrada: `definicion_OLA3_Punto_1_1.md` (definición fina cerrada por producto — no se cuestiona el alcance).*
*Plan hermano (QuBeKa): `../QuBeKa/qubeka/plan-advertencia-subconjunto-ola3-p11-v1.md` — leído y contrastado; sus propuestas se citan como propuestas, no como cerradas.*

---

## 1. RESUMEN DE ALCANCE

Kuestion agrega un **paso de evaluación previo a la promoción** en la bandeja de revisión: cuando el usuario hace clic en "Aprobar seleccionados", Kuestion consulta a QuBeKa qué consecuencias estructurales tendría promover exactamente ese subconjunto. Si no hay consecuencias, la promoción fluye igual que hoy, sin paso intermedio. Si hay consecuencias, se muestran las advertencias (informan, no bloquean) y el usuario confirma la aprobación o vuelve a la selección **conservándola intacta**. La evaluación corre **solo al aprobar** — nunca durante la selección. Toda la lógica de estructura del grafo vive en QuBeKa (§1.4 del documento de origen): Kuestion no calcula nada, llama y renderiza.

Construimos tres cosas: (1) el cliente HTTP del mecanismo de evaluación de QuBeKa, (2) el estado y la rama en `ReviewTray::aprobarSeleccionados()` (evaluar → promoción directa / advertencia → confirmar o volver), y (3) la UI del paso de advertencia con el copy, dentro del panel del documento expandido que ya existe del Punto 1. El flujo "aprobar todo" (sin subconjunto) no cambia.

**Preguntas abiertas declaradas antes de las fases** (ninguna se resuelve por su cuenta):

| # | Pregunta | Estado |
|---|---|---|
| P1 | **Payload final del mecanismo de evaluación.** QuBeKa propone endpoint `POST /sesiones-analisis/{id}/evaluar-subconjunto` respondiendo `{consecuencias: [{tipo, nodos_afectados, descripcion}], total, subconjunto_evaluado}` con `tipo ∈ {nodo_huerfano, enlace_perdido, sugerencia_no_resuelta}` (Q4 de su plan, contrato v1.8 §2.6). | **Propuesta confirmada por Kuestion en la ronda §4.3** (mecanismo + payload, sin objeciones de fondo; la aclaración menor sobre `nodos_afectados` de `enlace_perdido` quedó resuelta — NB5). El bloqueo para Fases B–D se levanta al publicarse el contrato v1.8; mientras tanto no se conecta parsing (spec §3.B.1). |
| P2 | **Comportamiento ante fallo de la evaluación** (spec §4.1, decisión final de producto). Propuesta de Kuestion: seguir la recomendación de producto — no bloquear; mostrar aviso intermedio "No pudimos verificar las consecuencias de esta selección" con opciones aprobar-igual / reintentar. Viabilidad UX confirmada: es el mismo patrón de fallo visible ya usado en la bandeja. | Viable desde Kuestion; decisión comunicada a QuBeKa en la ronda §4.3. **✅ Confirmada por producto (2026-09-16).** |
| P3 | **Forma del paso intermedio y copy de botones** (spec §4.2 — decisión delegada explícitamente a Kuestion dentro del marco §1.5). Propuesta: **panel dentro del panel del documento expandido** de la bandeja (no modal, no página intermedia), botones "Confirmar aprobación" (neutro, evita "de todas formas") y "Volver a la selección". | Decisión de Kuestion registrada y comunicada a QuBeKa en la ronda §4.3. **✅ Confirmada por producto (2026-09-16).** |
| P4 | **Latencia agregada**: un HTTP extra al aprobar subconjunto (solo ese botón). Asumido por el spec (§1.2: evaluación solo al aprobar). | Declarado; sin acción. |

---

## 2. FASES Y TAREAS

### Fase A — Contrato y coordinación (ronda §4.3)

**Objetivo:** congelar el payload con QuBeKa y validar el endpoint real antes de escribir código.

| Tarea | Detalle |
|---|---|
| **A.1** | Ronda de coordinación: confirmar mecanismo (endpoint dedicado, propuesta T2.1 de QuBeKa), estructura del payload (Q4) y los supuestos técnicos que definen qué advierte la UI y por lo tanto el copy: huérfano por **padre inmediato** (NB1/Q1), sugerencia solo si **ambos extremos quedan aprobados** (NB2/Q2), estado requerido igual a `approve` (NB3/Q3). |
| **A.2** | Validar el endpoint real contra QuBeKa `:8000` (curl con sesión documento real del Punto 1): los tres tipos, el caso sin consecuencias (`consecuencias: []`), y el 422 de validación (debe usar el mismo mensaje que `approve`, para que Kuestion reúsa la misma lista de nodos en evaluar y aprobar). |
| **A.3** | Documentar las decisiones P2 y P3 con la confirmación (o ajuste) de producto. |

**Dependencias:** plan de QuBeKa en ejecución (su Fase 2 produce contrato `CONTRATO_API_REVISION.md` v1.8 §2.6). — **Estado (ronda §4.3, 2026-09-16): A.1 y A.3 ejecutados** — mecanismo y payload confirmados; **P2/P3 confirmadas por producto**. Restan A.2 (curl real al endpoint cuando QuBeKa despliegue, ~1,5 días) y la publicación de v1.8.
**Entregable verificable:** respuesta real del endpoint pegada en el cierre (payload v1.8 confirmado) + decisiones P2/P3 documentadas.
**Validación:** curl real contra `:8000`. Sin eso, no se arranca la Fase B (es el bloqueo P1).

### Fase B — Cliente de evaluación en el servicio

**Objetivo:** `QbkContributionService::evaluarSubconjunto()` con el patrón de errores del servicio.

| Tarea | Detalle |
|---|---|
| **B.1** | Método `evaluarSubconjunto(int|string $sessionId, array $nodosAprobados, ?array $credential): array` — POST al endpoint v1.8, sobre el sobre real `{success, data}`, con el manejo 401/403/404/422/5xx/timeout ya usado en `approve()` del mismo servicio (mismo credential/patrón de header). |
| **B.2** | Errores distinguibles: fallo de **transporte** (QuBeKa caído/timeout/5xx) ≠ 422 de **validación** (array vacío o ids ajenos). La UI los trata distinto en Fase C (P2); el 422 es de dominio y llega con mensaje legible de QuBeKa. |
| **B.3** | Tests con `Http::fake` fiel al payload confirmado en Fase A: sin consecuencias, un test por cada tipo, caso combinado (varias consecuencias en una evaluación), 422, 403, 404, 5xx y timeout. Patrón de fakes ya aprendido en este repo: **un solo fake secuencial por estado** — `Http::fake()` acumula stubs y el primero matchea; URLs con `*` y esquema completos. |

**Dependencias:** Fase A (payload congelado; ver P1).
**Entregable verificable:** cliente probado contra mock v1.8 **y** contra el servicio real con una sesión real.
**Validación:** tests de B.3 + curl real.

### Fase C — Flujo de aprobación con evaluación (lógica)

**Objetivo:** `ReviewTray::aprobarSeleccionados()` evalúa antes de promover y ramifica.

| Tarea | Detalle |
|---|---|
| **C.1** | `aprobarSeleccionados()`: clic → evaluar el subconjunto seleccionado → sin consecuencias: el código actual de aprobación corre igual (regresión del criterio de cierre #4 del spec); con consecuencias: estado `advertenciaPendiente` + datos de advertencia, **sin** ejecutar la promoción. |
| **C.2** | `confirmarAprobacionConAdvertencia()`: ejecuta el `approve` existente con la misma lista que se evaluó (misma selección, cero mutaciones entre evaluar y aprobar). |
| **C.3** | `volverASeleccion()`: limpia el estado de advertencia sin tocar los checkboxes ni la selección — criterio de cierre #5 del spec (selección intacta, sin resetear). |
| **C.4** | Fallo de la evaluación (P2, **confirmado por producto 2026-09-16**): estado `evaluacionFallida` con el aviso intermedio y opciones aprobar igual / reintentar. |
| **C.5** | "Aprobar todo" y el resto de la bandeja: **sin cambios** (spec §2). La evaluación existe solo en el camino de subconjunto. |
| **C.6** | Tests Livewire: (1) sin consecuencias — no hay paso intermedio y el approve sale igual que hoy; (2) con consecuencias + confirmación — `approve` con el payload exacto de la selección; (3) con consecuencias + cancelación — la selección queda intacta; (4) fallo de la evaluación — según P2. |

**Dependencias:** Fase B.
**Entregable verificable:** los cuatro caminos demostrables por test (no "código escrito").
**Validación:** tests Livewire de C.6.

### Fase D — UI y copy del paso de advertencia

**Objetivo:** la advertencia visible, legible y consistente con los patrones de la bandeja.

| Tarea | Detalle |
|---|---|
| **D.1** | Bloque de advertencia dentro del panel del documento expandido (P3): listado de consecuencias con el `tipo` traducido a encabezado legible y la `descripcion` de QuBeKa tal como viene (QuBeKa la redacta; Kuestion no la reescribe). Los nodos afectados se citan por texto, igual que las contradicciones del Punto 1. En `enlace_perdido`, `nodos_afectados` trae **ambos extremos** (NB5 confirmado) — el panel cita la relación completa sin segunda llamada. |
| **D.2** | Copy y tono: informativo, no alarmante (spec §1.5); botones "Confirmar aprobación" / "Volver a la selección" (P3). Encabezados por tipo: huérfano → se promovería como raíz; enlace perdido → el enlace no se recrea; sugerencia no resuelta → quedarían como nodos separados. |
| **D.3** | Aviso de fallo de evaluación (P2) con su copy, distinto del aviso de consecuencias (no mezclar "no se pudo verificar" con "hay consecuencias"). |
| **D.4** | Regresión visual: el bloque solo aparece con consecuencias o con fallo; el flujo feliz no agrega nada a la vista. |
| **D.5** | Tests de render (`assertSee` del copy, de las descripciones devueltas y de los botones). |

**Dependencias:** Fase C (P2/P3 ya confirmadas por producto — 2026-09-16).
**Entregable verificable:** panel con advertencias visible en la bandeja real, con copy revisado.
**Validación:** tests de D.5 + verificación visual de Fase E.

### Fase E — QA integral, E2E real y cierre

| Tarea | Detalle |
|---|---|
| **E.1** | Suite completa en verde + Pint solo con archivos del punto (regresión general). |
| **E.2** | `npm run build` + verificación de las clases nuevas en el **CSS compilado/servido** (ítem obligatorio §3). |
| **E.3** | Verificación visual en navegador real con devtools (ítem obligatorio §3): los tres caminos en `:8001` — flujo feliz sin panel, advertencias visibles con botones operativos, cancelación conservando la selección. Servicios con `scripts/dev-qbk.sh`. Si no es posible, pendiente declarado con motivo. |
| **E.4** | E2E real contra QuBeKa: sesión documento real → subconjunto con consecuencias → advertencias en pantalla → confirmar → `promocionada` con el subconjunto exacto; y un caso sin consecuencias aprobándose directo. Si el proveedor de IA de QuBeKa no responde (precedente del Punto 1), reintentar; si no se logra, pendiente declarado. |
| **E.5** | Documentos de cierre y resumen (formato de los anteriores) + commit + push. |

**Dependencias:** Fase D cerrada.
**Entregable verificable:** criterio de cierre §5 del spec, ítems 2 y 5 (los que son de Kuestion), demostrados.
**Validación:** checklist FE de la sección 3.

---

## 3. PRUEBAS FUNCIONALES Y DE INTEGRACIÓN

**Fase A — checklist FA (servicio real):**
1. `POST /sesiones-analisis/{id}/evaluar-subconjunto` con sesión documento real → 200 con payload v1.8; guardar la respuesta como fixture del contrato.
2. Subconjunto completo (todos los nodos) → `consecuencias: []`, `total: 0`.
3. `nodos_aprobados` vacío o con ids ajenos → 422 con el mismo mensaje que `approve` (consistencia de validación, reuso de la misma lista).
4. Token de otro workspace → 403 legible; sesión inexistente → 404 legible; sesión cerrada → 422 (NB3/Q3).

**Fase B — mock v1.8 + curl real:** tests de B.3 (campos y tipos exactos del payload congelado, no asumidos). El servicio real ya se validó en FA; B re-verifica el camino del cliente (sobre `{success, data}`, códigos de error mapeados).

**Fase C — tests de los 4 caminos (C.6):** el camino feliz **no** agrega paso intermedio (regresión #4); confirmación ejecuta `approve` con el payload exacto; cancelación conserva la selección (regresión #5); fallo según P2.

**Fase D — render:** advertencias legibles con textos de nodos reales, botones visibles y operativos; flujo feliz sin rastro del bloque.

**Fase E — checklist FE (E2E como persona real):**
1. Abrir la bandeja con una sesión documento real pendiente → expandir el documento → deseleccionar un nodo padre → "Aprobar seleccionados" → advertencia visible con el huérfano → "Volver a la selección" → checkboxes intactos.
2. Repetir → "Confirmar aprobación" → sesión `aprobada` → luego `promocionada` con el subconjunto exacto (verificación en QuBeKa real).
3. Selección completa → "Aprobar seleccionados" → promoción directa, sin panel (regresión #4).
4. (Con QuBeKa caído o forzando timeout) → aviso de fallo según P2, sin pantalla congelada ni estado silencioso.

**Ítems obligatorios del framework, por fase:**
- **Rebuild de assets y CSS compilado:** Fases D/E — `npm run build` y verificación de cada clase nueva en el bundle (con variantes, que en el CSS van escapadas — lección de los cierres anteriores).
- **Verificación visual en navegador real:** Fase E.3 con devtools; no se declara cerrada por tests.
- **Compatibilidad con versiones instaladas:** Livewire 4 — `redirect()` existe, `redirectExternal()` no; sin métodos nuevos de Livewire ni de Laravel más allá de los ya usados por la bandeja. `Http::fake()` **acumula** stubs y el primero matchea (verificado contra vendor en Ola 3 P1); `Http::timeout()` disponible. Nada nuevo en `composer.json`.
- **Fallo visible y claro en runtime:** el fallo de la evaluación tiene estado y copy propios (P2/D.3); el 422 de validación muestra el mensaje de QuBeKa; nunca un spinner eterno ni un click sin efecto (regla operativa 3 del feedback de Ola 2 P1).
- **Prueba contra el servicio real:** FA y FE contra QuBeKa `:8000` con `scripts/dev-qbk.sh`. Si QuBeKa no tiene aún el endpoint desplegado, la Fase A queda en espera activa declarada (P1) y no se simula un contrato.

---

## 4. DUDAS Y BLOQUEOS

**Bloqueante:**
- **P1** — sin contrato v1.8 publicado (y endpoint real validado, A.2) no se conecta el parsing de las Fases B–D. El spec §3.B.1 lo exige expresamente. **La propuesta de payload ya está confirmada por Kuestion** (ronda §4.3, sin objeciones de fondo): el bloqueo es de timing de QuBeKa, no de decisión.

**No bloqueantes (avanzamos con supuesto declarado, se confirman antes de cerrar la fase correspondiente):**
- **NB1–NB3 de QuBeKa** (padre inmediato, sugerencia solo con ambos extremos aprobados, estado requerido): no cambian código de Kuestion, solo el copy de los encabezados por tipo (D.2). Si la ronda los ajusta, el cambio es de texto.
- **P2 (fallo de la evaluación):** **confirmada por producto (2026-09-16)** — no bloquear + aviso intermedio con aprobar igual / reintentar (C.4/D.3). Sin pendientes.
- **P3 (forma del paso):** **confirmada por producto (2026-09-16)** — panel dentro del panel del documento expandido, no modal; botones "Confirmar aprobación" / "Volver a la selección". Sin pendientes.
- **Renombres del payload:** si la ronda §4.3 renombra campos respecto de Q4, el ajuste toca un único punto (B.1) + fixtures de tests.
- **NB5 — `nodos_afectados` en `enlace_perdido`:** **✅ Confirmado por QuBeKa (2026-09-16):** incluye ambos extremos (aprobado y descartado) con id + texto; ejemplo fijado en v1.8 §2.6. D.1 puede enriquecer el panel sin segunda llamada. Sin pendientes.
- **NB6 — Limitación conocida intra-chunk:** **registrada como limitación conocida (2026-09-16, precisión de QuBeKa):** `parent_id_temp` y los enlaces solo se resuelven entre nodos del mismo chunk — la relación padre-hijo entre chunks **no existe en los datos** (`parent_id_temp` queda `null`), así que la evaluación no puede advertirla y en la promoción real ese hijo nace como raíz sin advertencia previa. Consecuencias para Kuestion: (a) la advertencia cubre exactamente lo que el modelo de datos registra — v1.8 §2.6 llevará la subsección "Qué NO cubre la evaluación"; (b) los tests de C.6 deben construir sesiones con relaciones intra-chunk para ejercitar los tres tipos de advertencia; (c) el E2E (checklist FE) no debe esperar advertencia para un huérfano entre chunks. Si QuBeKa extiende el pipeline para resolver jerarquía/enlaces entre chunks, el contrato se actualiza con nota de versión y la evaluación se extiende sola (el servicio lee las mismas tablas).

**Dependencia de timing:** QuBeKa entrega primero (su estimación: ~2 días, Fase 2 → contrato v1.8). Kuestion no puede paralelizar código sin violar P1; la única paralelización segura es preparar el análisis de copy y los casos de test (sin payload).

---

## 5. ESFUERZO ESTIMADO

| Fase | Estimado |
|---|---|
| A — Coordinación + validación real | 0,25 día |
| B — Cliente + tests | 0,5 día |
| C — Lógica del flujo + tests | 0,5 día |
| D — UI + copy + tests | 0,5 día |
| E — QA + E2E real + cierre | 0,5 día |
| **Total** | **~2,25 días** |

Mayor incertidumbre: **el timing de la Fase A→B** (coordinación externa con QuBeKa, no técnica). El resto replica patrones ya resueltos en este repo (advertencias ámbar del Punto 1, manejo de errores del servicio, tests Livewire).

---

## 6. FUERA DE ALCANCE

1. Todo lo que el spec §2 excluye: recálculo durante la selección, bloqueo de la aprobación, casos nuevos fuera de los tres definidos, cambios al flujo "aprobar todo".
2. El mecanismo de evaluación, su endpoint y su contrato — QuBeKa (spec §3.A). Kuestion no propone payload alternativo.
3. **Lógica de estructura del grafo en Kuestion** — restricción arquitectónica (spec §1.4, `Ecosistema_Cambios_Arquitectonicos_Post_Ola2.md` §1): solo llamamos y renderizamos.
4. Cambios en `PromocionService`, en el endpoint de `approve`, o en la semántica de la promoción confirmada (spec §6.5: comportamiento ya definido, la advertencia solo lo hace visible).
5. Migraciones — no hay dato nuevo que persistir del lado Kuestion.
6. Redacción de las `descripcion` de cada consecuencia — QuBeKa las envía listas; Kuestion solo traduce el `tipo` a encabezado y las muestra.

---

*Estado de la ronda §4.3: confirmada — mecanismo y payload aceptados por Kuestion; P2/P3 **confirmadas por producto (2026-09-16)**; NB5 confirmado por QuBeKa y limitación intra-chunk registrada como conocida (NB6). Único paso pendiente: A.2 (curl al endpoint real cuando QuBeKa despliegue y publique v1.8); recién ahí arranca la Fase B.*
