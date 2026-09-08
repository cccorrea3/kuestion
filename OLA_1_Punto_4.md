# Gate humano: revisión de aportes desde Kuestion

*Especificación de trabajo — Ola 1, punto 4*
*Agosto 2026 · Documento de entrada para los equipos de QuBeKa y Kuestion*

---

## 0. Qué resuelve este documento

El punto 3 ya definió que un "Aportar" en Kuestion termina como una sesión de análisis en el sandbox de QuBeKa, con nodos propuestos en espera de confirmación humana — nunca toca el grafo activo por sí sola. Este documento define **quién confirma, dónde vive esa experiencia, y qué pasa exactamente cuando se aprueba o se rechaza**.

Este es el punto donde se decide si el principio "ninguna IA decide qué conocimiento es válido" se cumple de una forma que se siente liviana o de una forma que se siente como trámite — es, en ese sentido, tan importante para la experiencia general como el punto 1 o el punto 3.

---

## 1. La pregunta central: ¿quién puede aprobar un aporte?

Esta es la decisión más importante de todo el documento, y no es solo técnica — es una decisión de producto que conviene tomar con cuidado antes de especificar cualquier pantalla.

### 1.1 Por qué esto es distinto al gate clásico de QBK

El gate humano que ya existe en QBK fue pensado para un escenario específico: un **agente de IA** propone contenido nuevo, y un **humano distinto** (revisor) confirma si es válido. Tiene sentido exigir un tercero ahí, porque el agente no tiene forma de saber si lo que generó es correcto — necesita un chequeo externo.

El caso de "Aportar" es distinto en un punto clave: **el texto original lo escribió un humano**, no lo inventó la IA. Lo que la IA hizo fue clasificar ese texto en la estructura QBK (a qué H corresponde, si es una N-K nueva, etc.). El riesgo que hay que cubrir acá no es "¿esto es verdad?" — eso ya lo garantiza la persona que lo escribió, con su propio conocimiento del tema — es **"¿la IA entendió y clasificó bien lo que la persona quiso decir?"**. Es un chequeo de fidelidad de traducción, no un chequeo de veracidad de contenido.

### 1.2 Por qué esto importa para el caso de uso de persona individual

Si el gate de "Aportar" exigiera siempre un revisor distinto al autor, **cualquier usuario individual sin equipo queda bloqueado** — no tiene a nadie más a quien pedirle que apruebe. Dado que QuBeKa y Kuestion están pensados explícitamente para servir tanto a personas solas como a empresas, este punto no es un detalle técnico: si se decide mal, el producto deja de funcionar para todo el segmento de usuario individual, que es justamente el que más valor le da a la fricción baja de "Aportar".

### 1.3 Decisión: autoconfirmación aceptada

**Cerrado.** El gate de "Aportar" acepta autoconfirmación del mismo autor como cumplimiento válido del principio "ningún contenido entra sin que un humano lo confirme explícitamente" — la persona no está certificando que algo desconocido es cierto, está confirmando que el sistema entendió bien lo que ella misma ya sabía y escribió. Esto sigue siendo un humano deteniendo a la IA antes de que algo entre al grafo activo; ese humano puede ser el mismo que aportó el texto.

**Razón de negocio, no solo conceptual:** si el gate exigiera siempre un revisor distinto al autor, cualquier usuario individual sin equipo queda bloqueado desde el primer uso — un flujo que no puede completar una persona sola es un flujo muerto para todo el segmento de usuario individual, que es justamente el que más valor le da a la fricción baja de "Aportar".

Cuando el aporte ocurre dentro de un workspace de equipo y existe un rol de revisor definido, se puede ofrecer la opción de que otro miembro confirme en su lugar — como posibilidad adicional, nunca como requisito bloqueante para el caso individual.

---

## 2. Dónde vive la experiencia de revisión

### 2.1 Las dos superficies posibles

QuBeKa ya tiene una pantalla de "Revisión Humana" como parte del flujo de cinco pasos de ingestión (distinta del `ValidationDashboard` clásico que revisa N-K sueltas creadas por agentes vía API — esa es una pantalla más general, pensada para volúmenes mayores). La pantalla de Revisión Humana del flujo de ingestión ya sabe mostrar una sesión con sus nodos propuestos, porque es la misma pieza que se usa para revisar una carga de documento completa.

**Opción A — Kuestion redirige a esa pantalla ya existente en QBK.** No requiere construir nada nuevo del lado de la revisión en sí, solo un enlace directo a la sesión correspondiente. El costo: la persona sale de Kuestion, rompe la sensación de "todo pasa en un solo lugar".

**Opción B — Kuestion construye su propia superficie de confirmación**, liviana, específicamente para el caso común de este flujo: una sesión chica (1-2 nodos, según ya establecimos en el punto 3), llamando a la misma API de promoción que usa la pantalla de QBK por debajo. No duplica el `ValidationDashboard` completo — solo cubre el caso simple.

### 2.2 Recomendación

Un camino intermedio, no forzar una sola opción para todos los casos:

- **Si la sesión es simple** (1-2 nodos propuestos, sin conflictos ni ambigüedad detectada por el análisis) → Kuestion muestra una confirmación liviana propia, con el contenido propuesto visible en texto simple ("Vamos a guardar esto como una hipótesis: *[texto]*, con esta nota como evidencia: *[texto]*. ¿Confirmás?") y un botón de aprobar/ajustar/descartar, sin salir de Kuestion.
- **Si la sesión es más compleja** (varios nodos, o el análisis marcó algo como ambiguo) → Kuestion redirige a la pantalla de Revisión Humana de QBK, porque ahí ya existe la herramienta adecuada para ese nivel de detalle, y construir una versión reducida de eso en Kuestion sería duplicar trabajo sin necesidad.

Esto evita construir dos veces la misma pantalla compleja, pero mantiene la experiencia liviana en el caso más frecuente esperado (aportes cortos y puntuales, que es el uso típico de este flujo).

---

## 3. Qué ve la persona en la confirmación (a diferencia de "Aportar")

Importante: en el punto 3 definimos que, al aportar, Kuestion **no** muestra la estructura Q/SQ/H/N-K — solo confirma que quedó pendiente. Acá es distinto: en el momento de revisar, la persona **sí** necesita ver qué se propone, porque de eso se trata el gate — no puede aprobar algo que no ve. La simplicidad de "Aportar" y la transparencia de "Revisar" no son la misma cosa, y no hay que confundirlas: se esconde la complejidad en el momento de escribir, no en el momento de confirmar.

En la confirmación liviana (sección 2.2), el contenido propuesto se muestra en lenguaje natural, no con etiquetas técnicas (mostrar el texto de la hipótesis, no la palabra "H:"), pero la persona ve exactamente qué va a quedar guardado.

---

## 4. Qué pasa al aprobar o rechazar

### 4.1 Al aprobar

Los nodos de la sesión se promueven del sandbox al grafo activo, con IDs nuevos asignados vía `contadores_id` (mecanismo ya definido como excepción del sandbox), naciendo directamente como `validada` — sin pasar por el ciclo clásico de `pendiente_revision` en el grafo activo, porque la revisión ya ocurrió acá.

### 4.2 Al rechazar

Ya existe una pregunta abierta del lado de QuBeKa, anotada previamente y no resuelta todavía, sobre la distinción entre **borrado del sandbox por rechazo** vs. **retención cuando la promoción falla por otro motivo** (parte de la propuesta de retención de 7 días). Este documento no la resuelve — la señala como dependencia: el comportamiento de "Aportar → rechazado" hereda lo que se decida ahí, no hace falta duplicar esa decisión acá.

### 4.3 Al ajustar (no aprobar tal cual, pero tampoco rechazar del todo)

En la confirmación liviana (sección 2.2), si la persona quiere corregir algo menor (por ejemplo, el texto de la N-K quedó mal recortado), lo más simple es permitir edición de texto directa antes de confirmar, sin volver a pasar por clasificación. Si el ajuste que la persona quiere hacer es estructural (cambiar de tipo de nodo, reasignar a otra H), eso excede la confirmación liviana y debería llevar a la Opción A (pantalla completa de QBK).

---

## 5. Notificación de que hay algo pendiente

QBK ya tiene centro de notificaciones propio (campana, dropdown, digest por email) que ya excluye al autor cuando el contenido lo generó un agente — pero acá el "autor" es la persona misma, así que esa exclusión no aplica de la misma forma.

**Para la autoconfirmación (sección 1.3), no hace falta notificación aparte** — la persona puede confirmar en el mismo momento, justo después de aportar, si la sesión es simple (sección 2.2). La notificación solo importa cuando: (a) la sesión es compleja y quedó para más tarde, o (b) el workspace es de equipo y se delega la revisión a otra persona. En ambos casos, el mecanismo de notificación ya existente en QBK alcanza sin trabajo adicional — la pregunta a resolver es si Kuestion también debería mostrar un indicador propio ("tenés 1 aporte pendiente de confirmar") para que la persona no tenga que enterarse solo por QBK, dado que Kuestion es donde vive la mayoría de su actividad.

---

## 6. Operacional

| Aspecto | Definición |
|---|---|
| **API de promoción** | Kuestion necesita un endpoint de QBK para aprobar/rechazar una sesión simple sin pasar por su UI — a confirmar si ya existe (dado que la pantalla de Revisión Humana ya construida debe estar llamando a algo equivalente por debajo) o si hace falta exponerlo. |
| **Permisos** | El endpoint de confirmación liviana debe validar que quien confirma tiene permiso sobre ese workspace — reutiliza el mismo mecanismo de autenticación ya definido en los puntos 1 y 3. |
| **Timeout de sesiones sin revisar** | A definir: si una sesión queda mucho tiempo sin confirmar, ¿se recuerda, se vence, se descarta? Depende de la política de retención ya mencionada en 4.2. |

---

## 7. Qué se le pide a cada equipo

### A QuBeKa

1. Confirmar si existe (o se puede exponer fácilmente) un endpoint de promoción/rechazo de sesión que Kuestion pueda llamar directamente, sin pasar por la UI completa de Revisión Humana.
2. Definir el criterio para distinguir una "sesión simple" de una "compleja" (sección 2.2) — probablemente ya existe alguna señal del análisis (cantidad de nodos propuestos, si hubo ambigüedad detectada) que se pueda reutilizar para esta clasificación.
3. Resolver la pregunta ya pendiente de rechazo vs. retención (sección 4.2), que ahora tiene una dependencia nueva esperándola.
4. Aportar su criterio sobre la decisión de autoconfirmación (sección 1.3, ya cerrada) — si hay alguna razón del lado del método QBK para no aceptarla que no se haya considerado acá, es el único punto donde cabría objeción; de lo contrario, se implementa tal como quedó definida.

### A Kuestion

1. Construir la pantalla de confirmación liviana (sección 2.2 y 3) para sesiones simples.
2. Construir el redireccionamiento a QBK para sesiones complejas.
3. Decidir si agrega un indicador propio de "pendiente de confirmar" (sección 5) o depende completamente de la notificación de QBK en esta primera versión.

---

## 8. Decisiones abiertas

- Si existe ya un endpoint de promoción liviano en QBK o hay que construirlo.
- Criterio exacto para distinguir sesión simple de compleja.
- Si Kuestion necesita indicador propio de pendientes o alcanza con las notificaciones de QBK en esta primera versión.

*(La autoconfirmación del autor, sección 1.3, queda cerrada — no es una decisión abierta.)*
