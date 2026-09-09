# Plan de Implementación — Ola 2, Punto 1: Bandeja de revisión rápida

*Equipo de Kuestion · Septiembre 2026*
*Documento de entrada: `OLA_2_Punto_1.md` (especificación cerrada de producto)*
*Contrato de referencia: `docs/CONTRATO_API_OLA2.md` (fuente única de contrato Ola 2)

---

## 1. RESUMEN DE ALCANCE

### Qué voy a construir

Una **bandeja de revisión** dentro de Kuestion donde la persona ve, en una sola lista, todos los aportes de conocimiento pendientes de su revisión (propios y de su equipo, según rol en QuBeKa) y los aprueba, rechaza o ajusta sin salir de Kuestion. Es una vista nueva que **consume las APIs de QuBeKa ya definidas en la Ola 1 (Punto 4)** — aprobación, rechazo y detalle de sesión — más un **endpoint nuevo de listado de sesiones pendientes que QuBeKa debe exponer** (sección 6 del documento de entrada, "A QuBeKa", ítem 1). No agrega una capacidad nueva al motor de QuBeKa: pule la experiencia de revisión que ya existe.

En concreto, en Kuestion se construye:

1. **Una sección de navegación "Revisar"** con un contador visible de pendientes, junto a las secciones existentes.
2. **La lista de pendientes**: ítems ordenados por antigüedad (más antiguos primero), paginados de a 20, cada uno con el texto original del aporte, la clasificación propuesta en lenguaje natural, el autor (si es distinto del revisor), la fecha y el estado.
3. **Acciones por ítem**: Aprobar y Rechazar (1-2 clics, sin salir de Kuestion). Ajustar solo si la sesión es simple (editor de texto en línea); si es compleja, redirigir a la pantalla de Revisión Humana de QuBeKa (mismo criterio de la Ola 1, Punto 4).
4. **Un historial** de ítems ya procesados (pendiente de la decisión de la sección 4 de este plan y de la decisión abierta N°1 del documento de origen).

### Qué NO cambia

- El mecanismo de clasificación, el sandbox y la promoción de nodos viven en QuBeKa — Kuestion solo los consume por API.
- La política de roles (quién puede revisar qué) se resuelve del lado de QuBeKa al consultar; Kuestion no implementa política propia.
- La vista de revisión individual ya construida en la Ola 1 (`ContributionReview` en `/contributions/{sessionId}/review`) se **reutiliza** como destino de navegación desde un ítem, no se reescribe.

### Hallazgos de la revisión del código que condicionan el alcance

- **Hoy no existe una lista de pendientes consumible por Kuestion.** El badge actual del header (`PendingReviewBadge`) cuenta **borradores locales** (`contribution_drafts` con `status=sent` y `qbk_session_id`), es decir, solo aportes hechos *desde Kuestion por ese mismo usuario*. La especificación pide además aportes de otros miembros del equipo, que nunca llegan a esa tabla local. Por lo tanto, la fuente de verdad de la bandeja debe ser el **endpoint de listado de QuBeKa**, no la tabla local (ver duda D2).
- **El endpoint de listado de QuBeKa no existe todavía** y no tiene contrato confirmado. Es la única dependencia externa nueva; todo el resto reutiliza endpoints ya confirmados en la Ola 1 (`GET /sesiones-analisis/{id}`, `POST .../approve`, `POST .../reject`, y el flag `is_simple`/`es_compleja`).
- **La lógica de aprobar/rechazar/ajustar ya está resuelta** en `ContributionReview` (componente Livewire) y en `QbkContributionService` (métodos `getSession`, `approve`, `reject` con manejo de 401/403/404/5xx/timeout). La bandeja debe **reutilizar esos servicios**, no duplicarlos.
- **Los ítems aprobados/rechazados hoy no dejan estado final en Kuestion**: `ContributionReview` solo marca el borrador local como `reviewed`, sin distinguir `aprobado` vs `rechazado`. El historial de la bandeja necesita definir de dónde sale ese estado (ver duda D3).
- **Estado transitorio conocido de QuBeKa**: `approve` responde `aprobada` (transitorio) y la sesión pasa a `promocionada` cuando corre el job de promoción. La bandeja debe tratar ambos como éxito y no mostrar el ítem como pendiente en ese interregno (misma regla ya aplicada en `ContributionReview`).

### Lo que NO construyo en esta versión

- Filtros complejos (por fuente, tipo de nodo, fecha).
- Revisión en lote (aprobar varios a la vez).
- Delegación de revisión a otra persona desde la bandeja.
- Notificaciones in-app propias de Kuestion por aprobación/rechazo de terceros (QuBeKa ya notifica al autor; Kuestion podría mostrarlas en una ola futura).
- Editor de ajuste para sesiones complejas dentro de Kuestion (redirige a QuBeKa, como en la Ola 1).

---

## 2. FASES Y TAREAS

### Fase A — Contrato de listado de sesiones + servicio en Kuestion

**Objetivo:** dejar a Kuestion lista para consumir el listado de sesiones pendientes de QuBeKa con un contrato definido y testeable, sin depender de que QuBeKa ya lo haya desplegado.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| A.1 | Proponer el contrato mínimo del endpoint de listado a QuBeKa: `GET {QUBKA_API_URL}/sesiones-analisis?estado=pendiente&page=...` (o similar) devolviendo por ítem `session_id`, `fecha_creacion`, `autor_id`, `autor_nombre`, `texto_original_del_aporte`, `resumen_clasificacion`, `es_compleja` (lista en sección 6 del documento de origen). Confirmar con QuBeKa paginación y filtrado por token/rol. | Coordinación con QuBeKa (no bloquea el desarrollo: se trabaja con mock). | Contrato escrito y aprobado por ambos equipos (sección de dudas D1). |
| A.2 | Agregar método `listPendingSessions()` (y el que corresponda para historial) a `QbkContributionService`, siguiendo el patrón exacto de `getSession`/`approve`/`reject` (timeout, 401→repo invalid o error claro, 403/404/5xx, desempaquetado de `{success, data}`). | A.1 (contrato esperado; se implementa contra ese contrato con Http fake) | Método en el servicio + tests unitarios del contrato. |
| A.3 | Definir un DTO/forma tipada de ítem de bandeja (id, autor, texto, resumen, es_compleja, fecha, estado) para que la vista no dependa de las claves crudas de QuBeKa (patrón ya usado en `QbkContributionService`). | A.2 | Normalización lista para la UI. |

**Entregable verificable:** con el endpoint de QuBeKa simulado (Http fake), `listPendingSessions()` devuelve los pendientes del workspace normalizados, paginados, y falla con error legible cuando QuBeKa devuelve 401/403/5xx. La bandeja no se puede probar de punta a punta real hasta que QuBeKa exponga el endpoint (declarado en la sección 3).

**Validación:** tests del servicio (contrato con fake) + checklist FA de la sección 3.

---

### Fase B — Sección "Revisar" en la navegación + contador + lista de pendientes

**Objetivo:** que exista la superficie visible: la entrada en la navegación con su contador y la lista de pendientes consumiendo la Fase A.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| B.1 | Crear componente Livewire `ReviewTray` (nombre a confirmar con UX) con ruta `/reviews` (o `/revisar`), layout de app. Carga la lista de la Fase A, ordena por antigüedad ascendente y pagina de a 20. Refresco con `wire:poll` (la especificación §5 permite polling, no WebSockets). | Fase A | Ruta y componente que renderizan la lista. |
| B.2 | Vista `livewire/review-tray.blade.php`: cada ítem muestra texto original del aporte, clasificación en lenguaje natural (campo `resumen` de QuBeKa — mismo texto que ya muestra la vista individual), autor cuando es distinto del revisor, fecha, y el estado (`pendiente`; y en historial, `aprobado`/`rechazado`). Copy definido con UX (regla: sin etiquetas técnicas QBK visibles). | B.1 | Lista visible con el copy acordado. |
| B.3 | Estados vacíos y de error legibles: "No hay aportes pendientes" y error con mensaje claro si QuBeKa no responde (nunca quedar en "cargando..." infinito — ítem obligatorio de la sección 3). | B.1 | Estados de vacío/error. |
| B.4 | Contador de pendientes en la navegación, integrado con la sección "Revisar". Definir con producto si reemplaza al badge actual del header o conviven (ver duda D4). En esta fase el contador se alimenta del listado de la Fase A (no de la tabla local). | B.1 | Contador visible y consistente con la lista. |

**Entregable verificable:** una persona con aportes pendientes ve el contador en la navegación, entra a "Revisar" y ve su lista completa (propios + de equipo según rol), con los datos pedidos por la especificación y el copy en lenguaje natural.

**Validación:** tests del componente + checklist funcional FB (navegación, contador, lista, vacío, error, assets, navegador real).

---

### Fase C — Acciones por ítem: Aprobar / Rechazar / Ajustar

**Objetivo:** resolver cada pendiente desde la bandeja en 1-2 clics, reutilizando la lógica ya existente.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| C.1 | Botones **Aprobar** y **Rechazar** por ítem, con confirmación visual y estados de procesamiento por ítem (patrón de `ContributionReview`: estado `processing`, deshabilitar botones mientras corre, error legible si falla). Llaman a `QbkContributionService::approve` / `reject` con la credencial del repositorio del autor del aporte (mismo repo QBK activo del usuario o el que corresponda según la sesión). | Fase A, B | Aprobar/rechazar funcionando desde la bandeja. |
| C.2 | Al aprobar/rechazar: el ítem sale de la lista de pendientes (con el estado transitorio `aprobada` tratado como éxito, igual que en `ContributionReview`) y el contador baja. Sincronizar el estado del borrador local (`ContributionDraft::STATUS_REVIEWED`) si el ítem proviene de un aporte hecho en Kuestion, para no romper el badge/indicadores existentes. | C.1 | Consistencia bandeja ↔ contador ↔ borradores locales. |
| C.3 | Botón **Ajustar** condicional: si la sesión es simple (`es_compleja=false`), abre el editor de texto en línea en la misma vista (misma lógica de `textos_ajustados` y `approve` con ajustes que ya tiene `ContributionReview`). Si es compleja, redirige a la pantalla de Revisión Humana de QuBeKa (URL `{QUBKA_URL}/analisis/{sessionId}/revision` — patrón existente; verificar compatibilidad de redirección en la versión instalada de Livewire, ítem obligatorio de la sección 3). | C.1 | Ajustar simple inline + redirección para complejas. |
| C.4 | Al aprobar/rechazar/ajustar, marcar los ítems correspondientes como procesados en la sesión local de la bandeja (sin recargar toda la lista; actualización optimista con re-consulta del listado). | C.1–C.3 | Bandeja consistente tras cada acción. |

**Entregable verificable:** desde la bandeja, una persona aprueba en ~2 clics, rechaza en ~2 clics, ajusta texto de una propuesta simple y la aprueba, y una sesión compleja la redirige a QuBeKa — sin tocar la vista de revisión individual para el flujo normal.

**Validación:** tests del componente (acciones con servicio mockeado) + checklist FC contra QuBeKa real cuando esté disponible (la aprobación real promueve nodos al grafo).

---

### Fase D — Historial de ítems procesados

**Objetivo:** mostrar los últimos ítems aprobados/rechazados con su estado (alcance sujeto a la decisión abierta N°1 del documento de origen — ver duda D5).

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| D.1 | Según la decisión de producto (solo pendientes + enlace "Historial" con los últimos 50 procesados, o historial en la misma bandeja): agregar la pestaña/sección de historial consumiendo el listado de QuBeKa con filtro de estado procesado (o la fuente local si QuBeKa no lo expone — ver duda D3). | Fase A, decisión de producto | Historial visible con estado por ítem. |
| D.2 | Sincronizar estados de procesados entre Kuestion y QuBeKa (un ítem aprobado desde QuBeKa directamente debe aparecer como aprobado aquí si se decide mostrar historial desde QuBeKa). | D.1 | Estados consistentes. |

**Entregable verificable:** una persona ve sus últimos aportes procesados con su estado final, y entiende qué fue aprobado o rechazado.

**Validación:** tests + checklist FD. **Esta fase no se cierra sin la decisión de producto D5.**

---

### Fase E — QA, regresión y cierre

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| E.1 | Regresión de los flujos de la Ola 1 que esta bandeja reutiliza: suites `ContributionReview*`, `PendingReviewBadge*`, `ContributionDraft*`, `ContributeAporte*`, `QbkContributionService*` — verificar que seguir aprobando desde la vista individual (ruta existente) y desde el badge no se rompe. | Fases A–D | Regresión verde. |
| E.2 | Validación de punta a punta contra QuBeKa real (levantando ambos servicios): aportar → ver el ítem en la bandeja → aprobar → verificar que QuBeKa promovió los nodos al grafo activo → ítem en historial. | QuBeKa real con endpoint de listado desplegado | E2E real documentado. |
| E.3 | Rebuild de assets (`npm run build`) + verificación de clases en el bundle compilado + verificación visual en navegador real (ítems obligatorios de la sección 3). | Fases A–D | Assets y visual verificados. |
| E.4 | Suite completa + Pint + documento de cierre (hallazgos, evidencia, pendientes). | Todo | Verde y documentado. |

---

## 3. PRUEBAS FUNCIONALES Y DE INTEGRACIÓN

Ítems obligatorios en toda fase con UI o integración (no negociables):

1. **Rebuild y verificación de assets compilados**: todo cambio en vistas (Fases B, C, D) exige `npm run build` y verificar en el CSS servido (`public/build/`) las clases usadas (p. ej. `bg-amber-50`, `text-teal-700`, `rounded-xl`). Verificación con grep contra el bundle.
2. **Verificación visual en navegador real**: cada botón/enlace/contador se inspecciona con DevTools (visible, legible, contraste, fondo del botón). Un test con mock no cierra una fase con UI.
3. **Compatibilidad con la versión instalada**: antes de usar APIs de Livewire/Laravel, verificar contra `composer.json` y el vendor. Ejemplo conocido del proyecto: Livewire `^4.0` tiene `redirect()` pero **no** `redirectExternal()`. La redirección de sesiones complejas (Fase C) debe usar el mecanismo que ya funciona en `ContributionReview`.
4. **Fallo visible y claro en runtime**: si QuBeKa no responde o devuelve error, la bandeja muestra un mensaje legible con cómo proceder — nunca "cargando..." infinito ni estado silencioso. Los estados de error de la Fase A (401/403/5xx) se muestran tal cual.
5. **Prueba contra el servicio real**: el listado de sesiones y la aprobación/rechazo reales requieren QuBeKa con el endpoint de listado desplegado. Mientras no exista, se prueba contra Http fake del contrato acordado (Fase A) y **se declara explícitamente qué queda pendiente de validación real**.

### Checklist FA — Servicio de listado (integración, mock primero)

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| FA.1 | Listado con 0 pendientes | Http fake | Lista vacía, sin error |
| FA.2 | Listado con pendientes (varios) | Http fake | Ítems normalizados con id/autor/texto/resumen/es_compleja/fecha |
| FA.3 | 401 del listado | Http fake | Error legible ("token inválido/revocado") + repositorio marcado `invalid` (patrón existente) |
| FA.4 | 403 / 404 / 5xx / timeout | Http fake | Error legible con código, sin excepción cruda |
| FA.5 | Paginación | Http fake con >20 ítems | Página 2 accesible |

### Checklist FB — Navegación, contador y lista (UI)

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| FB.1 | Entrada "Revisar" visible en la navegación | Navegador real + DevTools | Sección presente junto a Feed/Tags, contador visible cuando hay pendientes |
| FB.2 | Contador = cantidad real de pendientes del workspace (propios + equipo según rol) | Navegador real | Coincide con la lista |
| FB.3 | Orden por antigüedad (más antiguo primero) | Navegador real | Orden correcto |
| FB.4 | Texto original + clasificación en lenguaje natural + autor (si es de otro) + fecha | Navegador real | Cada ítem muestra lo especificado, sin etiquetas técnicas |
| FB.5 | Sin pendientes | Navegador real | Estado vacío claro |
| FB.6 | QuBeKa caído | Navegador real | Error legible, no "cargando..." infinito |
| FB.7 | Rebuild de assets | `npm run build` + grep | Clases en el bundle |

### Checklist FC — Aprobar / Rechazar / Ajustar (UI + integración real)

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| FC.1 | Aprobar un ítem simple | Navegador real (QuBeKa real) | Ítem sale de pendientes, contador baja, nodos promovidos en QuBeKa (verificar en BD/UI de QuBeKa) |
| FC.2 | Rechazar un ítem | Navegador real | Ítem pasa a historial como `rechazado`, sandbox descartado |
| FC.3 | Ajustar texto y aprobar | Navegador real | El texto corregido llega a QuBeKa (`textos_ajustados`) y se promueve |
| FC.4 | Sesión compleja → botón Ajustar redirige a QuBeKa | Navegador real | Redirección correcta a la Revisión Humana de QuBeKa |
| FC.5 | Aprobar con QuBeKa caído o 5xx | Navegador real / mock | Error legible, el ítem sigue pendiente, sin doble envío al reintentar |
| FC.6 | Estado transitorio `aprobada` → `promocionada` | QuBeKa real | El ítem no reaparece como pendiente en el interregno |

### Checklist FD — Historial (UI)

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| FD.1 | Ítems procesados visibles con estado | Navegador real | `aprobado`/`rechazado` correctos, últimos 50 |
| FD.2 | Aprobado desde QuBeKa directamente | Navegador real | Aparece en historial (según fuente definida en D3/D5) |
| FD.3 | Rebuild + visual | Navegador real | Clases en bundle, legible |

### Matriz mock vs real

| Fase | Con mock (siempre) | Contra servicio real (cuando esté disponible) |
|---|---|---|
| A — Servicio | Http fake del contrato acordado | **Requiere endpoint de listado de QuBeKa** (no existe hoy) |
| B — Lista/contador | Fakes del servicio | Con listado real desplegado |
| C — Acciones | approve/reject mockeados | **Obligatorio**: aprobar → ver nodos promovidos en QuBeKa real |
| D — Historial | Fakes | Con listado real desplegado |
| E — Cierre | Suite completa | Flujo E2E real documentado |

**Regla de cierre:** ninguna fase se declara cerrada sin completar los ítems obligatorios de esta sección. Si alguno no se pudo hacer (p. ej. validación real del listado porque QuBeKa aún no lo expone), se declara explícitamente como pendiente con su motivo.

---

## 4. DUDAS Y BLOQUEOS

### Bloqueantes

| # | Pregunta | Para quién |
|---|---|---|
| B1 | **Endpoint de listado de sesiones de QuBeKa**: ¿cuál es el contrato exacto (URL, método, filtros, paginación, qué devuelve por ítem y en qué formato)? El documento de origen se lo pide a QuBeKa (sección 6, ítem 1) y **no existe hoy**. Kuestion necesita el contrato para cerrar la Fase A y para que la Fase B tenga datos reales. | QuBeKa |
| B2 | **Roles y alcance del listado**: ¿el endpoint filtra por token/rol y devuelve solo lo que la persona puede revisar (incluidos aportes de otros miembros), como pide la especificación §5? Kuestion asume que sí — necesita confirmación explícita para no duplicar lógica de permisos. | QuBeKa |
| B3 | **Comportamiento de rechazo**: ¿borrado definitivo del sandbox o retención por X días? La especificación lo deja "según la política de QuBeKa". Determina si el historial puede mostrar ítems `rechazado` o si desaparecen. | QuBeKa (decisión abierta N°3 del documento de origen) |

### No bloqueantes

| # | Pregunta | Supuesto / estado | Para quién |
|---|---|---|---|
| D1 | Contrato de listado (mientras QuBeKa responde B1) | Se implementa la Fase A contra el contrato mínimo de la sección 6 del documento de origen, con Http fake. Si QuBeKa difiere, se ajusta el mapeo en A.3 (costo bajo). | Interno |
| D2 | Fuente de verdad de la bandeja | **QuBeKa** (listado por workspace), no la tabla local `contribution_drafts` (que solo contiene aportes hechos desde Kuestion por ese usuario y no ve aportes de equipo). El badge actual del header seguirá usando la tabla local hasta que se decida su destino (D4). | Interno |
| D3 | Fuente del historial de procesados | Si QuBeKa expone listado con estado, se usa esa fuente. Si no, alternativa local limitada (borradores `reviewed` sin distinguir aprobado/rechazado — no cumple la especificación). Pendiente de B1/B3. | Interno |
| D4 | **Destino del badge actual "Pendientes"** del header (Ola 1, Punto 4): hoy cuenta borradores locales y lleva a la última sesión. Con la bandeja nueva (contador + lista por workspace), ¿el badge se reemplaza por la entrada "Revisar", conviven, o migra a la misma fuente? Decisión de producto menor — no tocar hasta confirmar. | Propuesta: el badge migra a la fuente de la bandeja (listado de QuBeKa) y navega a "Revisar" en vez de a la última sesión. **Confirmar con producto.** | Producto / Interno |
| D5 | Historial dentro de la bandeja vs. solo pendientes + enlace a historial (decisión abierta N°1 del documento de origen, que propone "solo pendientes + historial de los últimos 50") | El plan asume la propuesta del documento (pendientes + enlace a historial). Confirmar antes de cerrar la Fase D. | Producto |
| D6 | Contador: ¿incluye solo aportes propios o también los del equipo que la persona puede revisar? (decisión abierta N°2, propone ambos) | El plan asume la propuesta del documento (ambos, según rol). Confirmar. | Producto |
| D7 | Nombre y ubicación de la sección ("Revisar" o similar) y copy de los ítems | Se define con UX; el plan usa "Revisar" como placeholder (la especificación §2.1 lo deja abierto). | UX |

---

## 5. ESFUERZO ESTIMADO

| Fase | Esfuerzo estimado | Incertidumbre |
|---|---|---|
| **Fase A** — Contrato + servicio de listado | S–M (0.5–1.5 d) | **Media-alta por dependencia externa**: el contrato real del listado de QuBeKa no existe; el trabajo con mock es barato, pero el cierre depende de QuBeKa (B1). |
| **Fase B** — Sección, contador y lista | M (1.5–2 d) | Media: UI nueva + estados vacío/error + contador consistente con una fuente que hoy no existe. |
| **Fase C** — Acciones Aprobar/Rechazar/Ajustar | M (1–1.5 d) | Baja-media: lógica ya resuelta en `ContributionReview`/`QbkContributionService`; el riesgo es la consistencia de estados (transitorio `aprobada`→`promocionada`) y la redirección de complejas. |
| **Fase D** — Historial | S (0.5 d) | Media por decisión de producto (D5) y fuente del estado (D3). |
| **Fase E** — QA y cierre | S (0.5–1 d) | Baja. |
| **TOTAL** | **S–M (4–6.5 d)** | La mayor incertidumbre está en la Fase A (contrato externo). El resto reutiliza patrones existentes de la Ola 1. |

---

## 6. FUERA DE ALCANCE

| Elemento | Por qué queda fuera |
|---|---|
| Endpoint de listado de sesiones en QuBeKa | Es trabajo de QuBeKa (sección 6 del documento de origen). Kuestion lo consume, no lo implementa. |
| Política de roles y de retención de sesiones | Vive en QuBeKa. Kuestion respeta lo que QuBeKa devuelve. |
| Revisión en lote | Explícitamente excluida en §2.5 del documento de origen. |
| Filtros complejos | Explícitamente excluidos en §2.5. |
| Delegación de revisión | Explícitamente excluida en §2.5. |
| Notificaciones in-app de Kuestion por aprobación/rechazo de terceros | QuBeKa ya notifica al autor. Kuestion lo evaluará en una ola futura. |
| Editor de ajuste para sesiones complejas dentro de Kuestion | Redirige a la Revisión Humana de QuBeKa (criterio de la Ola 1, Punto 4). |
| Cambios en el mecanismo de clasificación o promoción | Es motor de QuBeKa; Kuestion no lo toca. |
| WebSockets / tiempo real | La especificación §5 permite `wire:poll`; no se agrega infraestructura de tiempo real. |

---

## 7. Entregables comprometidos contra el contrato

Los siguientes entregables de esta fase comprometen el contrato `docs/CONTRATO_API_OLA2.md`:

| # | Entregable | Sección del contrato | Compromiso |
|---|---|---|---|
| 1 | `GET /api/v1/sesiones-analisis` (listado con `estado=pendientes|historial`, paginación `page`/`per_page`) | §4.1 | Consumo del listado por workspace; filtros y paginación según contrato. |
| 2 | Campos por ítem: `session_id`, `status`, `creado_en`, `contenido_entrada`, `resumen` (persistido), `is_simple`, `pregunta_previa`, `autor_email`/`autor_nombre` (sujeto B2), `fecha_decision` = `cerrado_en` | §4.1 | Mapeo según nombres reales del contrato (`creado_en`/`contenido_entrada`, no `fecha_creacion`/`texto_original_del_aporte`). |
| 3 | `POST .../approve` y `POST .../reject` reutilizados con scope `api:write` | §4.3, §4.4 | Estado `aprobada` tratado como éxito; polling C4 considera `promocionada`/`rechazada` como finales (no `aprobada`). |
| 4 | Persistencia del resumen (`resumen` en `sesiones_analisis`) | §4.5 | Fuente honesta: el resumen persiste al finalizar procesamiento. |
| 5 | Salvaguarda de `sandbox:limpiar` (sin eliminar sesiones activas) | §4.6 | Asumido implementado/testeado por QuBeKa en Ola 2. |
| 6 | Nudo B1 (MVP) + header `X-User-Email` reservado | §9 | Listado sin filtro por revisor humano; header opcional e ignorado. |
| 7 | Nudo B2: `autor_email`/`autor_nombre` opcionales en `POST /contribute`, atribución declarada | §10 | Destinatario de correo (Punto 5) según atribución declarada. |

---

## 8. Trazabilidad de referencias cruzadas (Ola 1)

- Este plan no reabre el contrato de la Ola 1. Referencia conceptual `ola1-preguntas-abiertas.md` solo como registro histórico de la Ola 1.
