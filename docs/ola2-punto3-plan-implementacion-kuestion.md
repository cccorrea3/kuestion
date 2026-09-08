# Plan de Implementación — Ola 2, Punto 3: Indicador de vigencia visible en la respuesta

*Equipo de Kuestion · Septiembre 2026*
*Documento de entrada: `OLA_2_Punto_3.md` (especificación cerrada de producto)*
*Contrato de referencia: `docs/CONTRATO_API_OLA2.md` (fuente única de contrato Ola 2)

---

## 1. RESUMEN DE ALCANCE

---

## 1. RESUMEN DE ALCANCE

### Qué voy a construir

Un **indicador visual de vigencia** que Kuestion muestra junto a cada respuesta de una pregunta vigilada, informando al usuario si el conocimiento que consume sigue siendo válido y ofreciendo la acción de reconfirmación cuando corresponde. Es un pulido de experiencia (colores, copy, posición), **no construcción de backend nuevo**: consume los datos que la Ola 2, Punto 2 hará disponibles para QBK (`fecha_ultima_confirmacion`, `ultimo_confirmador_nombre`, endpoint de reconfirmación) y las señales de Kuaforia (hoy workspace-level, ver hallazgo 3).

En concreto, en Kuestion se construye:

1. **Un componente visual de indicador de vigencia** con estados semánticos (verde "Vigente", amarillo "Pendiente de reconfirmación", rojo "Posiblemente obsoleto") y copy en lenguaje natural, con tooltip de detalle (fecha exacta y quién reconfirmó cuando esté disponible).
2. **La lógica de cálculo del estado** por fuente de la respuesta:
   - **QBK**: a partir de `fecha_ultima_confirmacion` de las fuentes de la versión actual vs. el umbral (90 días, decisión cerrada), con el caso "Sin reconfirmaciones registradas" cuando el campo no exista.
   - **Kuaforia**: a partir de las señales que Kuaforia devuelve/expone (estado actual: solo tools de workspace — ver hallazgo 3 y duda B2).
3. **El botón/enlace de acción** dentro del indicador: "Reconfirmar" (conectado al endpoint de QuBeKa del Punto 2) para QBK; para Kuaforia, enlace a la UI de Kuaforia para revisar el caso (sin reconfirmación integrada — decisión abierta del origen, se asume "solo enlace").
4. **Adaptaciones de nivel de detalle**: indicador completo en el detalle de la respuesta; mini-indicador (estado + fecha, sin botón) en la card del feed; indicador completo en la bandeja de revisión (Ola 2, Punto 1 — condicional).

### Qué NO construyo

- El campo `fecha_ultima_confirmacion` ni el endpoint de reconfirmación — trabajo de QuBeKa (Ola 2, Punto 2).
- Historial completo de reconfirmaciones — explícitamente fuera (§2.5 del origen).
- Ajuste del umbral desde la respuesta — fuera (§2.5; será un punto posterior de la Ola 2).
- Reconfirmación integrada para Kuaforia — decisión abierta del origen (se asume "solo enlace a Kuaforia").
- Integración de la reconfirmación con el flujo "Aportar" — fuera (§2.5).

### Hallazgos de la revisión del código que condicionan el alcance

1. **QBK — el dato de vigencia no llega todavía.** `QbkService::consult()` mapea `sources` completos (con `node_id`, `tipo`, `estado_validacion`, `texto_preview`, `camino`) pero **sin `fecha_ultima_confirmacion`** — el contrato de `/query` se amplía en la Ola 2, Punto 2. El indicador QBK en su estado real depende de ese trabajo (duda B1). El componente se puede construir y testear con mock mientras tanto.
2. **Kuaforia — las señales NO llegan por respuesta.** El spec asume leer `stale_case`/`low_confidence`/`deps_changed` "cuando la respuesta provenga de Kuaforia". En el código actual esas señales **no existen como datos por respuesta**: `StructuredSignalProviderInterface` expone tools de **workspace** (`getWorkspaceHealth`, `getDependencyHealthReport`) y `getCaseDetails(case_id)`, que hoy solo se invocan en `QuestionChecker::collectSignals()` al detectar un cambio, y el resultado se guarda **en el payload de la notificación**, no por versión ni para mostrar en la vista. Además, `KuaforiaResponse` (respuesta de `/consult`) no trae `case_id` ni señales. **Esto contradice el supuesto del origen y es la duda central del plan (B2): falta definir de dónde sale el estado "Posiblemente obsoleto" de Kuaforia por respuesta.**
3. **No hay hoy ningún indicador de vigencia en la UI** — el copy honesto de la Ola 1 (P5/6, "sin reconfirmaciones registradas") es lo más cercano y es justamente el estado que el nuevo indicador absorberá para QBK cuando el campo exista (sin regresión mientras tanto).
4. **La vista de detalle ya muestra confianza y fuentes** (`question-detail.blade.php`: tooltip de "búsqueda basada en texto" con confidence ≤ 50, contador de fuentes) — el indicador de vigencia es un bloque nuevo independiente que se ubica "justo después de la respuesta, antes de las fuentes" según §2.2.
5. **El signo visual semántico ya se usa en la app** (verde teal para éxito, ámbar para pendiente, rojo para error/danger) — el indicador debe reutilizar esos colores del diseño existente, no inventar una paleta nueva.

---

## 2. FASES Y TAREAS

### Fase A — Servicio de estado de vigencia (lógica pura, por fuente)

**Objetivo:** calcular el estado del indicador (`vigente` / `pendiente_reconfirmacion` / `posiblemente_obsoleto` / `sin_dato`) a partir de la fuente de la respuesta y los datos disponibles, sin UI todavía.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| A.1 | Definir y codificar el cálculo para **QBK**: dado el conjunto de `sources` de la versión actual con `fecha_ultima_confirmacion`, resolver el estado agregado (definir criterio de agregación: peor estado / más reciente / nodo principal — duda D1). Umbral desde `config/kuestion.php` (90 días, mismo patrón que Punto 2). Campos ausentes → `sin_dato`. | Ola 2, Punto 2 (config umbral); contrato QBK con campo (mock mientras tanto) | Función/servicio `VigenciaResolver` (o similar) con estados tipados. |
| A.2 | Definir el cálculo para **Kuaforia** según lo que cierre la duda B2 (señales por respuesta vs. workspace): si no hay dato por respuesta, el estado posible es `sin_dato`/`vigente_sin_dato` hasta que exista la señal — el plan no inventa un mecanismo (ver B2). | Hallazgo 2 + respuesta de producto/QuBeKa | Regla clara documentada para el caso Kuaforia. |
| A.3 | Tests unitarios del cálculo: QBK dentro/vencido/sin campo; umbral configurable; caso Kuaforia según regla. | A.1, A.2 | Tests verdes. |

**Entregable verificable:** dada una pregunta QBK con fuentes mockeadas (con/sin `fecha_ultima_confirmacion`, dentro/fuera del umbral), el servicio devuelve el estado correcto. Para Kuaforia, la regla queda documentada y testeada según la decisión de B2.

**Validación:** tests unitarios + checklist FA.

---

### Fase B — Componente visual del indicador (detalle de la respuesta)

**Objetivo:** el indicador visible en el detalle de la pregunta, con colores semánticos, copy en lenguaje natural, tooltip y botón de acción.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| B.1 | Componente Blade/Livewire `vigencia-indicator` (o similar) que recibe el estado calculado y el connector_type; renderiza el bloque con color, ícono (check/clock/alert), copy y tooltip. Reutilizar el sistema de colores existente de la app. | Fase A | Componente renderizable con los 4 estados. |
| B.2 | Integrar en `question-detail.blade.php` justo después de la respuesta y antes de las fuentes (posición §2.2). Para QBK con estado `sin_dato`, absorber el copy honesto de P5/6 (sin regresión de `QuestionVigenciaCopyTest`). | B.1 | Indicador visible en el detalle. |
| B.3 | Tooltip con detalle (fecha exacta, quién reconfirmó si viene `ultimo_confirmador_nombre`) — patrón de tooltip ya usado en el detalle para "búsqueda basada en texto". | B.1 | Tooltip de trazabilidad. |
| B.4 | Botón/enlace según estado y fuente: QBK vencido/sin_dato → "Reconfirmar" (acción del Punto 2); Kuaforia rojo → enlace a la UI de Kuaforia ("Revisar en Kuaforia"); vigente → sin acción o acción opcional según decisión de copy. | Ola 2, Punto 2 (endpoint) o mock | Acción correcta por estado. |

**Entregable verificable:** al abrir una pregunta QBK cuya vigencia supera el umbral, se ve el bloque amarillo "Última confirmación: hace 95 días — [Reconfirmar]"; dentro del umbral, verde "Vigente"; Kuaforia con señal, rojo con enlace. Copy no técnico, contraste correcto.

**Validación:** tests del componente + checklist FB (visual en navegador, assets compilados).

---

### Fase C — Acción de reconfirmación desde el indicador

**Objetivo:** que el botón del indicador ejecute la reconfirmación síncrona (patrón del Punto 2), actualice el indicador en la misma vista y maneje los errores visibles.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| C.1 | Conectar "Reconfirmar" a `reconfirmarNodos()` (Punto 2, Fase A) con los `node_id` de las fuentes; estado de procesamiento; actualización optimista del indicador a "¡Reconfirmado! Última confirmación: ahora". | Ola 2, Punto 2 (Fase A y C); Fase B | Reconfirmación síncrona desde el indicador. |
| C.2 | Manejo de errores visibles (ítem obligatorio): 403 (sin permiso) → botón deshabilitado/no mostrado (patrón §5 del origen); 404 (nodo eliminado) → mensaje "ya no disponible"; 5xx/timeout → error amigable con reintento, sin doble envío. | C.1 | Errores claros en runtime. |
| C.3 | No cachear el indicador más de unos minutos (§5 del origen): refrescar tras reconfirmar y en cada carga de la vista (sin recarga completa). | C.1 | Indicador fresco. |

**Entregable verificable:** desde el detalle, el usuario reconfirma con un clic y el indicador cambia a verde "ahora" sin recargar la página; si no tiene permiso, el botón no aparece o está deshabilitado; si QuBeKa falla, ve un error claro y puede reintentar.

**Validación:** tests de componente + checklist FC contra QuBeKa real cuando el endpoint exista (el Punto 2 debe estar desplegado).

---

### Fase D — Mini-indicador en el feed + indicador en la bandeja

**Objetivo:** llevar el estado de vigencia a las otras dos superficies con el nivel de detalle adecuado.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| D.1 | Mini-indicador en `question-card` (feed): solo estado + fecha (badge de color), **sin botón** (§2.2 — el botón se abre en el detalle). Reutilizar el estado calculado de la Fase A sobre las fuentes de la versión actual (sin N+1: eager-load ya existente de `currentVersion`). | Fase A | Badge de vigencia en el feed. |
| D.2 | Indicador completo en la bandeja de revisión (Ola 2, Punto 1) para ítems pendientes de reconfirmar — **condicional a que el Punto 1 esté construido**; si no, se difiere con el motivo declarado. | Ola 2, Punto 1; Fase B | Indicador en la bandeja. |

**Entregable verificable:** en el feed, cada pregunta QBK muestra un mini-badge coherente con el detalle; en la bandeja (si existe), los pendientes de reconfirmar muestran el indicador completo con botón.

**Validación:** tests + checklist FD (visual, assets).

---

### Fase E — QA, regresión y cierre

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| E.1 | Regresión de los flujos tocados: `QuestionVigenciaCopyTest` (copy honesto convive con el nuevo indicador), `QuestionDetail*`, `QuestionFeed*`, `QuestionChecker*` (las señales de notificación no cambian). | Fases A–C | Regresión verde. |
| E.2 | Validación de punta a punta contra QBK real (cuando el Punto 2 esté desplegado): pregunta con vigencia >90 días → indicador amarillo → reconfirmar → indicador verde "ahora" → verificar en QuBeKa. Para Kuaforia, verificar el caso rojo con el mecanismo que cierre B2. | QuBeKa real (Punto 2) | E2E real documentado. |
| E.3 | Rebuild de assets (`npm run build`) + clases en bundle + verificación visual en navegador (ítems obligatorios de la sección 3). | Fases B, D | Assets y visual verificados. |
| E.4 | Suite completa + Pint + documento de cierre. | Todo | Verde y documentado. |

---

## 3. PRUEBAS FUNCIONALES Y DE INTEGRACIÓN

Ítems obligatorios en toda fase con UI o integración (no negociables):

1. **Rebuild y verificación de assets compilados**: cada cambio de vista (Fases B, C, D) exige `npm run build` y verificar en el bundle (`public/build/`) las clases usadas (p. ej. `bg-teal-50`, `text-amber-700`, `border-red-200`). Verificación con grep contra el CSS servido.
2. **Verificación visual en navegador real**: los tres estados (verde/amarillo/rojo) se inspeccionan con DevTools (visible, legible, contraste, ícono, tooltip al hover). Un test con mock no cierra la fase.
3. **Compatibilidad con la versión instalada**: verificar APIs contra `composer.json` y el vendor (Livewire `^4.0`: `redirect()` existe, `redirectExternal()` no). El enlace a la UI de Kuaforia del caso rojo usa navegación estándar, no APIs inventadas.
4. **Fallo visible y claro en runtime**: errores de la reconfirmación (403/404/5xx/timeout) legibles y recuperables; nunca "cargando..." infinito ni estado silencioso.
5. **Prueba contra el servicio real**: el indicador QBK real y el botón requieren el Punto 2 desplegado en QuBeKa; el caso Kuaforia requiere el mecanismo que cierre B2. Hasta entonces se usa mock del contrato y se declara qué queda pendiente.

### Checklist FA — Servicio de estado de vigencia

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| FA.1 | Fuentes QBK dentro del umbral | Mock con `fecha_ultima_confirmacion` reciente | Estado `vigente` |
| FA.2 | Fuentes QBK vencidas (>90 días) | Mock con fecha antigua | Estado `pendiente_reconfirmacion` |
| FA.3 | QBK sin el campo en el contrato | Mock sin `fecha_ultima_confirmacion` | Estado `sin_dato` (copy honesto P5/6) |
| FA.4 | Umbral configurable | Cambiar config | Cambia el límite entre estados |
| FA.5 | Regla Kuaforia | Según decisión B2 | Documentada y testeada |

### Checklist FB — Indicador en el detalle (UI)

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| FB.1 | Estado verde | Navegador real + DevTools | "Última confirmación: hace 2 días — Vigente", ícono check, contraste OK |
| FB.2 | Estado amarillo | Navegador real | "Última confirmación: hace 95 días — Pendiente de reconfirmación [Reconfirmar]" |
| FB.3 | Estado rojo (Kuaforia) | Navegador real | "Posiblemente obsoleto — [Revisar en Kuaforia]" con enlace |
| FB.4 | Sin reconfirmaciones (QBK nuevo) | Navegador real | "Sin reconfirmaciones registradas — [Reconfirmar]" |
| FB.5 | Tooltip | Hover con DevTools | Fecha exacta + quién reconfirmó (si disponible) |
| FB.6 | Posición | Navegador real | Justo después de la respuesta, antes de las fuentes |
| FB.7 | Rebuild de assets | `npm run build` + grep | Clases del indicador en el bundle |

### Checklist FC — Reconfirmación desde el indicador (UI + integración real)

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| FC.1 | Reconfirmar QBK vencido | Navegador real (QuBeKa real) | Indicador → verde "¡Reconfirmado! Última confirmación: ahora", sin recargar |
| FC.2 | Sin permiso | QuBeKa real, token sin rol | Botón oculto/deshabilitado (o error 403 claro) |
| FC.3 | Nodo eliminado | QuBeKa real | Mensaje "ya no disponible para reconfirmar" |
| FC.4 | QuBeKa caído / 5xx | Mock / real | Error amigable con reintento, sin doble envío |
| FC.5 | Estado real en QuBeKa | Verificar en QuBeKa | `fecha_ultima_confirmacion` actualizada |

### Checklist FD — Feed y bandeja (UI)

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| FD.1 | Mini-badge en el feed | Navegador real | Color/estado coherente con el detalle, sin botón |
| FD.2 | Indicador en la bandeja (si Punto 1 existe) | Navegador real | Completo, con botón para pendientes de reconfirmar |
| FD.3 | Rebuild + visual | Navegador real | Clases en bundle, legible |

### Matriz mock vs real

| Fase | Con mock (siempre) | Contra servicio real (cuando esté disponible) |
|---|---|---|
| A — Servicio | Fuentes fake con/sin campo | **Requiere Punto 2 (campo en `/query`)** |
| B — Componente | Estados fake | Visual con datos reales del Punto 2 |
| C — Acción | reconfirm mockeado | **Obligatorio**: reconfirmar → campo actualizado en QuBeKa |
| D — Feed/bandeja | Estados fake | Bandeja requiere Punto 1 de la Ola 2 |
| E — Cierre | Suite completa | E2E real documentado |

**Regla de cierre:** ninguna fase se declara cerrada sin completar los ítems obligatorios. Si alguno no se pudo hacer (validación real porque el Punto 2 o la señal de Kuaforia no existen), se declara explícitamente como pendiente con su motivo.

---

## 4. DUDAS Y BLOQUEOS

### Bloqueantes

| # | Pregunta | Para quién |
|---|---|---|
| B1 | **Disponibilidad del Punto 2 (campo `fecha_ultima_confirmacion` + endpoint)**: el indicador QBK real y el botón dependen de él. ¿Cuándo estará desplegado? Mientras tanto se trabaja con mock. | QuBeKa (vía Ola 2, Punto 2) |
| B2 | **Origen del estado Kuaforia por respuesta (contradicción con el origen)**: el spec (§2.3, §6) asume leer `stale_case`/`low_confidence`/`deps_changed` por respuesta desde `StructuredSignalProviderInterface`, pero hoy esa interfaz expone tools de **workspace** (usados solo al detectar cambio, guardados en la notificación) y `KuaforiaResponse` no trae `case_id` ni señales. ¿Kuaforia expondrá señales por caso en la respuesta de `/consult`, o hay que llamar `getCaseDetails` con un `case_id` que hoy no llega? **Sin respuesta no se puede implementar el estado "Posiblemente obsoleto" de Kuaforia de forma honesta** — Kuestion no lo inventa. | QuBeKa / Producto |
| B3 | **Contrato ampliado de `/query` de QBK**: confirmar que cada `source` incluya `fecha_ultima_confirmacion` y `ultimo_confirmador_nombre` (lo pide el Punto 2; acá se consume). | QuBeKa (vía Ola 2, Punto 2) |

### No bloqueantes

| # | Pregunta | Supuesto / estado | Para quién |
|---|---|---|---|
| D1 | **Agregación para respuestas con varias fuentes**: el spec (§6, ítem 3) sugiere "estado del nodo principal o el más reciente". | Se asume: estado = el **peor** entre fuentes vencidas/sin dato (conservador, prioriza la alerta), con el detalle del nodo principal en el tooltip. Confirmar con producto. | Producto / Interno |
| D2 | Respuesta que combina nodos QBK + Kuaforia (decisión abierta del origen) | Se asume indicador de la **fuente principal** (la del repositorio de la pregunta); hoy una pregunta pertenece a un solo repositorio, por lo que el caso mixto no ocurre en la práctica. Confirmar. | Producto |
| D3 | ¿Mostrar botón "Reconfirmar" también en estado `vigente` (opcional) o solo vencido/sin dato? (§4.1 lo menciona como posible) | Se asume: visible en `pendiente_reconfirmacion` y `sin_dato`; oculto en `vigente` (sin fricción). Confirmar con UX. | Producto / UX |
| D4 | Fecha exacta vs. relativa en el texto principal (decisión abierta) | Se asume relativa ("hace X días") en el texto y exacta en el tooltip, como propone el origen. | UX |
| D5 | Umbral configurable | Fuera de alcance (decisión abierta del origen); solo config por entorno (default 90). | Producto |
| D6 | La bandeja (Fase D.2) depende de la Ola 2, Punto 1 | Si el Punto 1 no está construido, la tarea se difiere con el motivo declarado (no se simula). | Interno |
| D7 | Nombre de la fuente mostrado junto al indicador | El feed ya muestra el tag de fuente condicional (Ola 1 P5/6, Fase 2); no se agrega otro indicador de fuente acá. | Interno |

---

## 5. ESFUERZO ESTIMADO

| Fase | Esfuerzo estimado | Incertidumbre |
|---|---|---|
| **Fase A** — Servicio de estado de vigencia | S (0.5–1 d) | **Alta por el caso Kuaforia (B2)**: la lógica QBK es simple; la de Kuaforia no se puede definir hasta saber de dónde sale la señal por respuesta. |
| **Fase B** — Componente visual (detalle) | M (1–1.5 d) | Media: trabajo de UI con 4 estados, tooltip y posición; reutiliza patrones existentes (tooltip de confianza, colores). |
| **Fase C** — Acción de reconfirmación | S–M (0.5–1 d) | Baja-media: reutiliza la lógica del Punto 2; el riesgo es la actualización optimista y los 4 errores visibles. |
| **Fase D** — Feed + bandeja | S (0.5–1 d) | Media: feed es badge simple; bandeja depende del Punto 1 (D6). |
| **Fase E** — QA y cierre | S (0.5–1 d) | Baja. |
| **TOTAL** | **S–M (3–5.5 d)** | Incertidumbre concentrada en A/B2 (origen de la señal Kuaforia) y en la dependencia del Punto 2 para la validación real. El resto es pulido de UI sobre patrones existentes, como advierte el origen (§8). |

---

## 6. FUERA DE ALCANCE

| Elemento | Por qué queda fuera |
|---|---|
| Campo `fecha_ultima_confirmacion` + endpoint de reconfirmación | Trabajo de QuBeKa (Ola 2, Punto 2). Kuestion consume. |
| Señal de vigencia por respuesta de Kuaforia (si no existe) | Kuestion no inventa un mecanismo; lo pide como bloqueante B2 y consume lo que exista. |
| Historial completo de reconfirmaciones | Explícitamente fuera (§2.5 del origen). |
| Ajuste del umbral desde la respuesta o perfil | Fuera (§2.5); punto posterior de la Ola 2. |
| Reconconfirmación integrada para Kuaforia | Decisión abierta; se asume "solo enlace a la UI de Kuaforia". |
| Integración reconfirmación ↔ flujo "Aportar" | Fuera (§2.5). |
| Filtros o agrupaciones nuevas en el feed | No pedidos; el mini-indicador no agrega navegación nueva. |
| WebSockets / tiempo real | El origen §5 pide polling ligero o actualización en vista; no infraestructura nueva. |

---

## 7. Entregables comprometidos contra el contrato

Los siguientes entregables de esta fase comprometen el contrato `docs/CONTRATO_API_OLA2.md`:

| # | Entregable | Sección del contrato | Compromiso |
|---|---|---|---|
| 1 | Indicador de vigencia consumo de `sources[]` con `fecha_ultima_confirmacion` + `ultimo_confirmador_nombre` | §6, §5.2 | Resuelve stato agregado lado cliente; sin endpoint nuevo. |
| 2 | Botón "Reconfirmar" conecta a `PATCH /api/v1/nodos/{id}/reconfirmar` | §5.1 | Acción síncrona por nodo, con handling de 403/404/5xx como contrato. |
| 3 | Literal `"Kuestion (conector)"` como `ultimo_confirmador_nombre` visible en tooltip | §5.3, §13-2 | String congruente con lo que QuBeKa escribe. |
| 4 | Degradación a copy honesto P5/6 cuando campo ausente (`sin_dato`) | §5.2 (campo `null` aceptable) | Sin regresión de Ola 1 P5/6 mientras QuBeKa tá campo. |

---

## 8. Trazabilidad de referencias cruzadas (Ola 1)

- Este plan no reabre el contrato de la Ola 1. Referencia conceptual `ola1-preguntas-abiertas.md` solo como registro histórico de la Ola 1.
