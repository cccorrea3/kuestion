# Indicador de vigencia visible en la respuesta misma

*Especificación de trabajo — Ola 2, punto 3*
*Agosto 2026 · Documento de entrada para los equipos de QuBeKa y Kuestion*

---

## 0. Qué resuelve este documento

Los puntos anteriores de la Ola 2 establecieron una bandeja de revisión rápida (punto 1) y un mecanismo de reconfirmación ligera (punto 2). Pero la reconfirmación solo tiene valor si el usuario **ve** el estado de vigencia en el momento de consumir la respuesta. Sin un indicador visible, el usuario no sabe si lo que está leyendo sigue siendo válido o necesita reconfirmación; la reconfirmación se convierte en una acción aislada, no en parte del flujo natural de consulta.

Este documento define el **Indicador de vigencia visible**: la representación visual y textual, dentro de la respuesta a una pregunta en Kuestion, que informa al usuario sobre el estado de vigencia del conocimiento que está consumiendo. No agrega una capacidad nueva al sistema, sino que **pule** la experiencia para que la vigencia sea transparente y accionable desde el mismo lugar donde el usuario obtiene respuestas.

---

## 1. El problema que resuelve (y por qué es necesario)

Hoy, en Kuestion, una respuesta puede provenir de Kuaforia (con sus señales internas de vigencia: `stale_case`, `low_confidence`, `deps_changed`) o de QuBeKa (con el nuevo campo `fecha_ultima_confirmacion` que se implementará en el punto 2). Pero en ambos casos, la respuesta se muestra sin ningún indicador de cuán vigente es ese conocimiento. El usuario recibe una respuesta y asume que es válida, sin saber si fue reconfirmada recientemente o si data de hace meses y nunca fue revisada.

El problema no es técnico (ya tenemos o tendremos los datos), es de **transparencia y confianza**. Un sistema de vigilancia que no muestra el estado de vigencia es un sistema que no comunica su valor. El indicador le da al usuario la información necesaria para decidir si confía en la respuesta, y también le ofrece una vía para actualizar la vigencia (a través del botón de reconfirmación definido en el punto 2). Además, el indicador actúa como un recordatorio suave para mantener el grafo actualizado, integrando la reconfirmación en el flujo natural de consumo, no como una tarea separada.

---

## 2. Alcance funcional — Indicador de vigencia

### 2.1 ¿Qué información muestra el indicador?

El indicador debe mostrar, al menos, la siguiente información, en lenguaje natural y con un diseño visual claro:

- **Última confirmación:** Fecha de la última reconfirmación humana (para contenido de QuBeKa) o la última vez que se evaluó la vigencia (para contenido de Kuaforia).
- **Estado de vigencia:** Un veredicto simple basado en el umbral de tiempo (por defecto 90 días, configurable a futuro) y las señales de la fuente:
  - **Vigente:** Si la última confirmación es reciente (menos de 90 días) y no hay señales de alerta.
  - **Pendiente de reconfirmación:** Si la última confirmación supera los 90 días.
  - **Posiblemente obsoleto:** Si hay señales de alerta (`stale_case`, `low_confidence`, o algún nodo dependiente cambió).
- **Un botón de acción:** Un enlace o botón "Reconfirmar" que dispara la acción definida en el punto 2, actualizando la vigencia y refrescando el indicador.

### 2.2 ¿Dónde aparece el indicador?

- **Dentro de la respuesta a una pregunta:** Justo después del texto de la respuesta, antes de las fuentes citadas, se muestra un bloque pequeño con el indicador y el botón de reconfirmación.
- **En el feed de vigilancia:** Cada ítem del feed que corresponda a una pregunta vigilada muestra un mini-indicador (solo el estado y la fecha, sin el botón a menos que se abra el detalle).
- **En la bandeja de revisión (punto 1):** Cuando un nodo pendiente de reconfirmar aparece en la bandeja, su estado de vigencia se muestra claramente, con el botón de reconfirmación integrado.

### 2.3 ¿Cómo se determina el estado de vigencia?

El estado de vigencia se calcula en base a la fuente de la respuesta y los datos disponibles:

- **Si la respuesta proviene de QuBeKa:**
  - Se usa `fecha_ultima_confirmacion` del nodo (o del conjunto de nodos que componen la respuesta).
  - Si la fecha es posterior a la fecha actual menos el umbral (90 días), se muestra "Vigente".
  - Si la fecha es anterior al umbral, se muestra "Pendiente de reconfirmación".
  - Si no existe `fecha_ultima_confirmacion` (por ejemplo, nodos que aún no se han reconfirmado nunca), se muestra "Sin reconfirmaciones registradas" y el botón para reconfirmar.
- **Si la respuesta proviene de Kuaforia:**
  - Se usan las señales internas que Kuaforia ya calcula: `stale_case`, `low_confidence`, `deps_changed`.
  - Si ninguna señal de alerta está activa y el caso está `active`, se muestra "Vigente según Kuaforia".
  - Si alguna señal está activa, se muestra "Posiblemente obsoleto" y se sugiere revisar el caso en Kuaforia.
  - Nota: Kuaforia no tiene un concepto de "reconfirmación humana" equivalente al de QuBeKa, por lo que el botón de reconfirmación, en este caso, podría redirigir a la UI de Kuaforia para marcar el caso como revisado (si Kuaforia expone esa acción), o simplemente no mostrarlo y sugerir al usuario que consulte la fuente.

### 2.4 Diseño visual y copy

- **Colores:** El indicador debe usar un sistema de colores semántico:
  - **Verde:** "Vigente" (confirmado recientemente, sin alertas, < 90 días).
  - **Amarillo:** "Pendiente de reconfirmación" (supera los 90 días, pero no hay alertas).
  - **Rojo:** "Posiblemente obsoleto" (alertas activas).
- **Texto:** El copy debe ser claro y no técnico:
  - *"Última confirmación: hace 2 días"* (verde).
  - *"Última confirmación: hace 95 días — [Reconfirmar]"* (amarillo).
  - *"Posiblemente obsoleto — [Revisar en Kuaforia]"* (rojo, solo para fuentes Kuaforia).
- **Tooltip:** Al pasar el cursor sobre el indicador, debe mostrarse un tooltip con más detalles: quién reconfirmó (si está disponible) y la fecha exacta.

### 2.5 ¿Qué NO hace este indicador en su primera versión?

- No muestra un historial completo de reconfirmaciones (solo la última).
- No permite cambiar el umbral de vigencia desde la propia respuesta (eso será parte de un punto posterior de la Ola 2, como "Explicabilidad" o ajustes de perfil).
- No integra la reconfirmación con el flujo de "Aportar" (reconfirmar no es lo mismo que aportar conocimiento nuevo).

---

## 3. Dependencias con la Ola 1 y otros puntos de la Ola 2

| Dependencia | Estado | Nota |
|---|---|---|
| **Campo `fecha_ultima_confirmacion` en QuBeKa** | Depende del punto 2 de la Ola 2 | Este campo es esencial para mostrar la vigencia de contenido de QuBeKa. Si QuBeKa no lo implementa, el indicador para QBK no puede funcionar. |
| **Endpoint de reconfirmación en QuBeKa** | Depende del punto 2 de la Ola 2 | Para el botón "Reconfirmar" en el indicador, se necesita el endpoint que actualiza la confirmación. |
| **Capacidad de Kuestion para leer `fecha_ultima_confirmacion` desde el contrato de QBK** | Depende de la Ola 1, punto 1 (Motor de Consulta) | La respuesta de QBK debe incluir `fecha_ultima_confirmacion` para que Kuestion pueda mostrarla. Esto debería estar cubierto por el contrato definido en el punto 1 de la Ola 1. |
| **Integración con señales de Kuaforia** | Depende de la Ola 1, punto 1 (conector a Kuaforia) | Para mostrar vigencia de respuestas de Kuaforia, Kuestion necesita leer las señales (`stale_case`, etc.) a través del `StructuredSignalProviderInterface`. Esto ya está diseñado, pero se debe verificar que Kuaforia devuelva esas señales en la respuesta. |
| **Umbral de 90 días** | Decisión ya cerrada (Resumen de Sesión, sección 7) | Se reutiliza el mismo umbral del punto 2. |

---

## 4. Experiencia de usuario detallada

### 4.1 Flujo principal: consulta y visualización de vigencia

1. El usuario escribe una pregunta en Kuestion: *"¿El endpoint de pagos sigue teniendo timeout?"*
2. El sistema busca en QuBeKa (o Kuaforia) y devuelve una respuesta.
3. Debajo de la respuesta, el usuario ve un bloque pequeño:
   - *"Última confirmación: hace 12 días — Vigente"* (verde, con un ícono de check).
4. El usuario puede hacer clic en el indicador para ver más detalles (tooltip) o hacer clic en el botón "Reconfirmar" si desea actualizar la fecha (aunque el sistema no se lo sugiera porque está vigente).

### 4.2 Flujo: respuesta con vigencia expirada (supera los 90 días)

1. El usuario hace una consulta y obtiene una respuesta.
2. El indicador muestra: *"Última confirmación: hace 95 días — Pendiente de reconfirmación [Reconfirmar]"* (amarillo).
3. El usuario hace clic en **Reconfirmar**.
4. El sistema llama al endpoint de QuBeKa, actualiza `fecha_ultima_confirmacion`, y el indicador cambia a *"¡Reconfirmado! Última confirmación: ahora"* (verde).

### 4.3 Flujo: respuesta de Kuaforia con señales de alerta

1. El usuario pregunta algo que Kuaforia responde con un caso que tiene `stale_case = true`.
2. El indicador muestra: *"Posiblemente obsoleto — [Revisar en Kuaforia]"* (rojo, con un enlace directo a Kuaforia).
3. El usuario hace clic en el enlace y es redirigido a Kuaforia para revisar el caso.
4. Al volver a Kuestion, si el caso fue actualizado en Kuaforia, la próxima consulta mostrará el nuevo estado.

### 4.4 Flujo: respuesta sin reconfirmaciones previas (nodo nuevo)

1. El usuario aportó conocimiento nuevo hace unos días, pero nunca lo reconfirmó.
2. El indicador muestra: *"Sin reconfirmaciones registradas — [Reconfirmar]"* (amarillo, sin fecha).
3. El usuario puede reconfirmar directamente, estableciendo la primera fecha de confirmación.

---

## 5. Operacional

| Aspecto | Definición |
|---|---|
| **Frecuencia de actualización del indicador** | El indicador se muestra en el momento de la consulta, basado en el estado actual de `fecha_ultima_confirmacion`. Después de una reconfirmación, se actualiza en la misma vista sin necesidad de recargar toda la página. |
| **Cache** | No se debe cachear el indicador por más de unos minutos, porque la vigencia puede cambiar con una reconfirmación o con una señal de Kuaforia que se actualice en segundo plano. |
| **Sincronía** | La reconfirmación desde el indicador debe ser síncrona (el usuario espera a que se confirme), para que el feedback sea inmediato y no quede una acción en el aire. |
| **Manejo de errores** | Si el endpoint de reconfirmación falla, se debe mostrar un error amigable y permitir reintentar. |
| **Permisos** | Solo el autor o un revisor del workspace puede reconfirmar. Si el usuario no tiene permisos, el botón debe estar deshabilitado o no mostrarse. |

---

## 6. Qué se le pide a cada equipo

### A QuBeKa

1. **Asegurar que el contrato de respuesta de QBK** incluya `fecha_ultima_confirmacion` y `ultimo_confirmador_nombre` para cada nodo que se devuelva en una consulta. Esto ya está en el punto 1 de la Ola 1 y en el punto 2 de la Ola 2, pero debe verificarse en la implementación.
2. **Proveer el endpoint de reconfirmación** definido en el punto 2 de la Ola 2, para que el botón del indicador pueda llamarlo.
3. **Proveer una señal o endpoint** que permita a Kuestion consultar el estado de vigencia de un conjunto de nodos (por si la respuesta agrupa varios nodos y se necesita un estado agregado). Esto es opcional para la Ola 2; por simplicidad, se puede mostrar el estado del nodo principal (ej. la N-K más relevante) o el más reciente.

### A Kuestion

1. **Leer `fecha_ultima_confirmacion` y `ultimo_confirmador_nombre`** desde la respuesta de QBK (a través de `QbkService`) y mostrar el indicador en la UI de respuesta.
2. **Leer las señales de Kuaforia** (`stale_case`, `low_confidence`, `deps_changed`) desde el `StructuredSignalProviderInterface` cuando la respuesta provenga de Kuaforia, y mostrarlas en el indicador con el copy adecuado.
3. **Construir el componente visual** del indicador: diseño, colores, tooltip, botón de reconfirmación.
4. **Conectar el botón de reconfirmación** al endpoint de QuBeKa, y manejar la respuesta exitosa/fallida actualizando la UI.
5. **Mostrar el indicador en el feed y en la bandeja de revisión** (según corresponda), adaptando el nivel de detalle (el feed puede mostrar solo un badge de color, mientras que la bandeja muestra el texto completo).

---

## 7. Decisiones abiertas

| Decisión | Propuesta a evaluar |
|---|---|
| ¿El indicador muestra la fecha exacta o solo el tiempo relativo (hace X días)? | Evaluar si mostrar ambos en el tooltip, pero el texto principal usar el tiempo relativo para no abrumar. |
| ¿El indicador debe incluir el nombre de quien reconfirmó? | Sí, en el tooltip, para dar trazabilidad. |
| ¿Para contenido de Kuaforia, se muestra un botón de reconfirmación que redirige a Kuaforia, o se mantiene solo el enlace? | Evaluar mantener solo el enlace a Kuaforia, porque la reconfirmación en Kuaforia es una acción distinta (marcar como revisado). Por simplicidad, no se integrará una reconfirmación desde Kuestion para Kuaforia en esta versión. |
| ¿Qué pasa si una respuesta combina nodos de QBK y Kuaforia? | Evaluar si se muestra un indicador por fuente, o un indicador agregado que priorice el estado más crítico. Por simplicidad, se sugiere mostrar el indicador de la fuente principal (la que generó la mayor parte de la respuesta). Esto se puede refinar en una ola posterior. |
| ¿El umbral de 90 días debe ser visible y ajustable desde el indicador? | No en esta versión. El ajuste del umbral será parte de un punto posterior de la Ola 2 ("Explicabilidad" o ajustes de perfil). Aquí solo se usa el valor por defecto ya cerrado. |

---

## 8. Nota sobre el orden de ejecución

Este punto depende directamente del punto 2 de la Ola 2 (reconfirmación) y de la Ola 1 (motor de consulta y contrato). Sin embargo, el diseño del indicador (colores, copy, posición en la UI) puede avanzar en paralelo mientras se implementan las dependencias, ya que es principalmente trabajo de diseño y frontend. A diferencia del punto 2, este punto es un **pulido de experiencia**: no requiere nuevas tablas ni endpoints en el backend, solo consumir los datos que el punto 2 hará disponibles. La integración con los endpoints de reconfirmación y la lectura de señales de vigencia son los únicos bloques técnicos que deben esperar a que esos endpoints existan.

Se recomienda que los equipos de diseño y frontend de Kuestion empiecen a prototipar el indicador tan pronto como sea posible, utilizando datos mock, para que cuando los endpoints estén listos, la integración sea rápida.
