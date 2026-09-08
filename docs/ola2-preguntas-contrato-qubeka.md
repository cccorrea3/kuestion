# Preguntas para Congelar el Contrato — Ola 2

*Equipo de Kuestion → Equipo de QuBeKa*
*Septiembre 2026 · Versión 1.0*

Complementa el documento **"Lista de Necesidades y Nudos Críticos — Ola 2"** (Producto). Este archivo convierte esas necesidades en preguntas respondibles, para iterar hacia el `CONTRATO_API_OLA2.md` con el mismo proceso que funcionó en la Ola 1.

---

## 0. Alcance de esta etapa (leer antes de responder)

- **Objetivo: hacer funcionar los ciclos de punta a punta (ciclo feliz).** No estamos optimizando experiencia ni cubriendo casos no felices; las preguntas de errores, permisos finos y edge cases quedan fuera deliberadamente de esta etapa.
- **Solo QuBeKa.** Kuaforia se aborda más adelante; el indicador de vigencia V1 cubre solo contenido QBK.
- **Correos: como propone el spec** (`OLA_2_Punto_5.md`). No preguntamos por proveedor transaccional ni reglas finas de deduplicación.
- **Todo cambio propuesto es non-breaking** (campos opcionales, headers reservados) según las reglas de evolución de la sección 3 del documento de necesidades.
- **La necesidad 1.6 (token compartido en approve/reject) se considera resuelta** — ya validada en la Ola 1, Punto 4.
- El contrato vigente de la Ola 1 (`RESPUESTAS_OLA1_KUESTION.md` y respuestas por punto) **no se reabre**, salvo lo marcado explícitamente acá.

**Cómo responder:** como en la Ola 1 — por pregunta, con evidencia de código cuando aplique, indicando esfuerzo estimado. Si algo no puede entregarse en esta Ola, basta un "no en esta Ola, va para la Ola 3"; en ese caso Kuestion aplica el supuesto declarado en cada pregunta.

---

## 1. Los 4 ciclos que deben funcionar

| # | Ciclo | Puntos | Lo que habilita el ciclo |
|---|---|---|---|
| **C1** | Bandeja: listar pendientes → revisar en Kuestion → aprobar/rechazar | Punto 1 | Endpoint de listado (nuevo) + resumen persistido + salvaguarda de sandbox |
| **C2** | Vigencia: ver fecha de confirmación en la respuesta → Reconfirmar → la próxima consulta refleja el cambio | Puntos 2 y 3 | Campos en `sources` + endpoint de reconfirmación |
| **C3** | Explicabilidad: aportar → expandir "¿Por qué?" → ver metadatos de la clasificación | Punto 4 | Metadatos por nodo en el detalle de sesión (mínimo) |
| **C4** | Correos: polling de historial → detectar aprobado/rechazado → correo al autor | Punto 5 | Historial con estado final + autor identificable |

---

## 2. Preguntas por punto

### Punto 1 — Bandeja de Revisión

**Q1.1 — Contrato del endpoint de listado** `[BLOQUEANTE]` *(necesidades 1.1–1.3)*

Para `GET /api/v1/sesiones-analisis`:

1. Confirmar path, método y autenticación (mismo token de agente).
2. Filtros: ¿`estado=pendientes` (default) y `estado=historial`? ¿`autor_id` opcional?
3. Paginación mínima: ¿`page`/`per_page` + `total`? No pedimos cursor ni filtros avanzados.
4. **Nombre del campo de complejidad:** en Ola 1 P4 B1 quedó confirmado `is_simple` en el detalle; el documento de necesidades usa `es_compleja`. Pedimos **un solo nombre** en ambos endpoints (proponemos `is_simple`, que ya está confirmado).

*Supuesto si no hay respuesta:* `estado=pendientes|historial`, `page`/`per_page` + `total`, campo `is_simple`.

**Q1.2 — Campos por ítem del listado** `[BLOQUEANTE]` *(necesidad 1.2)*

Confirmar que cada ítem incluye: `session_id`, `status`, `fecha_creacion`, `texto_original_del_aporte`, `resumen_clasificacion` (ver Q1.3), `is_simple`, `pregunta_previa` (null si no aplica) y, sujeto al Nudo B2, `autor_email` + `autor_nombre`.

*Supuesto:* todos los campos presentes; los sujetos a B2 llegan solo si B2 se aprueba.

**Q1.3 — Persistencia del resumen** `[BLOQUEANTE]` *(necesidad 1.4)*

Confirmar que `resumen` se **persiste** en `sesiones_analisis` al momento de procesar (no calculado al vuelo): al aprobar/rechazar se eliminan los nodos del sandbox y un resumen calculado se perdería. ¿Esfuerzo?

*Supuesto:* migración simple; el campo se persiste en el paso de procesamiento.

**Q1.4 — Salvaguarda de `sandbox:limpiar`** `[BLOQUEANTE]` *(necesidad 1.5)*

Confirmar que `php artisan sandbox:limpiar --dias=N` **no** elimina sandboxes de sesiones con estado `creada`, `procesando` o `lista_para_revision`.

*Supuesto:* la salvaguarda queda implementada y testeada del lado de QuBeKa (es lógica interna suya; solo pedimos la confirmación).

**Q1.5 — Política de rechazo** `[NO BLOQUEANTE]` *(necesidad 1.7)*

Formalizar que rechazar = registro conservado (estado `rechazada`) + sandbox eliminado, y que esos ítems aparecen en el historial. Según Ola 1 P4 B2, así funciona hoy.

*Supuesto:* comportamiento confirmado tal cual; sin cambios.

**Q1.6 — Historial con fecha de decisión** `[NO BLOQUEANTE]` *(necesidad 5.1)*

Para que el polling de Kuestion (Punto 5) sepa cuándo se aprobó/rechazó, pedimos `fecha_decision` en los ítems del historial.

*Supuesto:* si no existe, Kuestion usa la fecha de última actualización del ítem (menos precisa, funcional para el ciclo).

### Punto 2 — Reconfirmación Periódica

**Q2.1 — Contrato del endpoint de reconfirmación** `[BLOQUEANTE]` *(necesidades 2.2–2.3)*

Para `PATCH /api/v1/nodos/{id}/reconfirmar`:

1. Confirmar qué hace del lado QBK: actualizar `fecha_ultima_confirmacion` + `ultimo_confirmador_id` + registro de auditoría.
2. Scope de token exigido: asumimos `api:write`.
3. Para una pregunta con N fuentes: ¿aceptan un array de `node_ids` en un solo llamado, o Kuestion hace N llamadas secuenciales? Cualquiera funciona para el ciclo — solo necesitamos saberlo antes de congelar.

*Supuesto:* un llamado por nodo; Kuestion itera sobre los `sources` de la versión actual.

**Q2.2 — Extensión de `POST /query`** `[BLOQUEANTE]` *(necesidad 2.4)*

Confirmar que cada elemento de `sources` incluirá `fecha_ultima_confirmacion` y `ultimo_confirmador_nombre`.

- `null` hasta la primera reconfirmación es aceptable — Kuestion ya maneja "sin dato" con el copy honesto de la Ola 1 P5/6 ("sin reconfirmaciones registradas").

*Supuesto:* campos siempre presentes; `null` si nunca hubo reconfirmación.

**Q2.3 — Nombre del confirmador en modo MVP** `[NO BLOQUEANTE]` *(relacionada con Nudo B1)*

Con B1 en modo MVP (sin identidad humana en las llamadas), ¿qué valor tendrá `ultimo_confirmador_nombre` cuando reconfirma el conector?

*Supuesto:* "Kuestion (conector)" o el string que QuBeKa defina — solo necesitamos un valor mostrable.

### Punto 3 — Indicador de Vigencia

**Sin preguntas nuevas.** El Punto 3 depende íntegramente del Punto 2 (ciclo C2). El estado agregado de la necesidad 3.3 lo resuelve Kuestion del lado cliente — no pedimos endpoint. Alcance V1: solo QBK.

### Punto 4 — Explicabilidad

**Q4.1 — Viabilidad de los metadatos** `[BLOQUEANTE]` *(necesidad 4.4 — la pregunta pivotal del punto)*

¿El `AnalisisService` actual puede producir, con el mismo mecanismo de IA ya construido (`AiProviderFactory` → `OllamaProvider`), estos metadatos por cada nodo propuesto?

| Campo | Tipo |
|---|---|
| `decision_type` | string |
| `confidence` | float 0.0–1.0 |
| `reasons` | array de strings |
| `alternatives_considered` | array de objetos |
| `detected_patterns` | array de strings |

¿Requiere ingeniería de prompt adicional? ¿Esfuerzo estimado?

*Supuesto:* si la respuesta es "no viable en esta etapa", Kuestion construye el bloque "¿Por qué?" con estado honesto `sin_detalle` y el Punto 4 se reduce — el ciclo C3 queda postergado, no roto.

**Q4.2 — Dónde se exponen los metadatos** `[BLOQUEANTE]` *(necesidad 4.3 + detalle)*

Pedimos los metadatos en:

1. `GET /sesiones-analisis/{id}` (detalle) — **mínimo indispensable**: la pantalla de revisión de Kuestion los consume al expandir el "¿Por qué?".
2. `GET /sesiones-analisis` (listado) — ideal, para la bandeja (necesidad 4.3).
3. ¿También en la respuesta de `POST /contribute`? Evitaría un segundo llamado en la confirmación inmediata; no es indispensable.

*Supuesto:* como mínimo en el detalle; el resto se resuelve con carga al expandir.

**Q4.3 — Persistencia de los metadatos** `[NO BLOQUEANTE]` *(necesidad 4.2)*

Confirmar que los metadatos se persisten asociados a la sesión y están disponibles mientras la sesión esté pendiente (único momento en que la bandeja los muestra).

*Supuesto:* persistidos por nodo (como hoy `confianza` / `justificacion_ia` en `NodoAnalisis`).

### Punto 5 — Notificaciones por Correo

**Q5.1 — Autor identificable en listado e historial** `[BLOQUEANTE — depende de B2]` *(necesidad 5.3)*

El correo "tu aporte fue aprobado/rechazado" necesita saber a quién enviarse → depende de la propuesta B2 de la sección 3. Sin B2, el ciclo C4 no funciona de punta a punta.

*Supuesto:* con B2 aprobado, `autor_email` + `autor_nombre` llegan en el listado y el historial.

**Q5.2 — Revisores del workspace** `[NO BLOQUEANTE]` *(necesidad 5.2)*

¿Cómo expondrán quiénes son los revisores del workspace (endpoint nuevo o extensión de `GET /agent/me`)?

No bloquea la bandeja (MVP: todos ven lo pendiente del workspace) ni el ciclo C4 (correos al autor). Solo se necesita para el correo "aporte pendiente de revisión" dirigido a revisores.

*Supuesto:* sin respuesta en esta etapa, ese correo específico queda postergado; el resto de los correos del ciclo funcionan sin él.

**Q5.3 — Filtro incremental para el polling** `[NO BLOQUEANTE]` *(necesidad 5.1)*

Kuestion hará polling periódico de `estado=historial` y detectará cambios comparando contra su último estado conocido. ¿Existe o está planeado un filtro tipo `actualizado_desde=...`?

*Supuesto:* sin filtro, el polling trae el historial completo y Kuestion hace el diff local — funcional para los volúmenes de esta etapa.

---

## 3. Propuesta de Kuestion sobre los Nudos B1 y B2 (para cotizar)

**B1 — Identidad del revisor humano: propuesta MVP**

- **Esta etapa:** opción B — la bandeja muestra todo lo pendiente del workspace al token del conector, sin filtro por usuario humano. Desbloquea C1 y C2 sin cambios de autenticación.
- **Contrato:** el header `X-User-Email` queda **definido como opcional desde el día 1** (non-breaking según las reglas de evolución). QuBeKa lo ignora hasta que implemente su validación.
- **Preguntas:** ¿objeción? ¿Estimación de esfuerzo de la validación del header (pertenencia al workspace + rol de revisor) para una etapa posterior?

**B2 — Autor de los aportes: propuesta mínima**

- Agregar a `POST /contribute` dos campos **opcionales**: `autor_email` y `autor_nombre` (strings, declarados por el conector; QuBeKa no valida cuenta de usuario).
- QuBeKa los persiste como atribución y los devuelve en listado e historial (habilita Q5.1).
- Es non-breaking (campos opcionales) según las reglas de evolución del documento de necesidades.
- **Preguntas:** ¿factible? ¿esfuerzo? ¿objeción a registrar atribución declarada por el conector (no verificada contra cuentas)?

**B3 — Fatiga de correos:** decisión interna de Kuestion/Producto (regla de eventos accionables + deduplicación). Sin pregunta a QuBeKa.

---

## 4. Estado del contrato

### 4.1 Contrato base

Respuestas confirmadas por QuBeKa (Septiembre 2026) → `docs/CONTRATO_API_OLA2.md` (fuente única de contrato para los ciclos C1–C4).

### 4.2 Acuerdos abiertos (pendientes de Producto)

| # | Acuerdo | Estado |
|---|---|---|
| 1 | `creado_en` vs `fecha_creacion` / `contenido_entrada` vs `texto_original_del_aporte` | ✅ Resuelto: ganan `creado_en` y `contenido_entrada` (ver §13 `CONTRATO_API_OLA2.md`) |
| 2 | Literal `"Kuestion (conector)"` | ✅ Confirmado por QuBeKa |
| 3 | Estados finales para polling | ✅ Confirmado: `promocionada`/`rechazada` (no `aprobada`) |
| 4 | `confidence` de explicabilidad | ⏳ Pendiente decisión: documentar como "señal orientativa" |

### 4.3 Próximo paso

1. QuBeKa revisa `CONTRATO_API_OLA2.md` contra código real (≤ 1 día).
2. Si hay discrepancias: reportar por punto y corregir.
3. Cuando confirmen: contrato cerrado; siguiente paso = validación real punto por punto (contrato cerrado en papel ≠ ciclo funcionando).

---

## 5. Entregables comprometidos contra el contrato

Los siguientes entregables del proceso de preguntas comprometen el contrato `docs/CONTRATO_API_OLA2.md` y confirman que la base de preguntas fue fuente de verdad para su generación:

| # | Entregable | Sección del contrato | Estado |
|---|---|---|---|
| 1 | Estructura del listado + campos base + polling sobre estados finales | §4.1, §12, §15 | ✅ Validado por QuBeKa (sección 15 del contrato) |
| 2 | Contrato de reconfirmación + campo en `sources[]` + literal `"Kuestion (conector)"` | §5.1, §5.2, §5.3, §13-2/3 | ✅ Confirmado por QuBeKa |
| 3 | Exposición de explicabilidad: detalle + listado (si P1 aterriza) + `POST /contribute` | §7.1, §7.2 | ✅ Confirmado por QuBeKa |
| 4 | Endpoints `POST .../approve`, `POST .../reject`, `POST /contribute`, `GET /workspaces/{id}/miembros` | §4.3, §4.4, §8.1, §8.2 | ✅ Names corregidos en contrato (vs. borrador anterior) |
| 5 | Sobre de respuesta `success`/`errors` (no `message`/`data` ni `error.code`) | §2, §15 | ✅ Ajustado por revisión de QuBeKa |
| 6 | Filtros `pendientes | historial` (no `pendiendo`/`historia_execution`) | §4.1 | ✅ Ajustado por revisión de QuBeKa |
| 7 | Mapeo de campo `pregunta_previa → pregunta_previa` (no `texto_original_del_aporte`) | §4.1, §7.3 | ✅ Ajustado por revisión de QuBeKa |
| 8 | `creado_en` y `contenido_entrada` como nombres reales (no `fecha_creacion`/`texto_original_del_aporte`) | §13-1 | ✅ Cerrado por Producto + QuBeKa |

---

## 6. Esfuerzo consolidado (lado Kuestion, de acuerdo con planes de implementación)

Los planes de implementación de Kuestion (`docs/ola2-punto1-plan-implementacion-kuestion.md` … `docs/ola2-punto5-plan-implementacion-kuestion.md`) incorporan el contrato como fuente de referencia y los entregables comprometidos de cada punto. La estimación total de Kuestion corresponde a la suma de los planes (ver sección 5 de cada plan).

