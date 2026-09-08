# Explicabilidad de cada propuesta automática (por qué el sistema decidió que esto es H y no N-K)

*Especificación de trabajo — Ola 2, punto 4*
*Agosto 2026 · Documento de entrada para los equipos de QuBeKa y Kuestion*

---

## 0. Qué resuelve este documento

La Ola 1, punto 3, definió que Kuestion envía un texto al servicio de clasificación de QuBeKa, y que este devuelve una propuesta de estructura QBK (Q, SQ, H, N-K, N-A) sin que el usuario vea el detalle — solo un resumen ("Se propuso 1 hipótesis y 1 nota de conocimiento"). La Ola 2, punto 1, creó una bandeja de revisión donde el usuario puede aprobar o rechazar esa propuesta. Pero entre "ver el resumen" y "aprobar o rechazar" hay un espacio de confianza: el usuario no sabe **por qué** el sistema decidió que el texto era una H y no una N-K, o por qué vinculó un aporte a una Q existente en lugar de crear una nueva.

Sin explicabilidad, la revisión se convierte en un acto de fe: el usuario confía en que el sistema clasificó bien, o desconfía y rechaza sin entender. Ambos casos son malos: el primero porque valida errores silenciosos; el segundo porque descarta propuestas válidas por falta de transparencia.

Este documento define la **Explicabilidad de cada propuesta automática**: el mecanismo que muestra al usuario, en el momento de la revisión, el razonamiento del sistema detrás de cada decisión de clasificación, de modo que pueda tomar una decisión informada en segundos, sin tener que ser experto en el método QBK.

**Aclaración importante:** este punto implica una **capacidad nueva** en el servicio de clasificación de QuBeKa. Hoy, el servicio de ingestión no produce razonamiento estructurado (reasons, alternatives_considered, detected_patterns, confidence desglosado). Implementar esto requiere trabajo de ingeniería de prompt y/o lógica adicional específica para explicabilidad, no es una simple extensión del servicio existente. El documento refleja esta realidad en la sección 3 y en el checklist de QuBeKa.

---

## 1. El problema que resuelve (y por qué es necesario)

El flujo actual de "Aportar → clasificar → revisar" tiene un agujero de confianza. El usuario escribe un texto, el sistema devuelve un resumen ("se propuso 1 hipótesis"), y el usuario debe decidir si aprueba o rechaza. Pero el usuario no tiene forma de saber:

- Por qué el sistema interpretó el texto como una H en lugar de una N-K.
- Por qué el sistema vinculó el aporte a una Q existente en lugar de crear una nueva Q.
- Por qué el sistema no encontró relación con otros nodos que el usuario cree que están relacionados.
- Con qué nivel de confianza el sistema tomó cada decisión.

Sin esta información, el usuario está aprobando o rechazando una caja negra. Esto no es solo un problema de confianza; es un problema de **calidad del grafo**. Un usuario que aprueba sin entender puede estar incorporando clasificaciones erróneas que luego afectan la vigilancia. Un usuario que rechaza sin entender puede estar descartando conocimiento válido que luego no será vigilado.

La explicabilidad no es un "nice to have"; es una condición necesaria para que el flujo de revisión sea efectivo y para que el usuario pueda aprender a confiar (o desconfiar) del sistema con criterio.

---

## 2. Alcance funcional — Explicabilidad en la revisión

### 2.1 ¿Qué información se muestra al usuario?

En el momento de la revisión (ya sea en la bandeja de revisión rápida del punto 1, o en la confirmación inmediata posterior a un "Aportar"), el usuario debe ver, además del contenido propuesto y los botones de aprobar/rechazar/ajustar, un bloque de **explicación** que responda a estas preguntas:

| Pregunta | Qué información debe contener la respuesta |
|---|---|
| **¿Qué tipo de nodo se propuso y por qué?** | Una frase clara que explique la decisión de clasificación: *"Se clasificó como Hipótesis porque el texto describe una causa probable ('el batch bancario no llega'), en lugar de describir un procedimiento o un hecho confirmado."* |
| **¿Con qué nivel de confianza?** | Un porcentaje o indicador visual (ej. 85% de confianza) que muestre cuán seguro está el sistema de su decisión. Si la confianza es baja, el indicador debe ser más visible (amarillo/naranja). |
| **¿Qué señales o reglas usó el sistema para decidir?** | Una o dos frases que mencionen las reglas que el sistema aplicó: *"Se detectaron palabras clave de causa ('porque', 'falla') que sugieren una explicación, lo que se alinea con el patrón típico de una Hipótesis."* |
| **¿Qué alternativas se consideraron?** | Mencionar si el sistema consideró otro tipo de nodo y por qué lo descartó: *"También se evaluó clasificar esto como Nota de Conocimiento, pero se descartó porque el texto no describe un procedimiento ni un hecho confirmado, sino una causa aún no verificada."* |

### 2.2 ¿Dónde se muestra la explicación?

- **En la bandeja de revisión (punto 1):** Cada ítem de la bandeja debe tener un botón o enlace "¿Por qué?" que, al hacer clic, expanda el bloque de explicación (sin navegar a otra pantalla).
- **En la confirmación inmediata:** Justo después de un "Aportar", cuando el sistema muestra el resumen ("Se propuso 1 hipótesis..."), debe haber un enlace "Ver detalles de la clasificación" que abra la explicación en un modal o panel desplegable.
- **En el historial de revisiones:** Si el usuario quiere revisar una decisión pasada, la explicación debe estar disponible también en el historial.

### 2.3 ¿Cómo se genera la explicación?

La explicación no debe ser una respuesta en lenguaje natural generada por un LLM en cada revisión (eso sería costoso y lento). En su lugar, el servicio de clasificación de QuBeKa (definido en la Ola 1, punto 3) debe **devolver, junto con la propuesta de estructura, un conjunto de metadatos estructurados** que Kuestion pueda traducir a lenguaje natural.

Los metadatos mínimos que el servicio de clasificación debe devolver son:

| Campo | Tipo | Descripción |
|---|---|---|
| `decision_type` | string | El tipo de nodo propuesto (Q, SQ, H, N-K, N-A). |
| `confidence` | float | Nivel de confianza del sistema (0.0 a 1.0). |
| `reasons` | array de strings | Lista de razones breves que explican la decisión (ej. "El texto describe una causa", "Contiene palabras clave de procedimiento"). |
| `alternatives_considered` | array de objetos | Lista de otros tipos de nodo que se consideraron, con su razón de descarte (ej. { "type": "N-K", "reason": "No describe un procedimiento" }). |
| `detected_patterns` | array de strings | Patrones lingüísticos detectados (ej. "palabras de causa", "estructura de pregunta"). |

Kuestion recibe estos metadatos y los traduce a un bloque de texto legible, usando plantillas predefinidas. Esto asegura que la explicación sea consistente y no dependa de un LLM en tiempo real.

### 2.4 Diseño visual y copy

- El bloque de explicación debe ser colapsable por defecto, para no abrumar al usuario. Un botón o enlace "¿Por qué?" lo expande.
- El copy debe ser en lenguaje natural, no técnico, pero sin perder precisión. Ejemplos:
  - *"El sistema clasificó esto como Hipótesis porque el texto describe una causa probable ('el batch bancario no llega'), en lugar de describir un procedimiento o un hecho confirmado. Confianza: 85%"*
  - *"El sistema consideró también clasificarlo como Nota de Conocimiento, pero lo descartó porque el texto no describe un procedimiento."*
- El nivel de confianza debe representarse visualmente con un indicador de barras o un semáforo de colores (verde >80%, amarillo 50-80%, rojo <50%).
- Si el sistema detectó que el usuario podría estar esperando otro tipo de clasificación, se puede agregar un mensaje de advertencia: *"Nota: El sistema no encontró suficiente evidencia para clasificar esto como N-K. Por favor, revisa si consideras que debería serlo."*

---

## 3. Dependencias con la Ola 1 y otros puntos de la Ola 2

| Dependencia | Estado | Nota |
|---|---|---|
| **Servicio de clasificación de QuBeKa (Ola 1, punto 3)** | Ya definido, pendiente de implementar | Este servicio debe ser extendido para devolver los metadatos de explicabilidad (sección 2.3). **Es una capacidad nueva** — hoy el servicio no produce razonamiento estructurado, por lo que su implementación requiere trabajo de ingeniería de prompt y/o lógica adicional, no es una simple extensión. |
| **Bandeja de revisión (Ola 2, punto 1)** | Ya definido, pendiente de implementar | La explicabilidad se muestra dentro de la bandeja. La bandeja necesita consumir los metadatos de explicabilidad desde la respuesta de QuBeKa. |
| **Confirmación inmediata (Ola 1, punto 3)** | Ya definido, pendiente de implementar | La confirmación debe incluir el enlace a la explicación. |
| **La "explicabilidad" no depende del punto 2 (reconfirmación) ni del punto 3 (indicador de vigencia)** | Son independientes | La explicabilidad se puede implementar en paralelo con los otros puntos de la Ola 2. |

---

## 4. Experiencia de usuario detallada

### 4.1 Flujo principal: revisión con explicación expandida

1. Un usuario abre la bandeja de revisión (punto 1) y ve un ítem pendiente.
2. El ítem muestra el texto original del aporte, la clasificación propuesta, y el botón "¿Por qué?".
3. El usuario hace clic en "¿Por qué?" y se expande el bloque de explicación:
   - *"Se clasificó como Hipótesis porque el texto describe una causa probable ('el batch bancario no llega'), en lugar de describir un procedimiento o un hecho confirmado. Confianza: 85%"*
   - *"Alternativa considerada: Nota de Conocimiento — descartada porque no se detectó un procedimiento."*
4. El usuario, al entender la decisión, decide aprobar.
5. El sistema promueve el nodo al grafo activo.

### 4.2 Flujo: baja confianza

1. Un usuario aporta un texto ambiguo que el sistema clasifica con un 45% de confianza.
2. En la bandeja de revisión, el ítem muestra el indicador de confianza en amarillo/naranja y el bloque de explicación destaca que la decisión es incierta.
3. El usuario lee la explicación y decide que la clasificación no es correcta, así que hace clic en "Ajustar" (definido en el punto 1) para corregir la clasificación manualmente.

### 4.3 Flujo: decisión de clasificación con alternativas

1. El sistema clasifica un texto como N-K, pero consideró también H y SQ.
2. El bloque de explicación muestra: *"Se clasificó como Nota de Conocimiento porque el texto describe un hecho comprobado ('el job falla'), y no se detectaron palabras que sugieran incertidumbre. Alternativas consideradas: Hipótesis — descartada porque no se detectaron palabras de causa probable; Sub-pregunta — descartada porque el texto responde, no pregunta."*
3. El usuario, al ver que el sistema consideró las alternativas, confía en la decisión y aprueba.

---

## 5. Operacional

| Aspecto | Definición |
|---|---|
| **Tiempo de respuesta** | Los metadatos de explicabilidad se generan en el momento de la clasificación (por el servicio de QuBeKa) y se almacenan junto con la sesión. No se generan en tiempo real al hacer clic en "¿Por qué?"; solo se muestran. |
| **Almacenamiento** | Los metadatos deben almacenarse en el sandbox de QuBeKa, asociados a la sesión, y estar disponibles para Kuestion a través de la misma API que devuelve las sesiones pendientes (punto 1 de la Ola 2). |
| **Internacionalización** | El copy de las explicaciones se genera en español (idioma del producto actual). No hay necesidad de internacionalización en esta fase. |
| **Consistencia** | Las razones deben estar alineadas con las reglas de clasificación que el equipo de QuBeKa define para el flujo de ingestión. Si las reglas cambian, los metadatos deben actualizarse en consecuencia. |

---

## 6. Qué se le pide a cada equipo

### A QuBeKa

1. **Confirmar si el servicio de clasificación actual puede producir estos metadatos** (sección 2.3) con el mismo mecanismo de IA que ya usa, o si requiere trabajo de ingeniería de prompt/lógica adicional específica para explicabilidad. Esto debe resolverse antes de dar el punto por especificado, igual que se hizo con la Ola 1 para el motor de consulta.
2. **Extender el servicio de clasificación** (Ola 1, punto 3) para que devuelva, además de la propuesta de estructura, los metadatos de explicabilidad definidos en la sección 2.3: `decision_type`, `confidence`, `reasons`, `alternatives_considered`, `detected_patterns`.
3. **Almacenar estos metadatos en el sandbox** asociados a la sesión de análisis.
4. **Incluir estos metadatos en el endpoint que devuelve la lista de sesiones pendientes** (definido en el punto 1 de la Ola 2), para que Kuestion pueda mostrarlos en la bandeja.
5. **Definir las reglas y patrones de clasificación** que se usan para generar las razones y alternativas. Esto es esencial para que las explicaciones sean coherentes y no se contradigan.

### A Kuestion

1. **Modificar la bandeja de revisión** para que muestre el bloque de explicación expandible, consumiendo los metadatos devueltos por QuBeKa.
2. **Definir las plantillas de texto** que traducen los metadatos a lenguaje natural, asegurando que el copy sea claro y no técnico.
3. **Mostrar el nivel de confianza** visualmente (color, barra de progreso).
4. **Asegurar que la explicación esté disponible en la confirmación inmediata** después de un "Aportar", usando el mismo mecanismo.
5. **Definir el diseño y la interacción del bloque de explicación** (expansión, colores, iconografía).

---

## 7. Decisiones abiertas

| Decisión | Propuesta a evaluar |
|---|---|
| ¿La explicación debe incluir el texto original del aporte con palabras clave resaltadas? | Evaluar si es útil para que el usuario vea qué parte del texto activó cada razón. En una primera versión, se puede hacer opcional (a través de un toggle en la explicación). |
| ¿La explicación debe estar disponible en el feed de vigilancia o solo en la bandeja? | Evaluar si solo en la bandeja y en la confirmación inmediata. En el feed se muestra solo el indicador de vigencia (punto 3) sin explicación. |
| ¿La explicación debe incluir un enlace a la documentación del método QBK? | Evaluar si se incluye en esta versión o en una ola posterior, según lo solicite el usuario. |
| ¿Qué pasa si la confianza es muy baja y el usuario no entiende la explicación? | El flujo de "Ajustar" (punto 1) permite al usuario corregir manualmente. Eso es suficiente por ahora. |
| ¿Qué criterios específicos debe usar el servicio de clasificación para generar las razones y alternativas? | Esto depende de la confirmación del punto 1 de QuBeKa y de la definición de reglas de clasificación. Se propone evaluar con el equipo de QuBeKa antes de implementar. |

---

## 8. Nota sobre el orden de ejecución

Este punto depende de que el servicio de clasificación de QuBeKa esté implementado (Ola 1, punto 3) y de que la bandeja de revisión (Ola 2, punto 1) esté en construcción. Sin embargo, la extensión del servicio de clasificación para incluir metadatos de explicabilidad es un trabajo incremental que se puede hacer en paralelo con otras tareas de la Ola 2. Se recomienda que el equipo de QuBeKa priorice la generación de metadatos de explicabilidad incluso si la bandeja de Kuestion aún no está lista, para evitar que este punto se convierta en un cuello de botella cuando Kuestion empiece a consumir la información.

**Importante:** el primer paso para este punto debe ser la confirmación del equipo de QuBeKa sobre la viabilidad de generar los metadatos de explicabilidad (sección 6, punto 1). Sin esa confirmación, el alcance y esfuerzo del punto no pueden ser dimensionados correctamente.