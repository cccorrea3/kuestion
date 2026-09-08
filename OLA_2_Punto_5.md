# Notificaciones fuera de la app (correo electrónico)

*Especificación de trabajo — Ola 2, punto 5*
*Agosto 2026 · Documento de entrada para los equipos de QuBeKa y Kuestion*

---

## 0. Qué resuelve este documento

Los puntos anteriores de la Ola 2 han mejorado la experiencia dentro de Kuestion: la bandeja de revisión (punto 1), la reconfirmación ligera (punto 2), el indicador de vigencia (punto 3) y la explicabilidad (punto 4). Pero todo esto depende de que el usuario **abra Kuestion** para ver los cambios, las revisiones pendientes y las reconfirmaciones necesarias. En la práctica, los usuarios no viven dentro de Kuestion; viven en su correo electrónico, en Slack, en su calendario. Si el sistema solo notifica dentro de la app, el usuario puede perderse eventos críticos y la vigilancia se vuelve reactiva (el usuario tiene que acordarse de revisar) en lugar de proactiva.

Este documento define las **Notificaciones fuera de la app (correo electrónico)**: el envío de mensajes de correo electrónico a los usuarios para informarles sobre eventos relevantes que ocurren en el ecosistema (cambios en respuestas vigiladas, nuevos aportes pendientes de revisión, reconfirmaciones necesarias, etc.). Es la pieza que convierte a Kuestion en un sistema de vigilancia **proactivo**, capaz de llegar al usuario donde está, sin que este tenga que recordar revisar la app.

---

## 1. El problema que resuelve (y por qué es necesario)

Hoy, el único canal de notificación en Kuestion es el in-app (el badge en la campana y el feed). Esto funciona para usuarios que abren la app a diario, pero no para aquellos que solo la usan esporádicamente. Cuando un usuario no abre la app durante varios días, puede perderse:

- Que una respuesta que vigilaba cambió de forma importante.
- Que un aporte que hizo fue aprobado o rechazado por su equipo.
- Que un nodo que reconfirmó hace tiempo está pendiente de reconfirmación.
- Que alguien más aportó conocimiento que responde a una pregunta que él mismo hizo.

Sin notificaciones por correo, el sistema depende de que el usuario recuerde revisar periódicamente, lo que va en contra de la promesa de "vigilancia automática". El correo electrónico es el canal más universal y de baja fricción para llegar al usuario donde está: su bandeja de entrada.

Además, las notificaciones por correo tienen un efecto secundario positivo: **crean hábito**. Un correo bien diseñado que llegue en el momento adecuado puede llevar al usuario a abrir Kuestion y actuar, cerrando el ciclo de vigilancia. Con el tiempo, el usuario aprende que puede confiar en Kuestion para avisarle cuando algo pasa, sin tener que estar pendiente constantemente.

---

## 2. Alcance funcional — Notificaciones por correo

### 2.1 ¿Qué eventos generan un correo?

Para la Ola 2, se definen los siguientes eventos que deben disparar un correo electrónico a los usuarios relevantes:

| Evento | Destinatario | Contenido del correo (resumen) |
|---|---|---|
| **Cambio importante en una pregunta vigilada** (clasificado como `new_version` en el feed) | Usuario que creó la pregunta vigilada | "Tu pregunta '¿Cómo afecta la nueva política...?' tiene una nueva respuesta. [Ver cambios]" |
| **Aporte pendiente de revisión** (cuando alguien más aporta contenido que necesita la revisión de otro) | Revisores del workspace (según roles de QuBeKa) | "Juan ha aportado un nuevo conocimiento que necesita tu revisión. [Revisar ahora]" |
| **Aporte propio aprobado o rechazado** | Autor del aporte | "Tu aporte 'El job falla...' ha sido aprobado (o rechazado) por [nombre del revisor]." |
| **Reconfirmación pendiente** (cuando un nodo supera el umbral de días sin reconfirmar) | Autor del nodo o último revisor | "Hace 45 días confirmaste que 'El job falla...' era válido. ¿Sigue siendo cierto? [Reconfirmar]" |
| **Alerta de vigencia crítica** (cuando una señal de Kuaforia como `stale_case` o `low_confidence` se activa para una pregunta vigilada) | Usuario que creó la pregunta | "La respuesta a tu pregunta '¿El endpoint de pagos tiene timeout?' puede estar obsoleta. [Revisar en Kuaforia]" |

**Nota:** Los eventos de "cambio menor" (`minor` en el feed) no disparan correo en esta versión, para evitar saturar al usuario. Solo los cambios mayores (`new_version`) y los eventos que requieren acción humana (revisión, reconfirmación) generan correo.

### 2.2 ¿Cómo se configura la frecuencia?

- **Por defecto:** El usuario recibe un correo por cada evento individual, en tiempo real (o con un delay de hasta 5 minutos para agrupar eventos de la misma sesión). No hay un digest diario en esta versión.
- **Preferencias del usuario:** En el perfil de Kuestion, el usuario puede optar por:
  - **Recibir todos los correos** (por defecto).
  - **Recibir solo correos de eventos críticos** (revisión pendiente, alerta de vigencia, reconfirmación necesaria) — excluyendo cambios en respuestas vigiladas.
  - **No recibir correos** (solo notificaciones in-app).
  - Esta configuración se almacena en Kuestion y se aplica a todos los eventos (no hay configuración por tipo de evento en esta versión).

### 2.3 Contenido del correo (diseño)

Cada correo debe tener una estructura clara y accionable, con los siguientes elementos:

- **Asunto:** Claro, con el nombre del evento y la pregunta o nodo afectado. Ej: *"📬 Nuevo aporte pendiente de revisión: 'El job falla...'"*.
- **Cuerpo:**
  - **Saludo:** "Hola [nombre],"
  - **Resumen del evento:** Una o dos frases que expliquen qué pasó y por qué es relevante para el usuario.
  - **Contexto adicional:** Si el correo es por un cambio en una respuesta, incluir el primer párrafo de la nueva respuesta y un enlace a la pregunta.
  - **Llamada a la acción (CTA):** Un botón o enlace destacado que lleva al usuario directamente a la acción correspondiente en Kuestion (revisar aporte, reconfirmar, ver cambios, etc.).
- **Pie de página:** Instrucciones para cambiar la configuración de correos, y un enlace para darse de baja (en cumplimiento de normativas de correo).

### 2.4 Ejemplo de correo para "reconfirmación pendiente"

**Asunto:** ⏰ ¿Sigue siendo válido? Reconfirma "El job falla los lunes"

**Cuerpo:**

> Hola Ana,
>
> Hace 45 días confirmaste que esta respuesta era válida:
>
> *"El job de conciliación falla los lunes porque el batch del banco no llega antes de las 6am."*
>
> ¿Sigue siendo cierto hoy? Si es así, reconfirmalo con un clic, y seguirá siendo parte de tu conocimiento vigilado.
>
> **[Reconfirmar ahora]** → (enlace directo a la pregunta con acción de reconfirmación)
>
> Si ya no es válido, puedes editarlo o descartarlo desde Kuestion.
>
> — El equipo de Kuestion
>
> [Configurar mis notificaciones] · [Dejar de recibir estos correos]

---

## 3. Dependencias con la Ola 1 y otros puntos de la Ola 2

| Dependencia | Estado | Nota |
|---|---|---|
| **Sistema de notificaciones in-app de Kuestion** | Ya existe (F5) | Los eventos que generan correo son los mismos que generan notificaciones in-app. Se reutiliza la lógica de clasificación de eventos. |
| **Cola de trabajos (Queue)** | Ya existe en Kuestion | Los correos se envían desde un job en cola, para no bloquear la respuesta de la UI. |
| **Preferencias del usuario** | Nuevo | Se necesita una tabla o campo en el perfil de usuario para almacenar la preferencia de correo. |
| **Servicio de correo (SMTP)** | Ya existe en Kuestion (configurado, pero solo para logs) | Se debe habilitar el envío real de correos con un proveedor transaccional (SendGrid, Mailgun, etc.). Esto es una decisión de infraestructura que debe tomarse antes de implementar este punto. |
| **Eventos de QuBeKa** | Depende de la Ola 1, punto 3 (clasificación) y punto 4 (promoción) | **Tensión conocida:** el Contrato Mínimo Compartido establece comunicación por eventos (push) como objetivo, y el polling solo como paso técnico inicial. La solución propuesta en este documento (Kuestion consulta periódicamente a QuBeKa en vez de recibir eventos) es una decisión pragmática para la Ola 2, pero contradice ese objetivo. Este documento la adopta como solución viable en el corto plazo, pero queda pendiente migrar a un modelo de eventos cuando la infraestructura de QuBeKa lo permita. |

---

## 4. Experiencia de usuario detallada

### 4.1 Flujo principal: recibo de correo por cambio en respuesta vigilada

1. Un usuario tiene una pregunta vigilada en Kuestion: "¿El endpoint de pagos tiene timeout?".
2. El job horario detecta un cambio mayor en la respuesta (clasificado como `new_version`).
3. Kuestion actualiza el feed y la versión de la pregunta.
4. Kuestion encola un job para enviar un correo al usuario.
5. El usuario recibe un correo con el asunto: *"📬 Cambio importante en tu pregunta vigilada: '¿El endpoint de pagos...?'"*.
6. El correo muestra el primer párrafo de la nueva respuesta y un botón: **"Ver cambios"**.
7. El usuario hace clic, abre Kuestion, ve el diff, y decide si acepta o descarta la nueva versión (o simplemente toma nota).

### 4.2 Flujo: recibo de correo por aporte pendiente de revisión (cuando el usuario es revisor)

1. Un miembro del equipo hace un "Aportar" en Kuestion, que queda en pendiente de revisión.
2. El sistema identifica quiénes son los revisores del workspace (según roles de QuBeKa).
3. Kuestion encola un correo para cada revisor.
4. El revisor recibe un correo: *"📬 Nuevo aporte pendiente de revisión: 'El job falla...'"*.
5. El correo incluye el texto del aporte y el botón **"Revisar ahora"**.
6. El revisor hace clic, abre la bandeja de revisión de Kuestion, y aprueba o rechaza.

### 4.3 Flujo: recibo de correo por confirmación de aprobación/rechazo (al autor)

1. El revisor aprueba un aporte en la bandeja de revisión.
2. Kuestion actualiza el estado y encola un correo para el autor original.
3. El autor recibe un correo: *"✅ Tu aporte 'El job falla...' ha sido aprobado por Juan"*.
4. El autor puede abrir Kuestion para ver cómo quedó integrado en el grafo.

---

## 5. Operacional

| Aspecto | Definición |
|---|---|
| **Sincronía** | Los correos se envían de forma asíncrona, a través de una cola de trabajos. El usuario no espera a que se envíe el correo para continuar su flujo en la app. |
| **Frecuencia de envío** | Cada evento genera un correo individual, en tiempo real. No hay digest diario en esta versión. |
| **Deduplicación** | Si varios eventos ocurren en un corto período (ej. tres cambios en la misma respuesta en una hora), se debe enviar un solo correo con un resumen, en lugar de tres correos separados. Esto se gestiona con un mecanismo de deduplicación por pregunta y ventana de tiempo (ej. 30 minutos). |
| **Lista de destinatarios** | Se obtiene de la lista de usuarios del workspace que tienen el rol correspondiente (autor, revisor, etc.). Para el caso de "reconfirmación pendiente", se envía al autor o al último revisor. |
| **Preferencias** | Las preferencias del usuario se almacenan en la tabla `users` de Kuestion (ej. `email_notifications` enum: `all`, `critical_only`, `none`). |
| **Logs** | Kuestion debe registrar el envío de cada correo (fecha, destinatario, evento) para trazabilidad y análisis. |
| **Proveedor de correo** | Se debe configurar un proveedor transaccional (SendGrid, Mailgun, etc.) con las credenciales correspondientes en el entorno de producción. |

---

## 6. Qué se le pide a cada equipo

### A QuBeKa

1. **Definir un mecanismo de notificación** para los eventos que son originados en QuBeKa (aporte aprobado/rechazado, nuevo aporte pendiente). En esta versión, por simplicidad, Kuestion consultará periódicamente el estado de las sesiones de análisis para detectar aprobaciones/rechazos y disparar correos desde Kuestion. **Tensión conocida:** esto contradice el objetivo del Contrato Mínimo Compartido (comunicación por eventos/push). Se adopta como decisión pragmática para la Ola 2, pero queda pendiente migrar a un modelo de eventos cuando la infraestructura de QuBeKa lo permita.
2. **Asegurar que los roles de revisor estén correctamente definidos** en QuBeKa para que Kuestion pueda saber a quién enviar el correo cuando hay un aporte pendiente.

### A Kuestion

1. **Implementar el sistema de cola de correos** (usando el sistema de colas de Laravel existente).
2. **Definir las plantillas de correo** para cada tipo de evento, con el diseño y copy adecuado.
3. **Implementar la consulta periódica a QuBeKa** para detectar aprobaciones/rechazos de aportes y disparar correos a los autores.
4. **Construir la interfaz de preferencias de correo** en el perfil del usuario (página de configuración de Kuestion), con las opciones definidas en 2.2.
5. **Implementar la deduplicación de correos** para evitar múltiples correos por el mismo evento en un corto período.
6. **Configurar el proveedor de correo transaccional** en el entorno de producción (SendGrid, Mailgun, etc.).
7. **Crear un job programado** para enviar correos de "reconfirmación pendiente" (por ejemplo, ejecutarse diariamente a las 9am para revisar qué nodos superaron el umbral y enviar correos a los usuarios correspondientes).

---

## 7. Decisiones abiertas

| Decisión | Propuesta a evaluar |
|---|---|
| ¿Se envía un correo por cada cambio menor (`minor`) o solo por cambios mayores (`new_version`)? | Evaluar si solo `new_version` para no saturar al usuario. Los cambios menores se notifican solo in-app. |
| ¿La reconfirmación pendiente se notifica una vez o se repite (ej. cada semana) hasta que el usuario actúe? | Evaluar si se envía un solo correo cuando se supera el umbral o si se envían recordatorios periódicos. Se sugiere un solo correo en esta versión, y evaluar con uso real. |
| ¿El correo incluye el texto completo de la nueva respuesta o solo un resumen? | Evaluar si incluir un resumen (primer párrafo) para que el usuario pueda decidir si abrir la app sin tener que hacerlo para ver el contexto. |
| ¿Kuestion debe tener su propia tabla de preferencias de correo o usar la de QuBeKa? | Evaluar si usar la de Kuestion, ya que el correo lo envía Kuestion. Es más simple y evita dependencias cruzadas. |
| ¿Qué ocurre si el usuario no ha configurado un correo en Kuestion? | Kuestion ya tiene el correo del usuario (registro). Se usa ese. |
| ¿El correo se envía también cuando el usuario está inactivo (sesión expirada)? | Sí, el correo es independiente del estado de sesión. |

---

## 8. Nota sobre el orden de ejecución

Este punto es el más "pesado" de la Ola 2, porque involucra un proveedor de correo externo, colas, preferencias de usuario y plantillas de correo. Se recomienda que se implemente **después** de que los puntos 1 al 4 de la Ola 2 estén al menos en fase de desarrollo avanzado, para no desviar recursos de las funcionalidades centrales (bandeja, reconfirmación, indicador, explicabilidad). Sin embargo, el diseño de las plantillas y la configuración del proveedor de correo se puede hacer en paralelo con los otros puntos, ya que son tareas relativamente aisladas.

**Se sugiere una priorización interna:**
1. Punto 1 y 2 (bandeja y reconfirmación) → más urgentes para la experiencia.
2. Punto 3 y 4 (indicador y explicabilidad) → mejoran la confianza.
3. Punto 5 (correo) → se puede implementar en una segunda fase, una vez que los puntos anteriores estén estabilizados y el equipo tenga capacidad.
