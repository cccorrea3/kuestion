# Reconfirmación periódica ligera

*Especificación de trabajo — Ola 2, punto 2*
*Agosto 2026 · Documento de entrada para los equipos de QuBeKa y Kuestion*

---

## 0. Qué resuelve este documento

La Ola 1 estableció que una pregunta (o un nodo) queda vigilada por defecto y que el sistema detecta cambios mediante hash. La Ola 2, punto 1, creó una bandeja de revisión para gestionar los aportes pendientes. Pero el ciclo de vigilancia no termina con la aprobación: una vez que algo ha sido validado e integrado al grafo activo, necesita ser **reconfirmado periódicamente** para asegurar que sigue siendo vigente. Sin este mecanismo, el sistema solo puede decir "esto no ha cambiado", pero no puede decir "esto sigue siendo cierto". Esa distinción es clave para diferenciar un sistema de vigilancia activo de un simple monitor de versiones.

Este documento define la **Reconfirmación periódica ligera**: la acción de marcar que un conocimiento sigue siendo válido, sin tener que reabrir todo el contexto original. A diferencia de otros puntos de la Ola 2 que pulen mecanismos existentes, este punto implica **construcción nueva**: un campo en la base de datos de QuBeKa (`fecha_ultima_confirmacion`), un endpoint de reconfirmación, y el registro de auditoría asociado. Es, en esfuerzo, comparable a un punto de la Ola 1, no a un simple ajuste de UI.

---

## 1. El problema que resuelve (y por qué es necesario)

Hoy, en el ecosistema, QuBeKa no tiene campo `fecha_ultima_confirmacion` separado de `fecha_creacion`. Eso significa que el sistema no puede distinguir entre "esto se creó hace 90 días" y "esto se reconfirmó hace 90 días". El feed de Kuestion, para contenido proveniente de QBK, solo puede mostrar la fecha de creación como proxy de vigencia (ya abordado en el punto 6 de la Ola 1 con un copy honesto).

Pero el problema no es solo técnico (la falta del campo), es también de producto: **si el usuario no tiene una forma ligera de reconfirmar, el sistema nunca podrá darle la tranquilidad de que lo que sabe sigue siendo cierto.** El usuario se queda con la duda de si la respuesta que obtuvo hace meses sigue siendo válida, y la única forma de saberlo sería volver a hacer la pregunta manualmente y comparar (lo que el sistema debería hacer por él).

La reconfirmación ligera resuelve eso: permite que el usuario, con un solo clic, le diga al sistema "esto que vio hace semanas, hoy sigue siendo válido". Con el tiempo, el sistema acumula un historial de reconfirmaciones, y el feed puede mostrar "última reconfirmación: ayer" en lugar de "creado hace 90 días".

---

## 2. Alcance funcional — Reconfirmación ligera

### 2.1 ¿Qué es una reconfirmación?

Es la acción de que una persona (el autor original o alguien con permisos) indique que un nodo (Q, SQ, H, N-K) o una pregunta vigilada completa sigue siendo válida **en el momento actual**, sin modificar su contenido. Es una acción distinta de "editar" y distinta de "validar por primera vez". La reconfirmación no cambia el contenido, solo actualiza `fecha_ultima_confirmacion` y registra quién lo reconfirmó.

**Propuesta a evaluar:** si la reconfirmación se aplica a nodos individuales o a preguntas completas (que agrupan varios nodos). Por simplicidad inicial, se sugiere aplicarla a cada nodo N-K/H/Q individualmente, pero mostrándola al usuario como parte de la pregunta. Esta decisión debe confirmarse con el equipo de producto antes de implementar.

### 2.2 ¿Dónde aparece la acción?

La reconfirmación debe aparecer en los siguientes lugares, con un mismo diseño de botón o enlace:

- **Dentro de la respuesta de Kuestion:** Al mostrar el resultado de una consulta (sea de QBK o de Kuaforia), si el usuario ya está vigilando esa pregunta y la respuesta tiene una `fecha_ultima_confirmacion` anterior a un umbral (ej. 7 días), se muestra un botón: *"¿Sigue siendo válido? Reconfirmar"*. Al hacer clic, el sistema registra la reconfirmación en el nodo correspondiente de QBK (o en la señal de vigencia de Kuaforia, según la fuente).
- **En el feed de vigilancia:** Cada ítem del feed que represente una pregunta vigilada (o un nodo vigilado) muestra, junto a la fecha de última actualización, el mismo botón de reconfirmación, si la última reconfirmación es anterior al umbral.
- **En la bandeja de revisión (punto 1 de la Ola 2):** Aunque la bandeja está pensada para aportes pendientes, se puede agregar una pestaña o sección "Pendientes de reconfirmar" que liste nodos que superaron el umbral sin reconfirmar, ofreciendo el botón de reconfirmación directa.

### 2.3 ¿Cómo se registra la reconfirmación?

- **Para contenido proveniente de QBK:** Kuestion llama a un endpoint de QuBeKa que actualiza el campo `fecha_ultima_confirmacion` del nodo (o de un conjunto de nodos si la reconfirmación es de una pregunta que abarca varios). QuBeKa registra también el usuario que reconfirmó.
- **Para contenido proveniente de Kuaforia:** Kuaforia ya tiene su propio concepto de vigencia (`stale_case`, `low_confidence`). La reconfirmación ligera desde Kuestion debería, en este caso, traducirse en una acción que Kuaforia entienda (ej. marcar el caso como "consultado y confirmado" para actualizar sus señales internas). Por simplicidad, en esta primera versión, la reconfirmación solo se aplica a contenido de QBK; para Kuaforia se mantiene el comportamiento actual (la vigencia se deriva de las señales internas, no de una acción del usuario).

### 2.4 Umbral de tiempo para sugerir reconfirmación

El sistema debe sugerir reconfirmación cuando la `fecha_ultima_confirmacion` supere un umbral configurable. Para la Ola 2, este umbral se fija en **90 días** por defecto, siguiendo la decisión ya cerrada en el ecosistema (Resumen de Sesión, sección 7: "Vigencia en QBK: por tiempo por defecto (90 días)"). El usuario podrá cambiarlo en su perfil o por pregunta en una fase posterior, pero no se requiere una interfaz compleja para esta primera versión.

---

## 3. Dependencias con la Ola 1 y otros puntos de la Ola 2

| Dependencia | Estado | Nota |
|---|---|---|
| **Campo `fecha_ultima_confirmacion` en QuBeKa** | Brecha identificada en el Contrato Mínimo, pendiente de implementar en QuBeKa. Este punto no puede funcionar sin ese campo. | Este es el bloqueo más importante. Se recomienda que QuBeKa priorice este campo en su roadmap interno. |
| **Endpoint de reconfirmación en QuBeKa** | No existe. QuBeKa debe exponer un endpoint que reciba el nodo o lista de nodos y actualice `fecha_ultima_confirmacion`. | Similar al endpoint de promoción del punto 4 de la Ola 1, pero para actualización de metadata, no para promoción. |
| **Interfaz de Kuestion para mostrar el botón** | Kuestion necesita saber, para cada respuesta o feed, cuándo mostrar el botón. Eso requiere que Kuestion lea `fecha_ultima_confirmacion` desde el contrato de QBK (o desde el `StructuredSignalProviderInterface`). | Esto debería estar cubierto por el contrato mínimo, pero en la práctica hay que asegurarse de que el endpoint de QBK devuelva ese campo. |
| **Umbral de 90 días** | Decisión cerrada (Resumen de Sesión, sección 7). | No es negociable en esta ola. |

---

## 4. Experiencia de usuario detallada

### 4.1 Flujo principal: reconfirmación desde la respuesta

1. El usuario hace una consulta en Kuestion ("¿Por qué falla el job de conciliación los lunes?").
2. El sistema muestra la respuesta, con sus fuentes citadas, y al final un indicador: *"Última confirmación: 25 de julio (hace 12 días)."*
3. Si han pasado más de 90 días, el indicador cambia a *"Última confirmación: hace 95 días — [Reconfirmar]"*, con el botón visible.
4. El usuario hace clic en **Reconfirmar**.
5. El sistema actualiza `fecha_ultima_confirmacion` en QuBeKa (o en Kuaforia) y cambia el indicador a *"¡Confirmado! Última confirmación: ahora"*.
6. El feed de vigilancia refleja ese cambio en la próxima actualización.

### 4.2 Flujo desde el feed

1. El usuario abre su feed de vigilancia y ve una pregunta que marcó como "cambios pendientes" (sin cambios detectados, pero con antigüedad de 95 días sin reconfirmar).
2. El ítem muestra: *"Sin cambios detectados, pero no se reconfirma hace 95 días — [Reconfirmar]"*.
3. El usuario hace clic en **Reconfirmar**.
4. El sistema actualiza el nodo y el ítem cambia a *"Reconfirmado hace 5 minutos"*.

### 4.3 Flujo desde la bandeja de revisión (pendientes de reconfirmar)

1. El usuario abre la sección "Revisar" y ve una pestaña "Pendientes de reconfirmar".
2. La lista muestra todos los nodos (preguntas vigiladas) que superan los 90 días sin reconfirmar.
3. Cada ítem tiene el botón **Reconfirmar**.
4. El usuario puede reconfirmar uno por uno o, idealmente, en lote (aunque el lote se deja para una ola posterior).

---

## 5. Operacional

| Aspecto | Definición |
|---|---|
| **Frecuencia de revisión** | El trabajo de reconfirmación se activa a partir del umbral de días (por defecto 90). No hay un job automático que obligue a reconfirmar; es una acción voluntaria que el usuario realiza cuando lo ve conveniente. |
| **Registro de reconfirmaciones** | QuBeKa debe guardar el historial de reconfirmaciones (quién, cuándo, qué nodo) para que Kuestion pueda mostrar una trazabilidad en el feed ("Reconfirmado por Juan el 20 de agosto"). Esto podría ser un nuevo log o usar el sistema de auditoría existente. |
| **Notificaciones** | No hay notificaciones automáticas por falta de reconfirmación en esta versión, pero puede ser una mejora futura (recordatorios semanales de nodos que necesitan reconfirmación). |
| **Permisos** | Solo el autor del nodo o un revisor del workspace puede reconfirmar. Esto debe ser validado por QuBeKa en el endpoint de reconfirmación. |

---

## 6. Qué se le pide a cada equipo

### A QuBeKa

1. **Agregar el campo `fecha_ultima_confirmacion`** a la tabla `nodos` (o a una tabla de metadatos asociada). Este campo debe ser actualizable y tener un índice para consultas eficientes.
2. **Agregar el campo `ultimo_confirmador_id`** (o similar) para registrar quién hizo la última reconfirmación.
3. **Exponer un endpoint** `PATCH /nodos/{id}/reconfirmar` que reciba el token de autenticación, valide permisos y actualice `fecha_ultima_confirmacion` y `ultimo_confirmador_id`.
4. **Asegurar que el contrato mínimo de QBK** (el que Kuestion consume para vigilancia) devuelva `fecha_ultima_confirmacion` para cada nodo, para que Kuestion pueda mostrar el indicador.
5. **Definir una política de retención** para el historial de reconfirmaciones (¿cuánto tiempo se guarda el registro de quién reconfirmó?).
6. **Registrar en auditoría** cada acción de reconfirmación (quién, cuándo, qué nodo), de forma que sea trazable.

### A Kuestion

1. **Leer `fecha_ultima_confirmacion`** desde el contrato de QBK (a través de `QbkService` o del `StructuredSignalProviderInterface` cuando esté implementado).
2. **Mostrar el indicador de vigencia** en la respuesta, en el feed y en la bandeja de revisión, con el texto adecuado y el botón de reconfirmación cuando corresponda (umbral de 90 días).
3. **Construir el llamado al endpoint de reconfirmación de QuBeKa**, pasando el token de autenticación y el ID del nodo.
4. **Actualizar la UI** después de una reconfirmación exitosa (cambiar el indicador, eliminar el ítem de la lista de pendientes si estaba ahí).
5. **Definir el copy**: "Reconfirmar", "Reconfirmado hace X días", "Sin reconfirmar desde...". Asegurar que el tono sea amigable, no punitivo.

---

## 7. Decisiones abiertas

| Decisión | Propuesta a evaluar |
|---|---|
| ¿La reconfirmación se aplica a nodos individuales o a preguntas completas (que agrupan varios nodos)? | Evaluar si se aplica a cada nodo N-K/H/Q individualmente, mostrándolo al usuario como parte de la pregunta. Esta decisión debe tomarse con el equipo de producto antes de la implementación. |
| ¿Cuándo desaparece el botón de reconfirmación? | El botón aparece cuando `fecha_ultima_confirmacion` supera el umbral (90 días) y desaparece después de confirmar (y se actualiza el indicador). |
| ¿Se puede reconfirmar desde el feed sin abrir la pregunta? | Sí, el botón en el feed debería hacer la reconfirmación directamente, sin necesidad de navegar a la pregunta. Eso es parte de la "ligereza". |
| ¿El umbral debe ser configurable por el usuario (por ejemplo, en su perfil) o mantenerse fijo en 90 días? | **Pregunta abierta.** En la Ola 2 se deja fijo en 90 días para simplificar (decisión ya cerrada en el ecosistema). La configuración por usuario se propone para una ola posterior, pero requiere confirmación del dueño de producto. |
| ¿Qué pasa si el usuario reconfirma un nodo que ya no existe o fue eliminado? | El endpoint de QuBeKa debe devolver un error controlado, y Kuestion debe mostrar un mensaje claro ("Este conocimiento ya no está disponible para reconfirmar"). |

---

## 8. Nota sobre el orden de ejecución y esfuerzo

Este punto depende críticamente de que QuBeKa implemente el campo `fecha_ultima_confirmacion` y el endpoint de reconfirmación. A diferencia de otros puntos de la Ola 2, que son principalmente ajustes de UI o copy, este punto implica **construcción nueva en el backend de QuBeKa**: migración de base de datos, nuevo endpoint, registro de auditoría, y actualización del contrato de respuesta. El esfuerzo es comparable al de un punto de la Ola 1 (ej. el motor de consulta o el servicio de clasificación), no a un simple ajuste de UI.

**Se recomienda:**
1. Que QuBeKa priorice esta funcionalidad en su roadmap interno, incluso antes de que la Ola 1 esté completamente cerrada, para que Kuestion pueda empezar a trabajar en la UI en paralelo.
2. Una reunión de sincronización entre los equipos de QuBeKa y Kuestion para confirmar la viabilidad, el esfuerzo estimado y la definición precisa del contrato de reconfirmación antes de iniciar el desarrollo.
3. Considerar que este punto es un **prerrequisito para el punto 3 de la Ola 2** (Indicador de vigencia visible), que depende directamente de la existencia del campo `fecha_ultima_confirmacion`.
