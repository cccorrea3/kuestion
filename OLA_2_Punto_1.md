# Bandeja de revisión rápida

*Especificación de trabajo — Ola 2, punto 1*
*Agosto 2026 · Documento de entrada para los equipos de QuBeKa y Kuestion*

---

## 0. Qué resuelve este documento

La Ola 1 ya permite que una persona aporte conocimiento desde Kuestion, que el sistema lo clasifique automáticamente (punto 3) y que ese aporte quede en un sandbox a la espera de confirmación humana (punto 4). La Ola 1 definió el mecanismo de validación, pero no definió la **experiencia de revisión** como un flujo continuo y de baja fricción. El punto 4 de la Ola 1 se enfocó en el momento de confirmación inmediata (justo después de aportar), pero no en cómo se gestionan múltiples aportes pendientes a lo largo del tiempo — y, sobre todo, no resolvió cómo hacer que la revisión se sienta como "aprobar en segundos" en lugar de "trabajo administrativo".

Este documento define la **Bandeja de revisión rápida**: la superficie donde una persona ve todos sus aportes pendientes (y los de su equipo, si aplica) y los aprueba, rechaza o ajusta en el menor tiempo posible. No agrega una capacidad nueva al sistema; **pule** el mecanismo de validación ya existente para que la experiencia de revisión sea coherente con la promesa de "Kuestion como centro de la experiencia".

Este es el primero de los cinco puntos de la Ola 2. Los restantes se definirán en documentos separados.

---

## 1. El problema que resuelve (y por qué es necesario)

En la Ola 1, la revisión ocurre en el momento inmediato posterior a un "Aportar", o bien redirige a la pantalla completa de Revisión Humana de QuBeKa si el aporte es complejo. Eso funciona para el primer aporte de una persona, pero no escala cuando hay múltiples aportes pendientes, cuando la persona quiere revisar más tarde, o cuando trabaja en equipo y otros miembros han aportado contenido que necesita su revisión.

El problema no es la existencia del gate humano; el problema es que, sin una bandeja dedicada, la revisión se convierte en una tarea dispersa: la persona tiene que recordar qué aportó, buscar la sesión en QuBeKa, abrirla, revisar la propuesta, decidir. Cada paso agrega fricción, y la fricción acumulada convierte un flujo que debería sentirse liviano en una carga administrativa.

**El objetivo de este punto es:** que la persona pueda abrir una bandeja en Kuestion, ver todos sus aportes pendientes en una sola lista, y aprobar o rechazar cada uno con uno o dos clics, sin tener que salir de Kuestion y sin tener que entender la estructura QBK para tomar la decisión.

---

## 2. Alcance funcional — La bandeja de revisión en Kuestion

### 2.1 Qué ve la persona

Una nueva sección en la navegación de Kuestion, llamada **"Revisar"** (o similar, según definición de UX), ubicada junto a "Feed" y "Tags". Al hacer clic, la persona ve:

- Un contador visible en el ícono de navegación con el número de aportes pendientes de su revisión (excluyendo los que ya revisó o los que no le corresponden).
- Una lista de ítems, ordenada por antigüedad (los más antiguos primero), donde cada ítem muestra:
  - El texto original del aporte (tal como lo escribió la persona o el miembro del equipo que lo aportó).
  - La clasificación propuesta por el sistema, en lenguaje natural (ej: *"Se propuso guardar esto como una hipótesis con la siguiente evidencia"*).
  - El nombre de la persona que hizo el aporte (si es distinta al revisor).
  - Fecha del aporte.
  - Estado actual: `pendiente`, `aprobado`, `rechazado` (para ítems ya procesados, si la persona quiere ver el historial).
- En cada ítem, dos botones grandes y claros: **Aprobar** y **Rechazar**. Opcionalmente, un botón **Ajustar** que abre una vista de edición simple (no la pantalla completa de QuBeKa, a menos que el aporte sea complejo — ver sección 2.4).

### 2.2 Qué pasa al aprobar

1. Kuestion llama a la API de promoción de QuBeKa (la misma que ya definimos en la Ola 1, punto 4).
2. QuBeKa promueve los nodos de la sesión del sandbox al grafo activo, con IDs nuevos y estado `validada`.
3. Kuestion actualiza la bandeja: el ítem desaparece de la lista de pendientes y pasa al historial.
4. Si la persona que aprueba es distinta del autor, el autor recibe una notificación (mismo mecanismo que hoy existe en QuBeKa, no requiere trabajo nuevo aquí).

### 2.3 Qué pasa al rechazar

1. Kuestion llama a la API de rechazo de QuBeKa (mismo endpoint o similar, según lo que QuBeKa decida en su política de retención).
2. QuBeKa descarta los nodos propuestos (baja definitiva del sandbox, o retención por X días según política).
3. Kuestion actualiza la bandeja: el ítem pasa al historial con estado `rechazado`.

### 2.4 Qué pasa al ajustar (edición simple)

- Si el aporte es simple (1-2 nodos, sin ambigüedad), Kuestion muestra un editor de texto en línea para que la persona corrija el contenido de la propuesta antes de aprobar o rechazar. Al guardar, la edición se envía a QuBeKa como una actualización de la sesión, y luego se promueve igual que en 2.2.
- Si el aporte es complejo (más de 2 nodos, o el análisis marcó ambigüedad), el botón "Ajustar" redirige a la pantalla completa de Revisión Humana de QuBeKa (como ya estaba definido en la Ola 1, punto 4). Esto evita duplicar funcionalidad compleja en Kuestion.

### 2.5 Qué NO hace esta bandeja en su primera versión

- No incluye filtros complejos (por fuente, por tipo de nodo, por fecha) — solo ordenamiento por antigüedad y separación por estado `pendiente` vs `historial`.
- No incluye revisión en lote (aprobar varios de una vez) — es una mejora posible para una ola posterior.
- No incluye la capacidad de delegar revisión a otra persona desde la bandeja — eso ya existe en QuBeKa, no se duplica en Kuestion en esta versión.

---

## 3. Dependencias con la Ola 1

Este punto depende directamente de los siguientes elementos ya definidos en la Ola 1:

| Dependencia | Estado | Nota |
|---|---|---|
| API de promoción de sesión de QuBeKa | A definir en Ola 1, punto 4 | La bandeja necesita este endpoint para aprobar/rechazar sin redirigir. |
| API de rechazo de sesión de QuBeKa | A definir en Ola 1, punto 4 | Misma dependencia. |
| Distinción entre sesión simple vs compleja | A definir en Ola 1, punto 4 | La bandeja usa ese criterio para decidir si el botón "Ajustar" abre un editor simple o redirige a QuBeKa. |
| Autenticación compartida (token de Kuestion → QuBeKa) | Ya definido en Ola 1, puntos 1 y 3 | La bandeja reutiliza la misma credencial. |

**Por lo tanto, este punto no se puede implementar hasta que la Ola 1 haya resuelto esas dependencias.** Es un orden natural de ejecución: la bandeja es el "consumidor" de la API de promoción/rechazo; si la API no existe, la bandeja no puede hacer su trabajo.

---

## 4. Experiencia de usuario detallada

### 4.1 Flujo principal: revisión de un aporte propio

1. La persona abre Kuestion, ve un badge en el ícono "Revisar" con un número.
2. Hace clic y ve su lista de aportes pendientes.
3. Lee el primer ítem: *"Hace 3 días aportaste: 'El job falla porque el batch bancario no llega antes de las 6am los lunes.' El sistema propuso guardarlo como una hipótesis."*
4. La persona recuerda el contexto, decide que es correcto, y hace clic en **Aprobar**.
5. El ítem desaparece, el badge baja en 1, y la persona sigue con su día.

**Tiempo estimado:** 5-10 segundos por ítem.

### 4.2 Flujo secundario: revisión de un aporte de otro miembro del equipo

(Nota: esto solo aplica si el workspace de QuBeKa tiene más de un miembro y el rol de revisor está definido. La bandeja en Kuestion debe mostrar solo los aportes que la persona tiene permiso de revisar, según la política de roles de QuBeKa — no se define una política nueva en este documento.)

1. La persona abre la bandeja y ve un ítem que dice: *"Juan aportó hace 2 días: 'El timeout en la API de pagos se resolvió con retry exponencial.' El sistema propuso guardarlo como una nota de conocimiento."*
2. La persona no tiene el contexto completo, pero confía en Juan, así que aprueba.
3. El ítem desaparece, y Juan recibe una notificación (vía QuBeKa) de que su aporte fue aprobado.

### 4.3 Flujo de ajuste simple

1. La persona abre la bandeja y ve un ítem donde la clasificación propuesta no es exactamente correcta.
2. Hace clic en **Ajustar**, se abre un editor de texto en la misma vista (sin salir de Kuestion).
3. Corrige el texto de la hipótesis y hace clic en **Guardar cambios**.
4. Luego, desde el mismo ítem, hace clic en **Aprobar**.
5. El ítem desaparece y la versión corregida se promueve al grafo activo.

---

## 5. Operacional

| Aspecto | Definición |
|---|---|
| **Sincronía** | La bandeja consulta el estado de las sesiones pendientes de QuBeKa en cada carga de la vista (no en tiempo real, pero puede actualizarse con un `wire:poll` o similar). No hay necesidad de WebSockets para esta versión. |
| **Pagínación** | Si hay más de 50 ítems pendientes, se paginan de a 20. |
| **Roles** | La bandeja debe respetar los roles de QuBeKa: solo mostrar aportes que la persona tiene permiso de revisar (según el workspace y su rol). Esto se resuelve en el momento de consultar a la API de QuBeKa, no con lógica propia de Kuestion. |
| **Notificaciones** | La aprobación/rechazo de un aporte de otro miembro del equipo genera una notificación en QuBeKa (ya existe). Kuestion no necesita gestionar esa notificación en esta versión, pero en el futuro podría mostrar una notificación in-app propia si se justifica. |

---

## 6. Qué se le pide a cada equipo

### A QuBeKa

1. Exponer un endpoint que devuelva la lista de sesiones de análisis pendientes de revisión para un workspace dado, filtradas por el usuario que hace la consulta (según su token y rol). Este endpoint debe devolver, como mínimo: `session_id`, `fecha_creacion`, `autor_id`, `autor_nombre`, `texto_original_del_aporte`, `resumen_clasificacion` (el mismo `resumen` ya definido en el punto 3 de la Ola 1), y un flag `es_compleja` (según el criterio que se defina en el punto 4 de la Ola 1).
2. Confirmar que el endpoint de promoción/rechazo de sesión (definido en el punto 4 de la Ola 1) acepta ser llamado desde Kuestion con la misma autenticación que el resto de los conectores.
3. Definir el comportamiento de rechazo (borrado vs retención) para que Kuestion sepa qué mostrar en el historial (si se conserva el ítem en el historial con estado `rechazado` o desaparece por completo).

### A Kuestion

1. Construir la nueva vista "Revisar" en la navegación, con el contador de pendientes visible en el ícono.
2. Construir la lista de ítems pendientes, consumiendo el endpoint de QuBeKa definido en el punto anterior.
3. Construir los botones Aprobar / Rechazar / Ajustar, llamando a los endpoints correspondientes de QuBeKa.
4. Construir el editor simple para ajustes (si el aporte no es complejo), con la lógica de actualizar la sesión en QuBeKa.
5. Definir el copy y el diseño visual de los ítems, asegurando que la clasificación propuesta se muestre en lenguaje natural y no con etiquetas técnicas.

---

## 7. Decisiones abiertas

| Decisión | Propuesta para cerrar |
|---|---|
| ¿Se incluye un historial de ítems ya revisados (aprobados/rechazados) en la misma bandeja, o solo se muestra pendientes? | Propuesta: mostrar solo pendientes, pero tener un enlace a "Historial" que muestre los últimos 50 ítems procesados, con su estado. |
| ¿El contador de pendientes incluye solo aportes propios o también aportes de otros miembros del equipo que la persona puede revisar? | Propuesta: incluir ambos, porque la persona que tiene rol de revisor debe ver todo lo pendiente en su workspace. |
| ¿Cuánto tiempo se retiene un ítem en la bandeja si no se revisa? | Esto depende de la política de retención de sesiones de QuBeKa (sección 4.2 del punto 4 de la Ola 1), que aún no está definida. Este punto queda pendiente hasta que QuBeKa lo resuelva. |

---

## 8. Nota sobre el orden de las olas

Este punto es el primero de la Ola 2, pero su implementación depende de que la Ola 1 esté completamente ejecutada y validada — en particular, de que los endpoints de promoción/rechazo y el criterio de complejidad estén definidos y operativos. No se recomienda empezar a construir esta bandeja antes de tener esos bloques resueltos, porque la bandeja no tendría nada que consumir.

Dicho esto, el diseño de esta bandeja puede avanzar en paralelo con la implementación de la Ola 1, pero la ejecución de código de este punto debe esperar a que la Ola 1 esté cerrada, para no construir sobre una base inestable.