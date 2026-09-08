# Plan de Implementación — Ola 2, Punto 4: Explicabilidad de cada propuesta automática

*Equipo de Kuestion · Septiembre 2026*
*Documento de entrada: `OLA_2_Punto_4.md` (especificación cerrada de producto)*
*Contrato de referencia: `docs/CONTRATO_API_OLA2.md` (fuente única de contrato Ola 2)

---

## 1. RESUMEN DE ALCANCE

---

## 1. RESUMEN DE ALCANCE

### Qué voy a construir

Un **bloque de explicación expandible** que acompaña cada propuesta automática de clasificación y le muestra a la persona **por qué** el sistema propuso ese tipo de nodo (Q/SQ/H/N-K/N-A), **con qué confianza**, **qué señales usó** y **qué alternativas descartó** — en lenguaje natural, sin jerga QBK. Kuestion no genera la explicación: la recibe como **metadatos estructurados** que QuBeKa debe producir en el momento de la clasificación (sección 2.3 del documento de entrada), y la **traduce a texto con plantillas predefinidas**.

Ese bloque aparece en las superficies que define el documento (§2.2):

1. **En la confirmación inmediata posterior a un "Aportar"**: un enlace "Ver detalles de la clasificación" que expande la explicación (hoy `ContributeAporte` solo muestra el `resumen` de una línea).
2. **En la revisión de la Ola 1, Punto 4** (`ContributionReview`): un "¿Por qué?" por nodo propuesto, colapsado por defecto (es la revisión simple que Kuestion ya ofrece hoy para sus aportes).
3. **En la bandeja del Punto 1 (Ola 2)** y su **historial**: el mismo bloque reutilizado por ítem — **condicional a que el Punto 1 se implemente** (hoy solo existe su plan; no hay bandeja en el código).

No hay backend nuevo en Kuestion: la lógica es **parsear metadatos → traducir con plantillas → mostrar**. Los metadatos viajan por las APIs de sesiones de QuBeKa ya en uso.

### Hallazgos de la revisión del código que condicionan el alcance

- **`QbkContributionService` hoy no toca ningún campo de explicabilidad.** `getSession()` mapea de la sesión: `is_simple`, `pregunta_previa`, `resumen`, `nodos` (id/tipo/texto/relaciones), `created_at`, `workspace_nombre`. `contribute()` solo devuelve `session_id/status/resumen`. No hay `confidence`, `reasons`, `alternatives_considered` ni `detected_patterns` en ningún punto del flujo. **Todo el parsing de metadatos es trabajo nuevo** (Fase A).
- **La vista de revisión `ContributionReview` ya intenta leer un campo `justificacion` por nodo, pero el contrato confirmado de QuBeKa en la Ola 1 (Punto 4, respuesta B1) lista nodos solo con `id/tipo/texto/relaciones`** — sin `justificacion`. Hoy ese campo se renderiza como `null` en la práctica. Los `reasons` estructurados del Punto 4 vienen a reemplazar/augmentar esa zona: hay que confirmar con QuBeKa si `justificacion` (o su `justificacion_ia` interna, que su propia respuesta B1 dice existir en `NodoAnalisis`) se conserva, se renombra o se sustituye por `reasons` — y **no duplicar** el mapeo viejo junto al nuevo (duda B2).
- **La confirmación inmediata (`ContributeAporte`, estado `saved`) hoy no guarda más que `session_id` + `resumen`.** El contrato de `POST /contribute` confirmado en la Ola 1 (Punto 3) no incluye metadatos. Para que "Ver detalles de la clasificación" funcione hay que decidir de dónde sale el dato (duda D2; supuesto: consulta al detalle de sesión al expandir).
- **El metadata se genera en el momento de la clasificación (QuBeKa) y queda asociado a la sesión en su sandbox** (§5 del documento de entrada). Las sesiones **creadas antes** del despliegue de esta extensión no tendrán metadatos: la UI debe degradar con elegancia ("sin detalle de clasificación para aportes anteriores"), nunca inventar una explicación (duda D3).
- **El feed de vigilancia no lleva explicación**: la decisión abierta N°2 del documento de entrada propone que en el feed solo vaya el indicador de vigencia del Punto 3 (supuesto D6, alineado con el plan del Punto 3 ya generado).
- La bandeja del Punto 1 no existe en código (solo `docs/ola2-punto1-plan-implementacion-kuestion.md`): la integración en bandeja/historial queda **marcada como dependiente** de ese plan, no simulada.

### Lo que NO construyo en esta versión

- La generación de metadatos (razones, alternativas, patrones) — es trabajo de QuBeKa (§6 del documento de entrada) y su viabilidad es el paso 1 del punto (§8).
- El almacenamiento de metadatos en el sandbox — QuBeKa.
- La definición de las reglas/patrones de clasificación que producen las razones — QuBeKa (§6, ítem 5).
- Resaltado de palabras clave en el texto original (decisión abierta N°1: "opcional en una primera versión" → queda fuera).
- Enlace a documentación del método QBK (decisión abierta N°3: ola posterior).
- Internacionalización (solo español, §5 del documento de entrada).

---

## 2. FASES Y TAREAS

### Fase A — Contrato de metadatos + parsing en el servicio

**Objetivo:** que `QbkContributionService` entienda los metadatos de explicabilidad del contrato extendido y los normalice para el resto de la app, sin depender de que QuBeKa ya los haya desplegado.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| A.1 | Proponer a QuBeKa el contrato mínimo de metadatos (basado en §2.3 del documento de entrada): dónde viajan (`GET /sesiones-analisis/{id}`, ¿`POST /contribute`?, ¿listado del Punto 1?), si son **por nodo** o **por sesión**, nombres de campo (snake_case), y la relación con `justificacion`/`confianza` que QuBeKa ya almacena por `NodoAnalisis`. Confirmar por escrito. | Coordinación con QuBeKa (no bloquea el desarrollo: se trabaja con mock del contrato §2.3). | Contrato escrito y acordado (sección 4, B1/B2). |
| A.2 | Extender el mapeo de `getSession()` (y de `contribute()` si el contrato lo incluye) para parsear `decision_type`, `confidence`, `reasons[]`, `alternatives_considered[]` y `detected_patterns[]`, siguiendo el patrón de normalización existente del servicio (snake_case, default seguro cuando el campo no viene). | A.1 (contrato esperado; Http fake) | Parsing + tests unitarios con fixtures (metadata completa, parcial y ausente). |
| A.3 | Definir las estructuras tipadas que consumen la UI (p. ej. `explicacion` por nodo: tipo, confianza, razones, alternativas con tipo+motivo de descarte, patrones) para que las vistas no dependan de claves crudas. | A.2 | Normalización lista para Fases B/C. |
| A.4 | Manejo del caso **sin metadata** (sesiones previas al despliegue o campos ausentes): flag explícito `sin_detalle` en la normalización para que la UI muestre el estado de degradación sin romper. | A.2 | Comportamiento definido y testeado para datos viejos. |

**Entregable verificable:** con el contrato simulado (Http fake), `getSession()` devuelve los nodos con su explicación normalizada (o el flag `sin_detalle`), y la suite de `QbkContributionService` queda en verde. **El cierre de la fase depende de la confirmación de QuBeKa (B1/B2).**

**Validación:** tests del servicio (contrato con fake) + checklist FA de la sección 3.

---

### Fase B — Capa de traducción a lenguaje natural (plantillas + copy)

**Objetivo:** convertir los metadatos en el texto claro y no técnico que define el documento (§2.4), con plantillas fijas — no con LLM en tiempo real (§5).

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| B.1 | Crear el presentador `ExplicacionPresenter` (o similar) que arma las frases por plantilla: frase principal por tipo (`Se clasificó como Hipótesis porque el texto describe una causa probable…`), alternativas descartadas (`También se evaluó como Nota de Conocimiento, pero se descartó porque…`), patrones detectados y nota de advertencia por confianza baja. | Fase A | Presentador + copy centralizado (único lugar donde vive el texto). |
| B.2 | Umbrales de confianza y su semáforo según §2.4: verde >80%, amarillo 50–80%, rojo <50% (constantes en un solo lugar, patrón de `config/kuestion.php`). | B.1 | Regla de color testeable por rango. |
| B.3 | Regla de **consistencia del copy con las reglas de clasificación de QuBeKa** (§6-A5): las razones se alinean con los patrones que QuBeKa defina; Kuestion solo referencia los `detected_patterns`/`reasons` que QuBeKa envía (sin re-clasificar ni inferir por su cuenta). | B.1, confirmación de QuBeKa sobre patrones | Garantía de que Kuestion no contradice al clasificador. |
| B.4 | Tests de plantillas: cobertura de cada tipo (Q, SQ, H, N-K, N-A), metadata parcial (razones vacías, sin alternativas), y el estado `sin_detalle`. | B.1–B.3 | Suite de copy en verde. |

**Entregable verificable:** dado un fixture de metadatos, el presentador produce el bloque de texto en lenguaje natural que muestra el documento (§2.4, ejemplos 4.1–4.3), consistente y sin jerga técnica.

**Validación:** tests unitarios del presentador + revisión de copy con UX (ítem de la sección 3: el copy es parte del entregable visual).

---

### Fase C — Bloque visual reutilizable + confirmación inmediata del "Aportar"

**Objetivo:** que exista el componente de UI de explicación (colapsable, con semáforo de confianza) y que aparezca en la confirmación inmediata posterior a un aporte.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| C.1 | Componente/bloque `classification-explanation` (Blade, reutilizable): "¿Por qué?" que expande en el mismo lugar (sin navegar), frases del presentador (B.1), indicador de confianza con barra/color (B.2), y estado `sin_detalle` con copy honesto ("Este aporte no tiene detalle de clasificación disponible"). | Fase A, B | Componente + vista. |
| C.2 | Integrarlo en `ContributeAporte` (estado `saved`): enlace "Ver detalles de la clasificación" bajo el resumen que expande el bloque. Fuente del dato según la decisión de la duda D2 (supuesto: consulta a `getSession()` al expandir, con la `session_id` que ya guarda el draft). | C.1, Fase A | Confirmación inmediata con explicación expandible. |
| C.3 | Fallo visible si la consulta del detalle falla al expandir (401/403/404/5xx/timeout): mensaje legible y enlace para reintentar — nunca quedar en "cargando…" (ítem obligatorio de la sección 3). | C.2 | Error visible y claro. |
| C.4 | Rebuild de assets (`npm run build`) + verificación de las clases nuevas en el CSS servido + verificación visual en navegador real (ítems obligatorios de la sección 3). | C.1–C.3 | Assets y visual verificados. |

**Entregable verificable:** una persona aporta un texto, ve el resumen y expande "Ver detalles de la clasificación": lee por qué se clasificó así, la confianza con su color y las alternativas descartadas, en lenguaje natural.

**Validación:** tests del componente (con el presentador y el servicio simulados) + checklist FC de la sección 3.

---

### Fase D — Integración en la revisión de la Ola 1 y en la bandeja del Punto 1 (condicional)

**Objetivo:** llevar el mismo bloque a los demás momentos de revisión: el detalle simple de la Ola 1 (hoy) y la bandeja/historial del Punto 1 (cuando exista).

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| D.1 | En `ContributionReview` (vista de revisión de la Ola 1): botón "¿Por qué?" por **nodo propuesto** que expande la explicación de ese nodo (colapsado por defecto). Reutiliza C.1 con la metadata normalizada de A.2. | Fases A–C | Explicación por nodo en la revisión simple actual. |
| D.2 | **En la bandeja del Punto 1** (ítem pendiente) y en su **historial**: el mismo bloque "¿Por qué?" por ítem, reutilizando el componente — sin lógica duplicada. **Condicional: la bandeja no existe hoy; esta tarea se ejecuta cuando el Punto 1 esté en código.** | Punto 1 (Ola 2) desplegado + Fase C | Explicación en bandeja e historial. |
| D.3 | Rebuild de assets + verificación en navegador real de las dos superficies (D.1 hoy; D.2 cuando exista). | D.1, D.2 | Visual verificado por superficie. |

**Entregable verificable:** en la revisión de la Ola 1, cada nodo propuesto muestra su "¿Por qué?" expandible con confianza y alternativas. La integración en bandeja/historial queda **declarada pendiente hasta que el Punto 1 exista** (regla de la sección 3: no se simula una dependencia que no está).

**Validación:** tests del componente en `ContributionReview` + checklist FD de la sección 3 (la parte de bandeja se valida cuando el Punto 1 esté disponible).

---

### Fase E — QA, regresión y cierre

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| E.1 | Regresión de los flujos que tocan: suites `ContributeAporte*`, `ContributionReview*`, `QbkContributionService*`, `PendingReviewBadge*` y del presentador nuevo — verificar que aprobar/rechazar/aportar siguen funcionando con el bloque agregado. | Fases A–D | Regresión verde. |
| E.2 | Validación de punta a punta contra QuBeKa real (cuando tenga el servicio de clasificación extendido): aportar un texto → expandir "Ver detalles de la clasificación" con metadata real → revisar → aprobar. Verificar que las razones mostradas corresponden a lo que QuBeKa realmente clasificó (consistencia §5). | QuBeKa con metadatos desplegados (B1/B3) | E2E real documentado. |
| E.3 | Suite completa + Pint + documento de cierre (hallazgos, evidencia, pendientes declarados). | Todo | Verde y documentado. |

---

## 3. PRUEBAS FUNCIONALES Y DE INTEGRACIÓN

Ítems obligatorios en toda fase con UI o integración (no negociables):

1. **Rebuild y verificación de assets compilados**: todo cambio en vistas (Fases C, D) exige `npm run build` y verificar en el CSS servido (`public/build/`) las clases nuevas (p. ej. los colores del semáforo de confianza: `bg-emerald-100`, `bg-amber-100`, `bg-red-100` y sus textos). Verificación con grep contra el bundle.
2. **Verificación visual en navegador real**: el "¿Por qué?" se inspecciona con DevTools (visible al expandir, texto legible, contraste del semáforo, enlace distinguible como tal). Un test con mock no cierra una fase con UI.
3. **Compatibilidad con la versión instalada**: antes de usar APIs de Livewire/Laravel, verificar contra `composer.json` y el vendor. Ejemplo conocido del proyecto: Livewire `^4.0` tiene `redirect()` pero **no** `redirectExternal()`. La expansión inline del bloque no debe depender de APIs de Livewire que no existan en la versión instalada (usar estado booleano local + `wire:click`, patrón ya usado en `ContributionReview::toggleEdit`).
4. **Fallo visible y claro en runtime**: si la consulta del detalle falla al expandir "Ver detalles" (C.3), el usuario ve un mensaje legible con cómo proceder — nunca "cargando…" infinito ni estado silencioso.
5. **Prueba contra el servicio real cuando esté disponible**: la metadata real la produce el servicio de clasificación extendido de QuBeKa. Mientras no exista, se prueba contra fixtures del contrato de §2.3 (Fase A) y **se declara explícitamente qué queda pendiente de validación real** (E.2).

### Checklist FA — Parsing del contrato de metadatos (servicio, mock primero)

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| FA.1 | Sesión con metadata completa por nodo | Http fake con fixture §2.3 | Campos normalizados (`tipo/confianza/reasons/alternatives/detected_patterns`) |
| FA.2 | Sesión sin campos de metadata (contrato viejo) | Http fake | Flag `sin_detalle`, sin excepción |
| FA.3 | Metadata parcial (razones vacías, sin alternativas) | Http fake | Defaults seguros, sin romper |
| FA.4 | 401 / 403 / 404 / 5xx / timeout del detalle | Http fake | Error legible con código (patrón existente del servicio) |
| FA.5 | Sesión con varios nodos, cada uno con su metadata | Http fake | Explicación por nodo, no mezclada |

### Checklist FB — Traducción a lenguaje natural (presentador)

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| FB.1 | Frase principal por cada tipo (Q, SQ, H, N-K, N-A) | Test unitario | Copy correcto por tipo, sin jerga QBK |
| FB.2 | Alternativa considerada con motivo de descarte | Test unitario | "También se evaluó como… pero se descartó porque…" |
| FB.3 | Semáforo por rango: 0.9 / 0.65 / 0.4 | Test unitario | verde / amarillo / rojo según §2.4 |
| FB.4 | Metadata ausente | Test unitario | Copy honesto de `sin_detalle` |
| FB.5 | Consistencia con las reglas de QuBeKa | Revisión del contrato B.2 | Kuestion no contradice al clasificador |

### Checklist FC — Confirmación inmediata del "Aportar" (UI + integración)

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| FC.1 | Aportar → ver "Ver detalles de la clasificación" bajo el resumen | Navegador real | Enlace visible y distinguible |
| FC.2 | Expandir → bloque con explicación, confianza y alternativas | Navegador real | Texto legible, semáforo con su color, sin navegar a otra pantalla |
| FC.3 | Expandir con sesión sin metadata | Navegador real | Estado `sin_detalle` honesto |
| FC.4 | QuBeKa caído al expandir | Navegador real / mock | Error legible + reintentar, no "cargando…" infinito |
| FC.5 | Rebuild de assets + clases en bundle | `npm run build` + grep | Clases del semáforo presentes en el CSS servido |

### Checklist FD — Revisión de la Ola 1 y bandeja (UI; bandeja condicional)

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| FD.1 | En la revisión simple (`ContributionReview`): "¿Por qué?" por nodo, colapsado por defecto | Navegador real | Expande la explicación de ese nodo; los demás quedan colapsados |
| FD.2 | Aprobar/rechazar siguen funcionando con el bloque presente | Navegador real (QuBeKa real) | Flujo de revisión intacto (regresión E.1) |
| FD.3 | Bandeja del Punto 1: "¿Por qué?" por ítem | Cuando el Punto 1 exista | Mismo componente reutilizado, sin duplicación |
| FD.4 | Historial del Punto 1: explicación disponible en procesados | Cuando el Punto 1 exista | Mismo componente |
| FD.5 | Rebuild + visual | Navegador real | Clases en bundle, legible |

### Matriz mock vs real

| Fase | Con mock (siempre) | Contra servicio real (cuando esté disponible) |
|---|---|---|
| A — Parsing | Http fake del contrato §2.3 | **Requiere QuBeKa con metadatos desplegados** (no existe hoy) |
| B — Presentador | Fixtures de metadata | Revisión del copy contra razones reales de QuBeKa |
| C — Confirmación | Servicio simulado al expandir | Aportar real → expandir con metadata real |
| D — Revisión Ola 1 / bandeja | Componente con fixtures | Revisión de una sesión real; bandeja cuando exista el Punto 1 |
| E — Cierre | Suite completa | E2E real documentado (E.2) |

**Regla de cierre:** ninguna fase se declara cerrada sin completar los ítems obligatorios de esta sección. Si alguno no se pudo hacer (p. ej. validación real porque QuBeKa aún no produce metadatos, o la bandeja porque el Punto 1 no está implementado), se declara explícitamente como pendiente con su motivo.

---

## 4. DUDAS Y BLOQUEOS

### Bloqueantes

| # | Pregunta | Para quién |
|---|---|---|
| B1 | **Viabilidad en QuBeKa**: el documento de entrada (§0, §8) y su propia "Aclaración importante" establecen que producir razonamiento estructurado (`reasons`, `alternatives_considered`, `detected_patterns`, confianza desglosada) es una **capacidad nueva** del servicio de clasificación, no una extensión simple. ¿Puede el servicio actual (con el mismo mecanismo de IA) producir estos metadatos, y cuándo estará disponible? **Es el paso 1 del punto (§8): sin esta confirmación no se puede dimensionar el cierre.** | QuBeKa |
| B2 | **Contrato exacto de los metadatos**: ¿viajan por nodo o por sesión? ¿En qué endpoints (`GET /sesiones-analisis/{id}`, `POST /contribute`, listado del Punto 1)? ¿Nombres de campo en snake_case? ¿Qué pasa con el `justificacion`/`justificacion_ia` y `confianza` que QuBeKa ya almacena por `NodoAnalisis` (su respuesta B1 de la Ola 1, Punto 4): se conservan, se renombran o los sustituye `reasons`? Kuestion hoy intenta leer `justificacion` por nodo en `ContributionReview` sin que el contrato confirmado lo incluya — hay que cerrarlo para no duplicar mapeos. | QuBeKa |
| B3 | **La decisión de vinculación a una Q existente** (el documento §1 pregunta "por qué se vinculó el aporte a una Q existente en lugar de crear una nueva") no tiene campo definido en los metadatos de §2.3. ¿QuBeKa lo cubre (p. ej. una razón a nivel de sesión) o queda fuera de esta versión? | QuBeKa (y producto si es decisión de alcance) |

### No bloqueantes

| # | Pregunta | Supuesto / estado | Para quién |
|---|---|---|---|
| D1 | ¿La metadata es por nodo o por sesión? | Se asume **por nodo propuesto** (los ejemplos del documento clasifican un texto → un tipo, y una sesión puede proponer varios nodos con tipos distintos; §4.1/4.3 muestran la explicación por decisión). Se ajusta al confirmar B2 (costo bajo: es mapeo). | Interno |
| D2 | ¿De dónde sale la metadata en la confirmación inmediata del "Aportar"? | Supuesto: **consulta a `getSession()` al expandir** "Ver detalles" (la `session_id` ya queda en el draft; los metadatos están asociados a la sesión en QuBeKa, §5). Alternativa: que `POST /contribute` la devuelva inline — mejor si QuBeKa la incluye sin costo, pero no se depende de eso. | Interno / QuBeKa |
| D3 | Sesiones sin metadata (creadas antes del despliegue de la extensión) | Mostrar estado honesto `sin_detalle` ("Este aporte no tiene detalle de clasificación disponible"); **nunca** generar una explicación inventada del lado de Kuestion. | Interno |
| D4 | Umbrales del semáforo de confianza | Los fija §2.4: verde >80%, amarillo 50–80%, rojo <50%. Sin margen de decisión. | — |
| D5 | Copy de advertencia por confianza baja / posible tipo esperado distinto (§2.4) | Se incluye en las plantillas (B.1) usando solo los `detected_patterns`/`reasons` que QuBeKa envíe. | Interno |
| D6 | ¿Explicación en el feed de vigilancia? | Supuesto alineado con la decisión abierta N°2 del documento: **no**; en el feed solo el indicador de vigencia del Punto 3. Confirmar con producto antes de cerrar la Fase D si alguien la pide. | Producto |
| D7 | El usuario no entiende la explicación con confianza muy baja | La decisión abierta N°4 del documento ya lo resuelve: el flujo "Ajustar" (Punto 1) permite corregir manualmente. No se agrega UX nueva. | — |
| D8 | i18n | Solo español (§5). | — |

---

## 5. ESFUERZO ESTIMADO

| Fase | Esfuerzo estimado | Incertidumbre |
|---|---|---|
| **Fase A** — Contrato + parsing | S (0.5–1 d) | **Media-alta por dependencia externa**: el parsing con mock es barato, pero el cierre depende de la confirmación de viabilidad y contrato de QuBeKa (B1/B2) — que el propio documento señala como paso previo indispensable (§8). |
| **Fase B** — Traducción a plantillas | M (1–1.5 d) | Media: es donde vive el copy (definición de frases por tipo, alternativas y advertencias); requiere revisión de UX y de consistencia con las reglas de QuBeKa. |
| **Fase C** — Componente + confirmación inmediata | M (1–1.5 d) | Media: UI nueva (expansión inline, semáforo) + fallo visible al expandir + assets/navegador. La lógica es simple, lo que pesa es el pulido y la verificación visual. |
| **Fase D** — Revisión Ola 1 + bandeja | S–M (0.5–1.5 d) | Media por dependencia: la parte de la Ola 1 es barata (reutiliza C.1); la bandeja/historial no se puede cerrar hasta que el Punto 1 exista. |
| **Fase E** — QA y cierre | S (0.5–1 d) | Baja-media: la validación real (E.2) depende de QuBeKa desplegando la extensión. |
| **TOTAL** | **S–M (3.5–6.5 d)** | La mayor incertidumbre es la **Fase A/bloqueo B1** (viabilidad de QuBeKa), que el documento declara como prerequisito del punto. El resto es UI de bajo riesgo sobre patrones ya existentes. |

---

## 6. FUERA DE ALCANCE

| Elemento | Por qué queda fuera |
|---|---|
| Generar los metadatos de explicabilidad (razones, alternativas, patrones, confianza desglosada) | Es trabajo de QuBeKa (§6 del documento de entrada); su extensión del servicio de clasificación es capacidad nueva de ese equipo. |
| Almacenar los metadatos en el sandbox asociados a la sesión | QuBeKa (§5, §6-3). |
| Definir las reglas/patrones de clasificación que producen las razones | QuBeKa (§6-5); Kuestion solo referencia lo que recibe. |
| Resaltado de palabras clave en el texto original del aporte | Decisión abierta N°1 del documento ("opcional en una primera versión") → no entra en esta versión. |
| Enlace a documentación del método QBK | Decisión abierta N°3 (ola posterior). |
| Explicación en el feed de vigilancia | Decisión abierta N°2: el feed lleva solo el indicador de vigencia (Punto 3). |
| Internacionalización | Solo español (§5). |
| Generar explicación con LLM en tiempo real al hacer clic en "¿Por qué?" | §5 del documento: los metadatos se generan en la clasificación y solo se muestran; Kuestion no llama a ningún LLM. |
| Bandeja de revisión del Punto 1 | Es el plan del Punto 1 (Ola 2); este punto solo reutiliza su superficie cuando exista (Fase D.2 condicional). |

---

## 7. Entregables comprometidos contra el contrato

Los siguientes entregables de esta fase comprometen el contrato `docs/CONTRATO_API_OLA2.md`:

| # | Entregable | Sección del contrato | Compromiso |
|---|---|---|---|
| 1 | Metadatos por nodo: `decision_type`, `confidence`, `reasons[]`, `alternatives_considered[]`, `detected_patterns[]` | §7.1 | Parsing en `QbkContributionService`; persistidos en `NodoAnalisis.datos_especificos.explicacion`. |
| 2 | Exposición en `GET /sesiones-analisis/{id}` (detalle) | §7.2 | Mínimo indispensable para C3. |
| 3 | Exposición en `GET /sesiones-analisis` (listado) | §7.2 | Si Punto 1 aterriza en esta ola; si no, diferido con motivo. |
| 4 | Exposición en `POST /contribute` | §7.2 | Análisis síncrono: metadatos disponibles en respuesta sin segundo llamado. |
| 5 | Degradación `sin_detalle` para sesiones previas al despliegue | §7.1 (viven solo mientras pendiente) | UI honesta, nunca inventa explicación. |

---

## 8. Trazabilidad de referencias cruzadas (Ola 1)

- Este plan no reabre el contrato de la Ola 1. Referencia conceptual `ola1-preguntas-abiertas.md` solo como registro histórico de la Ola 1. El campo `justificacion`/`justificacion_ia` presente en `NodoAnalisis` desde Ola 1 queda documentado en el contrato como dato existente; Kuestion lo consume solo si QuBeKa lo incluye explícitamente (ver duda B2 del plan).
