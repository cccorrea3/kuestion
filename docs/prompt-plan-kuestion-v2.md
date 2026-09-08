# Prompt para generar / actualizar un plan de implementación — v2

> **Uso:** sirve tanto para **crear** un plan nuevo como para **actualizar** un plan existente.
> Si vas a **actualizar**, cambia la primera consigna por: *"ya generaste un plan en `{archivo}.md`, quiero que veas nuevamente este requerimiento y actualices el punto / sección que te indico — NO generes un plan nuevo, solo actualiza el existente."*

---

Actúas como el equipo de Kuestion.

Toma el documento `{ESPECIFICACION.md}` como especificación de entrada. Es una especificación
funcional/arquitectura ya cerrada del lado de producto — no debes cuestionar el alcance
ni las decisiones ya tomadas en él, solo lo que te corresponde ejecutar como equipo de
ingeniería según la sección "Qué se le pide a cada equipo".

Genera (o actualiza) un documento de plan de implementación con esta estructura:

## 1. RESUMEN DE ALCANCE

Qué vas a construir, en tus propias palabras, confirmando que entendiste el pedido
correctamente. Si algo del documento es ambiguo o te falta información para dimensionarlo,
decláralo acá como pregunta abierta ANTES de proponer fases — no asumas una respuesta y
sigas adelante.

## 2. FASES Y TAREAS

Divide el trabajo en fases secuenciales. Cada fase debe tener:
- Nombre y objetivo de la fase.
- Tareas concretas dentro de ella.
- Dependencias: qué necesita existir antes de empezar esta fase.
- Entregable verificable al cierre de la fase (algo demostrable, no "código escrito").
- Cómo se prueba/valida esa fase antes de pasar a la siguiente.

## 3. PRUEBAS FUNCIONALES Y DE INTEGRACIÓN (además de las pruebas unitarias)

Los tests unitarios y de integración con mocks no son suficientes por sí solos —
validan que el código hace lo que el código dice que hace, no que el flujo completo
tenga sentido de punta a punta para una persona real usando el sistema. Por cada fase
que involucre un flujo de usuario o una integración entre sistemas, incluye además:

- Un checklist de prueba funcional manual (o E2E automatizada si es posible) que recorra
  el flujo completo como lo haría una persona real.
- Prueba explícitamente contra el servicio/sistema real del otro equipo cuando esté
  disponible, no solo contra mocks — señala en qué fase eso no es posible por falta de
  disponibilidad del otro lado, y qué se prueba con mock mientras tanto.
- Si el flujo incluye una pantalla o configuración nueva (conectar una fuente, cargar una
  credencial), confirma que esa pantalla existe y es usable en la interfaz real — no
  asumas que "se activa automáticamente" solo porque el registro a nivel de
  configuración/backend está completo.
- Si el flujo depende de una pieza externa (proveedor de IA, servicio de terceros),
  confirma que responde correctamente con datos reales antes de cerrar la fase, no solo
  que el código maneja bien una respuesta simulada.

Además, exige estos ítems mínimos en cada fase con UI o integración (son obligatorios,
no opcionales):

- **Rebuild y verificación de assets compilados.** Todo cambio en una vista o componente
  de UI exige recompilar los assets (`npm run build`) y verificar que las clases de estilo
  usadas aparecen en el CSS **compilado/servido**, no solo en el fuente. Un elemento
  presente en el DOM pero sin su clase de estilo en el bundle es un bug de entrega, no un
  detalle.

- **Verificación visual en el navegador real, no solo tests.** El checklist debe incluir
  inspección con las devtools (elemento visible, texto legible, contraste correcto, botón
  con su fondo). Un test que pasa con mock no valida que el flujo funcione para una persona
  real: confirma en pantalla que cada acción (botón, enlace, redirección) ocurre y es
  visible.

- **Compatibilidad con la versión instalada de las dependencias.** Antes de usar un
  método/API de una librería (Livewire, Laravel, paquete, etc.), comprobar que existe en la
  versión real instalada según `composer.json`/`package.json` y el código del vendor, no
  según documentación o ejemplos de otra versión. Ejemplo real en este proyecto: en
  Livewire 4 existe `redirect()` pero NO existe `redirectExternal()` — usarlo rompe el
  flujo en runtime.

- **Fallo visible y claro en runtime.** Si un flujo real falla (llamada al servicio
  externo, redirección, carga de datos), el usuario debe ver un error legible que indique
  qué pasó y cómo proceder — nunca una pantalla congelada en "cargando..." ni un estado
  silencioso que no informa. Un cambio de estado incorrecto (ej: quedar colgado en
  "loading") se reporta como bug, no como workaround.

- **Prueba contra el servicio real cuando esté disponible.** Por cada integración
  (Kuestion↔QuBeKa), ejecutar el flujo real contra el servicio real en la fase
  correspondiente; si está indisponible, declararlo y dejar definido qué se probó con mock
  y qué queda pendiente de validación real antes de declarar la fase cerrada.

## 4. DUDAS Y BLOQUEOS

Distinguiendo bloqueante (no podés avanzar) de no bloqueante (avanzás con un supuesto
razonable, pero necesita confirmarse antes de cerrar la fase).

## 5. ESFUERZO ESTIMADO

Aproximado por fase, señalando la de mayor incertidumbre y por qué.

## 6. FUERA DE ALCANCE

Qué NO vas a construir aunque esté mencionado o insinuado en el documento de origen.

---

## Reglas

- No tomes decisiones de producto por tu cuenta. Si el documento dejó algo como "decisión
  abierta", tu plan debe reflejar esa incertidumbre, no resolverla.
- No dupliques el contenido del documento de origen — referencialo, no lo reescribas.
- Si algo contradice lo que ya sabés de tu propio sistema, señálalo en dudas, no lo
  resuelvas en silencio.
- Un entregable "verificable" nunca es solo "tests unitarios en verde" cuando el punto
  involucra un flujo de usuario o una integración entre sistemas — debe incluir la prueba
  funcional real de la sección 3 (incluidos los ítems obligatorios de UI/assets/runtime).
- **No declares una fase cerrada ni el plan completo terminado** hasta completar los ítems
  obligatorios de la sección 3 (rebuild de assets, verificación visual en navegador,
  compatibilidad de versiones, fallo claro en runtime, prueba contra el servicio real).
  Si no pudiste hacer alguno, decláralo explícitamente como pendiente con su motivo, en vez
  de asumir que la fase está lista.
