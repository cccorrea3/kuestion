# Plan de Implementación — Ola 2, Punto 2: Reconfirmación periódica ligera

*Equipo de Kuestion · Septiembre 2026*
*Documento de entrada: `OLA_2_Punto_2.md` (especificación cerrada de producto)*
*Contrato de referencia: `docs/CONTRATO_API_OLA2.md` (fuente única de contrato Ola 2)

---

## 1. RESUMEN DE ALCANCE

---

## 1. RESUMEN DE ALCANCE

### Qué voy a construir

Un mecanismo en Kuestion que permite al usuario **reconfirmar con un clic** que una respuesta vigilada sigue siendo válida, cuando su contenido proviene de QuBeKa y supera un umbral de tiempo sin reconfirmación (90 días, decisión cerrada del ecosistema). La reconfirmación no modifica contenido: actualiza `fecha_ultima_confirmacion` del nodo en QuBeKa y registra quién lo hizo. Kuestion es el consumidor: **lee** el dato de vigencia del contrato de QBK, **muestra** el indicador y el botón cuando corresponde, y **llama** al endpoint de reconfirmación de QuBeKa.

En concreto, en Kuestion se construye:

1. **Lectura de `fecha_ultima_confirmacion`** por nodo fuente, a través del contrato que QBK ya expone en `/query` (`sources`), ampliado por QuBeKa para incluir el campo (requisito de la sección 3 del documento de origen).
2. **Indicador de vigencia** con copy definido ("Última confirmación: 25 de julio", "Sin reconfirmar desde hace 95 días — Reconfirmar") en tres superficies: el detalle de la pregunta (respuesta), la card del feed y la pestaña "Pendientes de reconfirmar" de la bandeja de revisión (Ola 2, Punto 1 — pendiente de construir).
3. **Acción de reconfirmar** desde esas superficies: llama a `PATCH /nodos/{id}/reconfirmar` de QuBeKa con el token del repositorio y los IDs de nodos, actualiza la UI de forma optimista y refleja el cambio.
4. **Regla de degradación**: si el campo aún no viene en el contrato (o viene `null`), Kuestion conserva el copy honesto que ya existe de la Ola 1, Puntos 5/6 ("sin reconfirmaciones registradas") — no rompe nada mientras QuBeKa despliega el campo.

### Qué NO construyo

- **El campo `fecha_ultima_confirmacion` ni el endpoint en QuBeKa** — es trabajo de QuBeKa (sección 6 "A QuBeKa"). Kuestion lo consume.
- **Reconfirmación para contenido de Kuaforia** — el documento de origen (§2.3) lo excluye en esta versión: la vigencia de Kuaforia se deriva de sus señales internas (`stale_case`, `low_confidence`), no de una acción del usuario.
- **Umbral configurable por usuario** — decisión abierta; en la Ola 2 queda fijo en 90 días (configurable por entorno, no por usuario).
- **Recordatorios/notificaciones** por falta de reconfirmación — explícitamente fuera de esta versión (§5 del origen).
- **Reconfirmación en lote** — se deja para una ola posterior (§4.3 del origen la menciona como "idealmente", no como requisito).

### Hallazgos de la revisión del código que condicionan el alcance

- **El copy honesto de la Ola 1 (P5/6) es el punto de partida correcto, pero quedará obsoleto cuando exista el campo real.** Hoy, `question-card` y `question-detail` muestran "sin reconfirmaciones registradas" para `connector_type === 'qbk'` (tests `QuestionVigenciaCopyTest` lo fijan). Este punto **supera** ese copy cuando el contrato traiga `fecha_ultima_confirmacion`: la lógica debe ramificar (campo presente → indicador real; ausente/null → fallback actual). Los tests de P5/6 deberán convivir con el nuevo comportamiento.
- **`QbkService::consult()` ya persiste `sources` completos en cada versión** (`answer_versions.sources`, JSON por nodo con `node_id`, `tipo`, `estado_validacion`, `texto_preview`, `camino`). Si QuBeKa agrega `fecha_ultima_confirmacion` a cada fuente del contrato de `/query`, Kuestion **no necesita una migración ni una tabla espejo**: puede calcular el estado de vigencia de la pregunta a partir de las fuentes de la versión actual. (Decisión de diseño D2 — ver sección 4.)
- **Las fuentes de QBK citan nodos reales con `node_id`** — la reconfirmación "de la pregunta" se traduce a reconfirmar los `node_id` de las fuentes de la versión actual. Esto deja abierta la decisión de producto "nodo vs. pregunta completa" (decisión abierta N°1 del origen) con impacto directo en qué IDs se envían.
- **Config por feature ya existe** (`config/kuestion.php` → `features.relations_graph`, con env). El umbral de 90 días se agrega con el mismo patrón (`kuestion.reconfirmacion.umbral_dias` + env), manteniendo el default cerrado.
- **La pestaña en la bandeja de revisión depende de la Ola 2, Punto 1**, que aún no está construida (solo planificada). Esta fase queda condicionada a ese punto o se difiere.

---

## 2. FASES Y TAREAS

### Fase A — Contrato de reconfirmación + lectura del campo en Kuestion

**Objetivo:** dejar a Kuestion lista para leer `fecha_ultima_confirmacion` y llamar al endpoint de reconfirmación, trabajando contra el contrato esperado mientras QuBeKa despliega el campo.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| A.1 | Confirmar con QuBeKa el contrato de reconfirmación (bloqueante B1): `PATCH /nodos/{id}/reconfirmar` — body (¿lista de nodos o uno solo?), formato de respuesta, errores (403 sin permiso, 404 nodo inexistente/eliminado), y si el campo `fecha_ultima_confirmacion` llega en cada `source` del contrato de `/query`. | Coordinación con QuBeKa (no bloquea el desarrollo: se trabaja con mock). | Contrato escrito y acordado. |
| A.2 | Agregar a `QbkService` (o al servicio que se defina, siguiendo su patrón) un método `reconfirmarNodos(array $nodeIds): array` que llame al endpoint con el token del repositorio y maneje 401/403/404/5xx/timeout con errores legibles (patrón `KuaforiaException` ya usado en `QbkService`/`QbkContributionService`). | A.1 (contrato esperado; se implementa con Http fake) | Método en el servicio + tests del contrato. |
| A.3 | Asegurar el parseo de `fecha_ultima_confirmacion` (y opcional `ultimo_confirmador_nombre`) dentro de cada fuente de la respuesta de `/query`, sin romper el contrato actual (campo ausente → `null` → degradación). | A.1, contrato de `/query` ampliado | `KuaforiaResponse` (o el mapeo en `QbkService`) expone el dato por fuente sin regresión. |

**Entregable verificable:** con el endpoint simulado (Http fake), `reconfirmarNodos()` reconfirma exitosamente, rechaza con error claro 403/404 y no rompe la consulta normal de `/query` cuando el campo no viene. **El cierre de la fase depende de la confirmación del contrato (B1).**

**Validación:** tests del servicio + checklist FA.

---

### Fase B — Umbral, estado de vigencia y degradación

**Objetivo:** definir y calcular cuándo una pregunta QBK "necesita reconfirmación", con umbral 90 días, y mantener el copy honesto como fallback.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| B.1 | Agregar `config/kuestion.php` → `reconfirmacion.umbral_dias` (default 90, env `KUESTION_RECONFIRM_UMBRAL_DIAS`) con el patrón de `features`. | Ninguna | Config disponible. |
| B.2 | Servicio/lógica de vigencia (reutilizando lo que ya muestra el feed): dado un `Question` con repo `qbk`, calcular `ultima_confirmacion` = fecha más reciente entre las `fecha_ultima_confirmacion` de las fuentes de la versión actual (o el criterio que cierre la decisión D1); devolver estado: `confirmada` (dentro del umbral), `vencida` (supera el umbral), `sin_dato` (campo ausente/null → fallback copy honesto). | Fase A | Estado de vigencia computable por pregunta. |
| B.3 | Ajustar el copy de la Ola 1 (P5/6) para que ramifique: `sin_dato` → texto honesto actual; `confirmada` → "Última confirmación: {fecha}"; `vencida` → "Sin reconfirmar desde hace X días — Reconfirmar". Mantener los tests existentes de P5/6 para el caso `sin_dato`. | B.2 | Copy correcto en los tres estados. |

**Entregable verificable:** una pregunta QBK con fuentes dentro del umbral muestra la fecha de confirmación; una vencida muestra el botón; una sin el campo en el contrato sigue mostrando el copy honesto (regresión P5/6 intacta).

**Validación:** tests de la lógica de vigencia + checklist FB (visual).

---

### Fase C — Acción "Reconfirmar" en detalle y en el feed

**Objetivo:** que el usuario reconfirme desde donde está viendo el contenido, sin salir de Kuestion.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| C.1 | Botón/enlace **Reconfirmar** en `question-detail` (junto al indicador de vigencia de la respuesta), visible solo para repo `qbk` con estado `vencida` (o `sin_dato` según decisión de copy). Al hacer clic: `reconfirmarNodos()` con los `node_id` de las fuentes (decisión D1), estado de procesamiento, actualización optimista a "¡Confirmado! Última confirmación: ahora". | Fase A, B | Reconfirmación desde el detalle. |
| C.2 | Mismo botón en la card del feed (`question-card`), accionable sin abrir la pregunta (requisito del origen: "ligereza"). Manejar el refresh del contador/indicador tras confirmar. | C.1 | Reconfirmación desde el feed. |
| C.3 | Errores visibles y claros en runtime (ítem obligatorio): 403 → "No tenés permiso para reconfirmar este conocimiento"; 404 → "Este conocimiento ya no está disponible para reconfirmar"; 5xx/timeout → reintento sin doble envío. Nunca quedar en "cargando...". | C.1 | Fallo legible en cada caso. |

**Entregable verificable:** desde el detalle y desde el feed, el usuario reconfirma con un clic; el indicador cambia a "ahora"; los casos de error muestran mensajes claros.

**Validación:** tests de componente + checklist FC contra QuBeKa real cuando el endpoint exista (la reconfirmación real actualiza el campo en QuBeKa y se refleja en la próxima consulta).

---

### Fase D — Pestaña "Pendientes de reconfirmar" en la bandeja de revisión

**Objetivo:** listar en la bandeja (Ola 2, Punto 1) las preguntas vigiladas que superan el umbral sin reconfirmar, con reconfirmación directa. **Condicionada a que el Punto 1 esté construido** — si no, se difiere con el motivo declarado.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| D.1 | Pestaña/sección en la bandeja "Pendientes de reconfirmar": lista de preguntas QBK activas con estado `vencida`, cada una con su botón Reconfirmar (lógica de C.1). | Fase C + Ola 2, Punto 1 (bandeja) | Pestaña funcional. |
| D.2 | Tras reconfirmar desde la pestaña, el ítem sale de la lista. Sin reconfirmación en lote (fuera de alcance). | D.1 | Lista consistente. |

**Entregable verificable:** la bandeja muestra los vencidos y permite reconfirmarlos uno por uno.

**Validación:** tests + checklist FD. **No se cierra sin el Punto 1 de la Ola 2 construido.**

---

### Fase E — QA, regresión y cierre

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| E.1 | Regresión de los flujos que esta función toca: suites `QuestionVigenciaCopyTest` (copy honesto convive), `QbkService*`, `QuestionChecker*`, `QuestionFeed*`, `QuestionDetail*` — verificar que nada se rompe con la ramificación del copy y la lectura del nuevo campo. | Fases A–C | Regresión verde. |
| E.2 | Validación de punta a punta contra QuBeKa real (cuando exista el campo y el endpoint): pregunta con respuesta >90 días sin confirmar → botón visible → reconfirmar → verificar en QuBeKa que `fecha_ultima_confirmacion` se actualizó → indicador "ahora". | QuBeKa real desplegado | E2E real documentado. |
| E.3 | Rebuild de assets (`npm run build`) + clases en bundle + verificación visual en navegador (ítems obligatorios de la sección 3). | Fases B–C | Assets y visual verificados. |
| E.4 | Suite completa + Pint + documento de cierre (hallazgos, evidencia, pendientes). | Todo | Verde y documentado. |

---

## 3. PRUEBAS FUNCIONALES Y DE INTEGRACIÓN

Ítems obligatorios en toda fase con UI o integración (no negociables):

1. **Rebuild y verificación de assets compilados**: cada cambio de vista (Fases B, C, D) exige `npm run build` y verificar en el bundle (`public/build/`) las clases usadas (p. ej. `text-teal-700`, `bg-amber-50`, `animate-pulse`). Verificación con grep contra el CSS servido.
2. **Verificación visual en navegador real**: indicador y botón visibles, texto legible, contraste correcto, botón con su fondo; la acción ocurre y es visible (nunca solo tests con mock).
3. **Compatibilidad con la versión instalada**: antes de usar APIs de Livewire/Laravel, verificar contra `composer.json` y el vendor. Ejemplo conocido: Livewire `^4.0` tiene `redirect()` pero no `redirectExternal()`. Toda navegación usa el mecanismo que ya funciona en `ContributionReview`/`QuestionDetail`.
4. **Fallo visible y claro en runtime**: 403/404/5xx/timeout de la reconfirmación muestran mensaje legible (C.3); nunca "cargando..." infinito ni estado silencioso.
5. **Prueba contra el servicio real**: la reconfirmación real requiere el campo + endpoint de QuBeKa. Hasta entonces se prueba con Http fake del contrato acordado y **se declara qué queda pendiente de validación real**.

### Checklist FA — Servicio de reconfirmación (integración, mock primero)

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| FA.1 | Reconfirmar nodos existentes | Http fake | 200/éxito, respuesta parseada |
| FA.2 | 403 sin permiso | Http fake | Error "No tenés permiso para reconfirmar..." |
| FA.3 | 404 nodo eliminado | Http fake | Error "Este conocimiento ya no está disponible..." |
| FA.4 | 401 token revocado | Http fake | Error de token + repositorio `invalid` (patrón existente) |
| FA.5 | 5xx / timeout | Http fake | Error legible, sin excepción cruda |
| FA.6 | `/query` sin `fecha_ultima_confirmacion` | Http fake (contrato viejo) | No rompe: campo `null`, degradación |

### Checklist FB — Estado de vigencia y copy (UI)

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| FB.1 | Fuentes dentro del umbral | Navegador real | "Última confirmación: {fecha}" sin botón |
| FB.2 | Fuentes vencidas (>90 días) | Navegador real | "Sin reconfirmar desde hace X días — [Reconfirmar]" |
| FB.3 | Sin campo en el contrato | Navegador real | Copy honesto de P5/6 intacto ("sin reconfirmaciones registradas") |
| FB.4 | Rebuild de assets | `npm run build` + grep | Clases del indicador/botón en el bundle |

### Checklist FC — Acción Reconfirmar (UI + integración real)

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| FC.1 | Reconfirmar desde el detalle | Navegador real (QuBeKa real) | Indicador → "¡Confirmado! Última confirmación: ahora" |
| FC.2 | Reconfirmar desde el feed sin abrir la pregunta | Navegador real | El clic funciona en la card; indicador actualizado |
| FC.3 | Estado real en QuBeKa | Verificar en QuBeKa (BD o UI) tras FC.1 | `fecha_ultima_confirmacion` y `ultimo_confirmador_id` actualizados |
| FC.4 | Permisos: revisor sin rol | QuBeKa real con token sin permiso | Mensaje 403 claro, sin doble envío |
| FC.5 | Nodo eliminado entre la consulta y la reconfirmación | QuBeKa real | Mensaje 404 claro ("ya no está disponible") |
| FC.6 | Doble clic / reintento | Navegador real | Sin duplicar la reconfirmación (estado processing + deshabilitado) |

### Checklist FD — Pestaña en la bandeja (solo si el Punto 1 existe)

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| FD.1 | Vencidos listados en la pestaña | Navegador real | Coinciden con el estado `vencida` |
| FD.2 | Reconfirmar desde la pestaña | Navegador real | Ítem sale de la lista |
| FD.3 | Rebuild + visual | Navegador real | Clases en bundle, legible |

### Matriz mock vs real

| Fase | Con mock (siempre) | Contra servicio real (cuando esté disponible) |
|---|---|---|
| A — Servicio | Http fake del contrato | **Requiere campo + endpoint de QuBeKa** (no existen hoy) |
| B — Vigencia | Fuentes fake con/sin campo | Con `/query` real ampliado |
| C — Acción | approve/reject… reconfirm mockeado | **Obligatorio**: reconfirmar → ver `fecha_ultima_confirmacion` actualizada en QuBeKa |
| D — Bandeja | Fakes | Requiere Punto 1 + QuBeKa real |
| E — Cierre | Suite completa | Flujo E2E real documentado |

**Regla de cierre:** ninguna fase se declara cerrada sin completar los ítems obligatorios. Si alguno no se pudo hacer (p. ej. validación real porque QuBeKa aún no despliega el campo), se declara explícitamente como pendiente con su motivo.

---

## 4. DUDAS Y BLOQUEOS

### Bloqueantes

| # | Pregunta | Para quién |
|---|---|---|
| B1 | **Contrato de reconfirmación de QuBeKa**: path exacto (`PATCH /nodos/{id}/reconfirmar` propuesto), si acepta un solo nodo o una lista, formato de respuesta y de errores (403/404), y si el contrato de `/query` amplía cada `source` con `fecha_ultima_confirmacion` (y `ultimo_confirmador_nombre`). Sin el contrato confirmado no se puede cerrar la Fase A ni validar la real. | QuBeKa |
| B2 | **Campo `fecha_ultima_confirmacion` y endpoint**: fecha estimada de disponibilidad en QuBeKa. Determina cuándo se puede ejecutar la validación real (checklists FC/FA) y la prioridad de la Fase E. | QuBeKa |
| B3 | **Política de retención del historial de reconfirmaciones y registro de auditoría** (sección 6 "A QuBeKa", ítems 5–6): ¿qué expone QuBeKa para que Kuestion muestre trazabilidad ("Reconfirmado por Juan el 20 de agosto") si se decide mostrarla? | QuBeKa |

### No bloqueantes

| # | Pregunta | Supuesto / estado | Para quién |
|---|---|---|---|
| D1 | **¿Reconfirmación por nodo individual o por pregunta completa?** (decisión abierta N°1 del origen, con propuesta "por nodo, mostrada como parte de la pregunta") | Se asume la propuesta del origen: reconfirmar los `node_id` de las fuentes de la versión actual al reconfirmar la pregunta. Confirmar antes de cerrar la Fase C (afecta qué IDs se envían). | Producto |
| D2 | **Fuente del estado de vigencia**: calcular desde `sources` de la versión actual (sin tabla espejo) vs. persistir una columna local | Se asume cálculo desde `sources` (sin migración): el dato llega fresco en cada consulta (`/query`) y el feed/detalle ya leen la versión actual. Limitación: una reconfirmación hecha directamente en QuBeKa no se refleja en Kuestion hasta la próxima consulta — aceptable (modelo de polling del origen, §5). Confirmar. | Interno |
| D3 | **¿Mostrar botón en estado `sin_dato`** (campo aún no disponible) o solo en `vencida`? | Se asume mostrarlo solo en `vencida` (con dato real) para no duplicar el copy honesto; `sin_dato` conserva el fallback de P5/6. Confirmar con UX. | Producto / UX |
| D4 | Umbral configurable por usuario | Fuera de alcance en esta ola (decisión abierta del origen). Se agrega solo config por entorno (default 90). | Producto |
| D5 | Copy exacto ("Reconfirmar", "Reconfirmado hace X días", "Sin reconfirmar desde...") y tono no punitivo | Se define con UX en la Fase B; el plan usa los textos del origen como punto de partida. | UX |
| D6 | La pestaña de la bandeja (Fase D) depende de la Ola 2, Punto 1 | Si el Punto 1 no está construido al llegar a la Fase D, la fase se difiere con el motivo declarado (no se simula). | Interno |

---

## 5. ESFUERZO ESTIMADO

| Fase | Esfuerzo estimado | Incertidumbre |
|---|---|---|
| **Fase A** — Contrato + servicio + parseo | S–M (0.5–1.5 d) | **Alta por dependencia externa**: contrato de QuBeKa inexistente aún (B1/B2). El trabajo con mock es barato; el cierre depende de QuBeKa. |
| **Fase B** — Umbral, estado y degradación | S–M (0.5–1.5 d) | Media: lógica nueva de vigencia + ramificación del copy que toca tests de P5/6 (convivencia). |
| **Fase C** — Botón en detalle y feed | M (1–2 d) | **Media-alta**: dos superficies UI con acción remota, actualización optimista y 4 casos de error visibles. Es la fase de mayor trabajo de UI. |
| **Fase D** — Pestaña en bandeja | S (0.5 d) | Condicionada a la Ola 2, Punto 1 (D6); si no existe, se difiere. |
| **Fase E** — QA y cierre | S (0.5–1 d) | Baja. |
| **TOTAL** | **S–M (3–6.5 d)** | Incertidumbre concentrada en A (contrato externo) y C (UI + errores). El punto es construcción nueva comparable a un punto de la Ola 1, como advierte el origen (§8). |

---

## 6. FUERA DE ALCANCE

| Elemento | Por qué queda fuera |
|---|---|
| Campo `fecha_ultima_confirmacion` y endpoint de reconfirmación en QuBeKa | Trabajo de QuBeKa (sección 6 "A QuBeKa"). Kuestion lo consume. |
| Reconfirmación para contenido de Kuaforia | El origen (§2.3) la excluye: la vigencia de Kuaforia se deriva de sus señales internas. |
| Historial de reconfirmaciones con trazabilidad visible en Kuestion | Requiere lo que QuBeKa exponga (B3); no se pide UI de trazabilidad en esta versión más allá del indicador. |
| Umbral configurable por usuario | Decisión abierta; queda fijo en 90 días (config por entorno). |
| Recordatorios/notificaciones por falta de reconfirmación | Explícitamente fuera de esta versión (§5 del origen). |
| Reconfirmación en lote | Se deja para una ola posterior. |
| Job automático que fuerce reconfirmación | El origen (§5) la define como acción voluntaria. |
| Cambios al `ChangeDetector`/vigilancia por hash | El mecanismo de detección no cambia; la reconfirmación es metadata aparte. |

---

## 7. Entregables comprometidos contra el contrato

Los siguientes entregables de esta fase comprometen el contrato `docs/CONTRATO_API_OLA2.md`:

| # | Entregable | Sección del contrato | Compromiso |
|---|---|---|---|
| 1 | `PATCH /api/v1/nodos/{id}/reconfirmar` (por nodo, scope `api:write`) | §5.1 | Reconconfirmación por nodo individual; array de node_ids diferido (no rompe). |
| 2 | Campos en `sources[]`: `fecha_ultima_confirmacion` + `ultimo_confirmador_nombre` (siempre presentes, `null` si nunca) | §5.2 | Lectura de campo en cada fuente sin romper hash de vigilancia. |
| 3 | Literal `"Kuestion (conector)"` como valor de `ultimo_confirmador_nombre` en modo MVP | §5.3, §13-2 | String congelado en contrato. |
| 4 | Estado aggregado de vigencia lo resuelve Kuestion lado cliente | §6 | El servicio de vigencia (Punto 3) consume estos datos; no hay endpoint agregado nuevo. |
| 5 | `ultimo_confirmador_id` solo seteado con identidad humana (B1); `null` en modo MVP | Referencia B1 (§9) + §5.1 | Sin inventar resolución de usuario humano. |

---

## 8. Trazabilidad de referencias cruzadas (Ola 1)

- Este plan no reabre el contrato de la Ola 1. Referencia conceptual `ola1-preguntas-abiertas.md` solo como registro histórico de la Ola 1.
