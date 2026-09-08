# Vigilancia y feed para preguntas conectadas a QBK

*Especificación de trabajo — Ola 1, puntos 5 y 6*
*Agosto 2026 · Documento de entrada para los equipos de QuBeKa y Kuestion*

---

## 0. Qué resuelve este documento

Con el motor de consulta (punto 1) y el flujo de Aportar (punto 3) ya definidos, este documento cierra los dos puntos restantes de la Ola 1: cómo se detecta que algo cambió en QBK (punto 5) y cómo se lo comunica el feed a la persona (punto 6). A diferencia de los documentos anteriores, la mayor parte de este mecanismo **ya existe y no requiere cambios de fondo** — el trabajo real acá es de ajuste, no de construcción nueva. También corrijo acá una asunción que se coló sin decisión formal en el documento del punto 3.

---

## 1. Punto 5 — Vigilancia

### 1.1 El mecanismo no cambia

Como ya quedó dicho en el documento del punto 1 (sección 2.4): el job horario de Kuestion (`CheckQuestionUpdatesJob`) re-consulta la pregunta original y hashea la respuesta recibida, comparándola contra la versión anterior. Con `QbkService` implementando `RagProviderInterface`, el job simplemente llama a ese servicio en vez de a `KuaforiaService` — sin ningún cambio en el `ChangeDetector` en sí. Esto queda confirmado, no es una decisión nueva de este documento.

### 1.2 Umbral de clasificación

Se reutiliza el mismo umbral ya definido (similitud coseno 0.8 separa `minor` de `new_version`), sin ajuste específico para QBK. No hay razón conocida para que el contenido proveniente de QBK necesite un umbral distinto — es texto comparado con el mismo mecanismo, independientemente del origen.

### 1.3 Corrección a una asunción del documento del punto 3

El documento del punto 3 mencionó, de paso, que Kuestion "ofrece Vigilar esta pregunta si el resultado fue relevante" — dando a entender que la vigilancia sería opcional y condicionada a la relevancia del resultado. **Esto no fue una decisión tomada, fue una imprecisión.** El comportamiento actual de Kuestion (ya implementado, F1) es que toda pregunta creada queda vigilada automáticamente según su frecuencia de revisión — no hay un paso separado de "activar vigilancia". Este documento restablece ese comportamiento como el correcto también para preguntas conectadas a QBK: **se vigila por defecto, sin excepción, incluida una pregunta que no encontró respuesta.**

### 1.4 Por qué vigilar también una pregunta sin respuesta importa especialmente acá

Este es el caso más interesante que aparece al conectar QBK, y vale la pena que quede explícito: si alguien pregunta algo que QBK no tiene todavía (`found: false`, según el contrato del punto 1), y esa pregunta queda vigilada por defecto (sección 1.3), entonces el día que **cualquier persona** —la misma u otra, en el mismo workspace— aporte contenido que termine resolviendo esa pregunta (vía el flujo de Aportar del punto 3, con su gate ya definido en el punto 4), el job horario va a detectar el cambio de forma completamente natural: el hash de "no encontré nada" es muy distinto al hash de una respuesta real, así que se clasifica como `new_version` sin ningún ajuste de código adicional.

Esto no requiere ningún mecanismo nuevo — es una consecuencia directa de mecanismos que ya definimos por separado, funcionando juntos. Pero sí requiere una decisión de copy (sección 2.4), porque merece un mensaje distinto a un cambio cualquiera: no es "algo cambió", es "algo que no tenía respuesta, ahora la tiene".

---

## 2. Punto 6 — Feed

### 2.1 Estados que ya existen, sin cambios

El feed y las notificaciones in-app (F5, F9) ya funcionan con badges y clasificación `unchanged`/`minor`/`new_version`. No hace falta reconstruir nada de esa base.

### 2.2 La brecha real: vigencia de contenido QBK

Como ya se identificó en el documento del punto 1: QBK no tiene hoy un campo `fecha_ultima_confirmacion` separado de `fecha_creacion` — es una brecha pendiente del lado de QBK, ya anotada en el Contrato Mínimo (sección 3.2) como trabajo futuro, no parte de esta Ola.

**Decisión para esta versión:** lanzar aceptando esa limitación, usando `fecha_creacion` como proxy — pero sin pretender una precisión que no existe. En vez de mostrar "última revisión: hace 45 días" como si fuera un dato confirmado (lo cual sugiere falsamente que alguien reconfirmó vigencia hace 45 días), el copy debería ser honesto sobre qué se sabe realmente: algo como *"agregado hace 45 días — sin reconfirmaciones registradas"*. Es una diferencia de una frase, pero evita prometer una precisión de vigencia que el sistema todavía no puede garantizar para contenido de QBK.

### 2.3 Mostrar la fuente

Dado que un usuario puede tener más de un repositorio conectado (QBK, Kuaforia, u otro a futuro), y que ya existe el modelo de repositorios con su `connector_type`, el feed debería indicar de forma simple de qué fuente viene cada pregunta cuando haya más de un repositorio activo — reutilizando la misma lógica ya definida para mostrar/ocultar el nombre del repositorio según haya uno o varios conectados (ya especificado en el sistema de conectores). No es trabajo nuevo, es aplicar un patrón ya decidido a este caso.

### 2.4 Copy especial: de "sin respuesta" a "con respuesta"

Este es el único copy genuinamente nuevo que hace falta para el caso descrito en la sección 1.4. En vez de mostrar el mensaje genérico de cambio de versión, cuando la clasificación detecta que la versión anterior tenía `found: false` y la nueva tiene `found: true`, el feed debería mostrar algo distinto y más positivo: *"Ahora hay información sobre algo que preguntaste."* — es, en la práctica, el momento de mayor valor percibido de todo este flujo: la persona preguntó, nadie sabía, alguien aportó, y el sistema le avisó sin que tuviera que volver a preguntar.

### 2.5 Estados de feed resultantes (resumen)

| Estado | Cuándo ocurre | Copy sugerido |
|---|---|---|
| Sin cambios | Hash igual entre re-consultas | (sin badge, o "sin cambios") |
| Cambio menor | Similitud ≥ 0.8 | "Se actualizó la respuesta" |
| Cambio mayor | Similitud < 0.8, `found` sigue `true` en ambas | "La respuesta cambió de forma importante" |
| **Nuevo — resuelto** | `found` pasó de `false` a `true` | "Ahora hay información sobre algo que preguntaste" |

---

## 3. Operacional

| Aspecto | Definición |
|---|---|
| **Frecuencia del job** | Sin cambios — mismo job horario existente, mismas frecuencias configurables (semanal/mensual/trimestral) por pregunta. |
| **Carga esperada** | El volumen de preguntas vigiladas sobre QBK va a crecer más lento que sobre Kuaforia al principio (el contenido depende del flujo de Aportar, más manual). No se anticipa necesidad de ajustar la infraestructura del job por esto. |
| **Sin cambios en `ChangeDetector`, `DiffGenerator`** | Confirmado — ambos servicios son agnósticos del origen del texto que reciben. |

---

## 4. Qué se le pide a cada equipo

### A QuBeKa

Sin pedidos nuevos de este documento — los puntos 5 y 6 no requieren trabajo adicional del lado de QuBeKa más allá de lo ya solicitado en los documentos de los puntos 1, 3 y 4. La brecha de `fecha_ultima_confirmacion` (sección 2.2) queda fuera de esta Ola, como ya estaba definido.

### A Kuestion

1. Confirmar que no hace falta ningún ajuste en `ChangeDetector`/`DiffGenerator` más allá de apuntar al nuevo `QbkService` (ya cubierto en el punto 1, se reafirma acá).
2. Ajustar el copy del feed para mostrar vigencia con lenguaje honesto cuando la fuente es QBK (sección 2.2).
3. Mostrar la fuente de cada pregunta cuando haya más de un repositorio conectado (sección 2.3) — reutilizando el patrón ya definido en el sistema de conectores.
4. Agregar la clasificación y el copy especial para el caso "de sin respuesta a con respuesta" (sección 2.4 y 2.5).
5. Confirmar que la vigilancia automática por defecto (sección 1.3) ya cubre preguntas con `found: false`, sin ningún gate condicional agregado — validar con una prueba real, dado que el documento del punto 3 sugirió lo contrario por error.

---

## 5. Decisiones abiertas

- Ninguna decisión de arquitectura queda pendiente en este documento — es, de los cuatro documentos de la Ola 1, el que menos preguntas abiertas genera, porque se apoya casi enteramente en mecanismos ya definidos en los puntos anteriores.
- Único punto a validar con uso real, no a decidir de antemano: si el copy honesto de vigencia (sección 2.2) genera dudas o desconfianza en la persona ("¿por qué no me dice cuándo se confirmó?") — algo que solo se sabe probando, no analizando.
