# Advertencia de consecuencias al aprobar subconjunto — Definición fina

*Ola 3, Punto 1.1 · Documento de definición*
*Septiembre 2026 · Documento de entrada para los equipos de QuBeKa y Kuestion*

---

## 0. Contexto y origen

Este documento define un desarrollo posterior al cierre del Punto 1 de la Ola 3, que surge como consecuencia directa del review independiente de ese punto.

**Origen del hallazgo:** el review independiente del Punto 1 (auditoría del commit de cierre, evidencia `git diff` + suite reproducida) identificó que la aprobación por subconjunto puede tener consecuencias estructurales sobre el grafo que el usuario no ve al momento de aprobar: nodos que quedan huérfanos, enlaces que se pierden, y sugerencias intra-sesión que no se resuelven. QuBeKa, en su respuesta al review, resolvió el lado backend (los datos necesarios ya se exponen) y delegó a Kuestion la advertencia en UI. Esa delegación no se había comunicado al equipo de Kuestion, lo que generó un vacío de coordinación.

**Naturaleza de este documento:** es una definición fina, independiente, con el mismo formato que los puntos de Ola 1, Ola 2 y Ola 3. No modifica `OLA_3_Punto_1.md` ni el plan maestro de la Ola 3. No se hace corrección retroactiva de documentos cerrados. Este punto se desarrolla con el mismo framework que cualquier otro punto: definición → planes por equipo → implementación → review → cierre.

**Documentos de referencia (no se reabren, se citan):**
- Review independiente del Punto 1 de la Ola 3 (donde se identificó el hallazgo).
- `OLA_3_Punto_1.md` (definición fina del Punto 1, la aprobación por subconjunto).
- `Ecosistema_Cambios_Arquitectonicos_Post_Ola2.md` (sección 1: la lógica de estructura del grafo vive en QBK, no se reconstruye en Kuestion).

**Prioridad:** se implementa antes de comenzar la Ola 3, Punto 3. No es deuda diferida.

---

## 1. Decisión de producto

### 1.1 Qué se advierte

Se advierte al usuario sobre **tres casos**, todos de la misma familia: consecuencias estructurales de aprobar un subconjunto de nodos propuestos.

| Caso | Descripción | Ejemplo |
|---|---|---|
| **Nodo huérfano** | Se aprueba un nodo cuyo padre (Q/SQ) no fue aprobado. Se promueve como raíz del grafo. | Se aprueba una H pero no su Q padre. |
| **Enlace perdido** | Se aprueba un nodo que tenía enlace dentro de la sesión con otro nodo no aprobado. El enlace no se recrea. | Se aprueba A, no se aprueba B, el enlace A→B desaparece. |
| **Sugerencia intra-sesión no resuelta** | El sistema detectó que dos nodos de la misma sesión son similares (`id_nodo_sesion_match`) y el usuario aprueba ambos sin vincularlos. | Dos H casi idénticas, ambas aprobadas como nodos distintos. |

### 1.2 Cuándo se advierte

**Al aprobar.** El usuario arma su selección en la bandeja de revisión, hace clic en "Aprobar seleccionados", y en ese momento el sistema evalúa la selección específica y muestra las advertencias correspondientes antes de ejecutar la promoción.

**No se advierte mientras se selecciona.** No hay recálculo en vivo por cada clic de checkbox. La razón es doble: evita una llamada al backend por clic (costo y latencia) y evita que Kuestion tenga que razonar sobre la estructura del grafo (principio arquitectónico ya cerrado en `Ecosistema_Cambios_Arquitectonicos_Post_Ola2.md`, sección 1).

### 1.3 La advertencia informa, no bloquea

El usuario puede aprobar igual después de ver la advertencia. El grafo de QBK admite raíces múltiples por diseño, y puede haber razones legítimas para aprobar un nodo sin su padre. Bloquear contradiría el principio general del sistema: el usuario decide.

### 1.4 Dónde vive la lógica

**En QuBeKa.** La lógica de estructura del grafo (qué es un padre, qué es un enlace, qué significa que algo quede huérfano) no se reconstruye en Kuestion. Es el mismo principio ya aplicado a la trazabilidad de origen (documento `Ecosistema_Cambios_Arquitectonicos_Post_Ola2.md`, sección 1).

**Mecanismo propuesto:** QuBeKa expone un endpoint de evaluación de subconjunto — recibe un subconjunto candidato de nodos y devuelve qué consecuencias estructurales tendría aprobarlo. Kuestion lo llama una sola vez, al momento de aprobar, y muestra el resultado antes de confirmar la promoción.

**Alternativa a evaluar con QuBeKa:** si el cálculo es suficientemente liviano, puede exponerse como campo calculado del detalle de sesión con el subconjunto como parámetro, en vez de endpoint dedicado. La decisión de mecanismo concreto queda a criterio técnico de QuBeKa; lo cerrado por producto es dónde vive la lógica (QuBeKa) y cuándo se ejecuta (al aprobar).

### 1.5 Experiencia de usuario

**Flujo normal (sin consecuencias):**
1. El usuario arma su selección.
2. Hace clic en "Aprobar seleccionados".
3. El sistema evalúa la selección, no encuentra consecuencias.
4. La promoción se ejecuta directamente. No hay paso intermedio.

**Flujo con consecuencias:**
1. El usuario arma su selección.
2. Hace clic en "Aprobar seleccionados".
3. El sistema evalúa la selección, encuentra consecuencias.
4. Se muestran las advertencias correspondientes antes de confirmar.
5. El usuario puede **confirmar la aprobación** (sabiendo las consecuencias) o **volver a la selección** para ajustarla.
6. Si confirma, la promoción se ejecuta. Si vuelve, no se promueve nada y el usuario sigue en la bandeja con su selección intacta.

**Copy y tono:** informativo, no alarmante. No es un error, es un aviso de consecuencia estructural. El detalle visual queda a criterio del equipo de Kuestion, respetando el patrón ya usado en Ola 2 (indicadores de vigencia) y en el Punto 1 (advertencias de contradicción ámbar, no bloqueantes).

---

## 2. Qué NO se construye

- **No se recalcula mientras el usuario deselecciona.** Solo al aprobar.
- **No se bloquea la aprobación.** El usuario decide si continuar después de ver la advertencia.
- **No se construye lógica de estructura del grafo en Kuestion.** El cálculo vive en QuBeKa.
- **No se incluyen casos fuera de los tres definidos** (huérfanos, enlaces perdidos, sugerencias intra-sesión no resueltas). Si aparece un cuarto caso en uso real, se evalúa en una ola posterior.
- **No se modifica el flujo de aprobación existente** más allá de agregar el paso de evaluación cuando corresponde. La aprobación sin subconjunto (aprobar todo) no cambia.

---

## 3. Qué se le pide a cada equipo

### A QuBeKa

1. **Confirmar viabilidad técnica del mecanismo de evaluación de subconjunto.** ¿El cálculo de consecuencias de un subconjunto candidato es viable con el modelo de datos actual? ¿Es suficientemente liviano para exponerlo como endpoint síncrono, o requiere algún ajuste?
2. **Decidir el mecanismo concreto:** endpoint dedicado (`POST /sesiones-analisis/{id}/evaluar-subconjunto`, o equivalente) o campo calculado con parámetro. La decisión queda a criterio técnico, con la restricción de que el cálculo no se distribuya a Kuestion.
3. **Diseñar el contrato del mecanismo.** Input: subconjunto candidato (IDs de nodos de la sesión). Output: lista de consecuencias estructuradas (tipo de advertencia, nodos afectados, descripción legible).
4. **Confirmar que los datos que ya expone** (relaciones por nodo, `id_nodo_sesion_match`, `id_nodo_real_match`) son suficientes para que el cálculo funcione, o identificar qué falta.
5. **Documentar el contrato en la versión correspondiente** (`CONTRATO_API_REVISION.md`), con ejemplos concretos de payload de entrada y salida para los tres tipos de consecuencia.
6. **Tests que cubran:** subconjunto sin consecuencias (camino feliz), subconjunto con cada uno de los tres tipos de consecuencia, y caso combinado (múltiples consecuencias en una misma evaluación).

### A Kuestion

1. **Esperar la confirmación de QuBeKa sobre el contrato** antes de empezar a construir la UI de la advertencia. No asumir forma del payload.
2. **Implementar el paso de evaluación en el flujo de aprobación:**
   - El usuario arma su selección y hace clic en "Aprobar seleccionados".
   - Se ejecuta la evaluación contra QuBeKa.
   - Si no hay consecuencias, la promoción fluye directo.
   - Si hay consecuencias, se muestran las advertencias antes de confirmar.
3. **Diseñar la interacción del paso de advertencia** según la sección 1.5 (flujo con consecuencias).
4. **Definir el copy y el diseño visual.** Informativo, no alarmante. Consistente con los patrones de advertencia ya usados en la plataforma.
5. **Definir el comportamiento cuando el usuario vuelve a la selección** después de ver la advertencia: la selección debe conservarse intacta, sin resetear.
6. **Cubrir con tests:** aprobación sin consecuencias, aprobación con consecuencias (con confirmación), aprobación con consecuencias (con cancelación y vuelta a la selección).

---

## 4. Puntos a resolver por los equipos antes de empezar

Estos tres puntos no son decisiones de producto ya cerradas. Son cuestiones operativas y de UX que los equipos deben resolver antes de generar sus planes de implementación, para que no queden implícitas y cada uno asuma algo distinto.

### 4.1 Comportamiento ante fallo de la evaluación

**Responsable de proponer:** Kuestion.
**Decisión final:** producto (a confirmar con la recomendación del equipo).

**Qué pasa si la evaluación de subconjunto no se puede ejecutar** (QuBeKa caído, timeout, error 500). Las opciones típicas son:
- Bloquear la aprobación hasta que la evaluación funcione.
- Proceder sin advertencia, asumiendo el riesgo silenciosamente.
- Mostrar un aviso intermedio: "no pudimos verificar consecuencias, ¿querés continuar igual?".

**Recomendación de producto (a validar por Kuestion):** no bloquear. El sistema tiene que seguir siendo usable si una pieza falla. Pero mostrar el aviso intermedio, para que el usuario sepa que no se pudo verificar. Kuestion debe confirmar si esta es la opción viable desde la UX y proponer alternativas si no.

### 4.2 Forma del paso intermedio

**Responsable de proponer:** Kuestion.
**Decisión final:** Kuestion, dentro del marco de la sección 1.5.

**Cómo se muestra el paso de advertencia** cuando hay consecuencias. Las opciones típicas son:
- Modal con las advertencias y botones "Confirmar de todas formas" / "Volver a la selección".
- Panel expandido dentro de la misma bandeja, con las advertencias y los mismos dos botones.
- Página intermedia dedicada, con más espacio para el detalle.

**Recomendación de producto (a validar por Kuestion):** modal o panel expandido, no página intermedia. La página intermedia rompe el flujo y agrega fricción innecesaria para un paso que es consultivo, no transaccional. Kuestion debe decidir cuál de las dos opciones prefiere y definir el copy de los botones ("Confirmar de todas formas" sugiere que la advertencia es un obstáculo; "Confirmar aprobación" puede ser más neutro; es decisión de UX).

### 4.3 Ronda de coordinación conjunta antes de empezar

**Responsable:** ambos equipos.

Antes de que ninguno de los dos empiece a construir, conviene una ronda corta de coordinación conjunta para cerrar:
- El mecanismo concreto que QuBeKa va a implementar (endpoint o campo calculado).
- La estructura exacta del payload de consecuencias.
- El timing: quién entrega primero y qué puede empezar en paralelo.

Esta ronda no bloquea la generación de los planes de implementación, pero sí conviene que se realice **antes** de la primera línea de código, para evitar retrabajo como el que ya se evitó en puntos anteriores con la misma práctica.

---

## 5. Criterio de cierre

Este punto se cierra cuando:

1. QuBeKa tiene el mecanismo de evaluación de subconjunto operativo y documentado en el contrato.
2. Kuestion tiene la UI de advertencia implementada en la bandeja de revisión.
3. Los tres casos (huérfanos, enlaces perdidos, sugerencias intra-sesión) están cubiertos y probados end-to-end.
4. Hay test de regresión que verifica que la aprobación sin consecuencias sigue funcionando sin paso intermedio.
5. El flujo de cancelación después de la advertencia está probado: el usuario vuelve a la selección sin perder su estado.
6. Los tres puntos de la sección 4 están resueltos y documentados (no necesariamente acá; pueden quedar en los planes de implementación de cada equipo, con la decisión explícita).

---

## 6. Decisiones abiertas

| # | Decisión | Propuesta a evaluar |
|---|---|---|
| 1 | **Mecanismo concreto en QuBeKa:** endpoint dedicado o campo calculado con parámetro. | A criterio técnico de QuBeKa. Lo cerrado por producto es dónde vive la lógica y cuándo se ejecuta. |
| 2 | **Estructura exacta del payload de consecuencias.** | Propuesta tentativa: `{tipo: "nodo_huerfano"|"enlace_perdido"|"sugerencia_no_resuelta", nodos_afectados: [...], descripcion: "..."}`. Confirmar con QuBeKa contra código real. |
| 3 | **Comportamiento ante fallo de la evaluación** (sección 4.1). | Kuestion propone, producto confirma. |
| 4 | **Forma del paso intermedio** (sección 4.2). | Kuestion decide dentro del marco de la sección 1.5. |
| 5 | **Comportamiento de la promoción si el usuario confirma con consecuencias.** | El nodo huérfano se promueve como raíz (comportamiento ya definido por QuBeKa). Los enlaces con extremos descartados no se recrean. Las sugerencias no resueltas quedan como nodos separados. Todo esto ya es comportamiento actual; la advertencia solo lo hace visible. |

---

*Documento de definición fina del Punto 1.1 de la Ola 3. Independiente del Punto 1 original. No modifica documentos previos. Se desarrolla con el mismo framework que cualquier otro punto: definición → planes por equipo → implementación → review → cierre. Se implementa antes de comenzar el Punto 3 de la Ola 3.*