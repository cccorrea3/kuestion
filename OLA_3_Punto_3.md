# Preguntas sugeridas — Definición fina

**Ola 3, punto 3 · Documento de definición**
*Septiembre 2026 · Documento de entrada para los equipos de QuBeKa y Kuestion*

---

## 0. Qué resuelve este documento

La Ola 1 y la Ola 2 construyeron un sistema que responde bien cuando el usuario ya sabe qué preguntar. Pero el usuario nuevo —o el usuario que vuelve después de un tiempo— llega a Kuestion y ve un campo de texto vacío. Eso es una barrera de adopción real: sin un punto de partida, muchos usuarios no hacen su primera pregunta, o hacen una pregunta genérica que no aprovecha lo que el sistema ya sabe.

Este documento define **preguntas sugeridas**: el mecanismo por el cual Kuestion propone al usuario preguntas relevantes basadas en lo que ya existe en su propio grafo. No es un sistema de recomendación, no es perfilado de usuario, no es comparación con otros usuarios. Es una extensión simple del sistema que ya existe para responder, aplicada a la dirección opuesta: en lugar de "el usuario pregunta, el sistema responde", es "el sistema sugiere, el usuario decide si pregunta".

### Lo que cambia respecto al estado actual

| Aspecto | Estado actual | Después de este punto |
|---|---|---|
| **Pantalla de entrada** | Campo de texto vacío | Campo de texto + sugerencias contextuales |
| **Usuario nuevo** | Sin punto de partida | Recibe sugerencias basadas en su grafo (si tiene) o genéricas (si no) |
| **Usuario que vuelve** | Sin recordatorio de qué quedó abierto | Recibe sugerencias basadas en preguntas abiertas, temas recientes y documentos ingeridos |
| **Detección de huecos de conocimiento** | No existe en QBK | Capacidad nueva a validar antes de comprometerse (ver sección 1.5) |

### Documentos base que este documento asume como cerrados y no reabre

- `Ecosistema_Cambios_Arquitectonicos_Post_Ola2.md` — decisiones firmes de arquitectura.
- `definicion_OLA_3.md` — punto 3, a nivel producto.
- `OLA_1_Punto_3.md` — flujo de "Aportar" y clasificación.
- `OLA_2_Punto_3.md` — indicador de vigencia visible (referencia para cómo mostrar metadata contextual sin exponer estructura).

---

## 1. Alcance funcional

### 1.1 Flujo completo (usuario)

1. El usuario entra a Kuestion.
2. Debajo del campo de texto, ve una sección de **"Quizás te interese preguntar"** con 3-5 sugerencias.
3. Cada sugerencia es una pregunta en lenguaje natural, clickeable, que:
   - Si el usuario hace clic, precarga la pregunta en el campo de texto (no la ejecuta automáticamente).
   - El usuario puede editarla antes de enviarla.
   - El usuario puede hacer clic en una sugerencia y enviarla, o ignorarlas y escribir su propia pregunta.
4. Las sugerencias se refrescan:
   - Cuando el usuario envía una pregunta (para reflejar la nueva actividad).
   - Cuando el usuario regresa a la pantalla después de un tiempo (para reflejar cambios en el grafo).
   - **No en tiempo real** (no hay razón para actualizar mientras el usuario mira la pantalla sin interactuar).

### 1.2 De dónde salen las sugerencias

**Decisión propuesta para v1:** las sugerencias se generan a partir de cuatro fuentes, en este orden de prioridad:

| Prioridad | Fuente | Ejemplo de sugerencia |
|---|---|---|
| 1 | Preguntas abiertas del usuario (Q/SQ existentes en su grafo) | *"¿Por qué falla el job de conciliación los lunes?"* |
| 2 | Documentos ingeridos recientemente (últimos 30 días) | *"¿Qué dice el informe de onboarding sobre la retención?"* |
| 3 | Temas consultados frecuentemente (últimas 20 preguntas del usuario) | *"¿Cuál es el estado actual de la API de pagos?"* |
| 4 | Sugerencias genéricas (solo si el usuario no tiene grafo propio aún) | *"¿Qué decisiones tomamos este mes?"*, *"¿Qué cambió desde la última vez que consulté?"* |

**Decisión cerrada — criterio de "pregunta abierta":** ninguna Q/SQ se considera cerrada automáticamente por el sistema, en ningún caso. El sistema no decide que una pregunta dejó de ser relevante porque tiene una N-K asociada, porque su N-K fue rechazada, o porque tiene tiempo sin actividad. Toda Q/SQ existente en el grafo del usuario es una candidata válida para ser sugerida. Si en el futuro se necesita un mecanismo de cierre explícito (por ejemplo, que el usuario marque manualmente una pregunta como "ya no me interesa"), será una decisión de producto en una ola posterior. Mientras tanto, la fuente #1 incluye todas las Q/SQ del grafo del usuario.

**Decisión cerrada — diversidad de fuentes:** la prioridad por orden no se aplica de forma estricta (no se agotan las preguntas abiertas antes de pasar a documentos). El mecanismo es de **diversidad garantizada**:

- Se toma la mejor sugerencia de cada fuente disponible, hasta un máximo de una por fuente.
- Si quedan slots libres (menos de 5 sugerencias), se completan con las siguientes mejores de cualquier fuente, respetando el orden de prioridad.
- Si una fuente no tiene candidatas (por ejemplo, no hay documentos ingeridos en los últimos 30 días), se salta sin afectar el resto.
- **El resultado final nunca tiene más de 2 sugerencias de la misma fuente**, para evitar que un usuario con muchas preguntas abiertas vea solo sugerencias de ese tipo.

Este mecanismo evita el problema de "40 preguntas abiertas → 5 sugerencias todas de la misma fuente" y garantiza representación de las fuentes que tengan contenido útil.

> *Mark como propuesta:* el umbral "máximo 2 por fuente" y el resto del mecanismo quedan a validar con uso real. Pueden ajustarse sin cambiar el concepto.

### 1.3 Cómo se generan las sugerencias

**Decisión propuesta para v1:** las sugerencias se generan **sin LLM**, a partir de consultas directas al grafo de QBK. Esto es importante porque:

- Es rápido (no hay latencia de generación).
- Es predecible (mismas condiciones, mismas sugerencias).
- Es barato (no consume tokens de IA).
- No requiere capacidad nueva del pipeline de clasificación.

**Mecanismo propuesto:**

1. Kuestion consulta a QBK por: Q/SQ del grafo del usuario, documentos ingeridos en los últimos 30 días y temas más consultados en las últimas 20 preguntas del usuario.
2. QBK devuelve una lista de nodos candidatos.
3. Kuestion convierte cada nodo en una pregunta en lenguaje natural usando una plantilla simple (ej: un nodo Q se convierte directamente en pregunta; un documento se convierte en *"¿Qué dice [documento] sobre [tema]?"*).
4. Se aplica el mecanismo de diversidad de fuentes (sección 1.2).
5. Se seleccionan las 3-5 más relevantes según prioridad y diversidad.
6. Se muestran al usuario.

> *Mark como propuesta:* el mecanismo exacto de selección y las plantillas quedan a confirmar por los equipos técnicos.

### 1.4 Refresco de sugerencias

Las sugerencias no son estáticas, pero tampoco necesitan ser en tiempo real.

**Decisión propuesta para v1:**

- Las sugerencias se calculan al cargar la pantalla de entrada.
- Se cachean por **10 minutos por usuario y por sesión de navegador**. Es decir: el cache es válido mientras el usuario mantiene su sesión activa; si cierra sesión y vuelve, las sugerencias se recalculan.
- Si el usuario envía una pregunta, las sugerencias se recalculan inmediatamente después (para reflejar la nueva actividad).
- No hay WebSockets ni push. Si el usuario no navega, no hay refresh.

> *Mark como propuesta:* el tiempo de cache es tentativo. Puede ser 5 minutos o 30; depende de cuánto cueste el cálculo.

### 1.5 Detección de huecos de conocimiento — decisión cerrada

**Decisión cerrada acá** (era decisión abierta #3 en `definicion_OLA_3.md`, sección 7):

La detección de huecos de conocimiento **no está en el alcance de esta versión**, por tres razones:

1. QBK no tiene esa capacidad hoy. La detección de huecos vive en Kuaforia (`KnowledgeGapService`), no en QBK. Construirla en QBK es una capacidad nueva, no un ajuste.
2. El caso de uso principal no la necesita. Las preguntas sugeridas resuelven el problema del usuario nuevo. El usuario nuevo no tiene "huecos de conocimiento" porque no tiene grafo todavía. Los huecos son un problema de usuario avanzado, no de onboarding.
3. Es la misma trampa que ya evitamos con explicabilidad. En Ola 2, la explicabilidad se comprometió como capacidad nueva con validación de viabilidad primero, no como asunción. Acá aplica el mismo criterio.

Lo que sí se hace en esta versión: las sugerencias se basan en las cuatro fuentes de la sección 1.2, todas ellas basadas en datos que ya existen en el grafo (Q/SQ del usuario, documentos ingeridos, temas consultados). Ninguna de las cuatro requiere detección automática de huecos.

Lo que queda como decisión abierta para el futuro: si la detección de huecos es viable en QBK (validación técnica requerida), y si es valiosa para el usuario una vez que la ola esté en producción. Se deja anotado como capacidad futura, no como bloqueante.

**Decisión cerrada acá** (era decisión abierta #5 en `definicion_OLA_3.md`, sección 7): las preguntas sugeridas se basan **exclusivamente en el grafo propio del usuario**. No se comparan con otros usuarios, no se usan patrones agregados de la plataforma, no se perfilan usuarios. Esto no es una limitación temporal; es una decisión de producto que se mantiene hasta que haya evidencia clara de que el valor de sugerir con datos cross-usuario supera el costo de complejidad y privacidad.

### 1.6 UI — dónde y cómo se muestran

**Decisión propuesta para v1:**

- Las sugerencias se muestran debajo del campo de texto, en la pantalla de entrada.
- Formato: lista vertical de 3-5 items, cada uno con la pregunta en texto claro.
- Al hacer clic, la pregunta se precarga en el campo de texto (no se ejecuta).
- El usuario puede editar la pregunta antes de enviarla.
- No hay "descartar sugerencia" ni "no me interesa". Si el usuario no hace clic, se ignora. Las sugerencias se refrescan solas con el tiempo.
- No hay sección de "historial de sugerencias descartadas".

> *Mark como propuesta:* el diseño visual exacto (colores, íconos, disposición) queda a confirmar por el equipo de Kuestion.

### 1.7 Comportamiento para usuario sin grafo

**Decisión propuesta para v1:** si el usuario es nuevo (no tiene grafo en QBK, o su grafo está vacío), las sugerencias se generan a partir de:

- Preguntas genéricas (*"¿Qué decisiones tomamos este mes?"*, *"¿Qué cambió desde la última vez que consulté?"*, *"¿Qué documentos tengo cargados?"*).
- Estas preguntas no van a tener respuesta útil inmediata, pero sirven para que el usuario vea el mecanismo y entienda cómo funciona.
- A medida que el usuario consulta, aporta o ingiere documentos, las sugerencias se vuelven específicas a su grafo.

> *Mark como propuesta:* el catálogo de preguntas genéricas queda a confirmar. Puede ser fijo (una lista predefinida) o generado a partir de templates.

---

## 2. Qué NO debe hacer (en esta versión)

- **No perfilado de usuario.** No hay comparación con otros usuarios, ni uso de datos cross-usuario para sugerir.
- **No detección automática de huecos de conocimiento.** No está en el alcance (ver sección 1.5).
- **No cierre automático de preguntas.** Ninguna Q/SQ se considera cerrada por el sistema. Todas son candidatas (ver sección 1.2).
- **No sugerencias en tiempo real.** Se calculan al cargar la pantalla, con cache de 10 minutos por sesión.
- **No historial de sugerencias descartadas.** No hay memoria de qué ignoró el usuario.
- **No auto-ejecución.** Las sugerencias nunca se ejecutan solas; el usuario siempre decide si las envía.
- **No edición de la sugerencia antes de precargarla.** El usuario puede editar la pregunta una vez precargada, pero no modificar la sugerencia en sí.
- **No sugerencias basadas en el contenido de otros repos.** Si el usuario tiene Kuaforia conectado, sus sugerencias no se basan en el contenido de Kuaforia (Kuaforia es fuente de consulta, no de estructura).
- **No sugerencias basadas en documentos que no fueron ingeridos.** Si el usuario tiene archivos en su computadora pero nunca los subió, no se sugieren preguntas sobre ellos.
- **No sugerencias basadas en temas "trending" de la plataforma.** Cada usuario ve solo su grafo.

---

## 3. Contrato entre Kuestion y QuBeKa

> *Mark como propuesta:* los nombres exactos de endpoints, campos y estructuras son propuestas de producto. Los equipos técnicos los confirmarán o ajustarán con evidencia de código real.

### 3.1 Request (Kuestion → QuBeKa)

```http
GET {QUBKA_API_URL}/suggestions
Authorization: Bearer {token}
```

**Query params:**
- `limit`: 5 (opcional, default 5)

No se necesitan más parámetros porque el token ya resuelve el workspace, y las sugerencias se basan en el grafo de ese workspace.

### 3.2 Response (Kuestion ← QuBeKa)

```json
{
  "suggestions": [
    {
      "id": "sug-001",
      "texto": "¿Por qué falla el job de conciliación los lunes?",
      "fuente": "pregunta_abierta",
      "nodo_origen_id": "Q-0042"
    },
    {
      "id": "sug-002",
      "texto": "¿Qué dice el informe de onboarding sobre la retención?",
      "fuente": "documento_reciente",
      "nodo_origen_id": "doc-2026-09-01-001"
    },
    {
      "id": "sug-003",
      "texto": "¿Cuál es el estado actual de la API de pagos?",
      "fuente": "tema_frecuente",
      "nodo_origen_id": "Q-0018"
    }
  ]
}
```

> *Mark como propuesta:* la estructura exacta del response (nombres de campos, valores de fuente) queda a confirmar por el equipo de QBK. El valor `pregunta_abierta` reemplaza al anterior `pregunta_sin_cerrar` para reflejar la decisión de que ninguna pregunta se considera cerrada.

### 3.3 Comportamiento cuando el grafo está vacío

Si el workspace no tiene suficiente información para generar sugerencias (usuario nuevo, grafo vacío), QBK devuelve:

```json
{
  "suggestions": []
}
```

Kuestion, al recibir una lista vacía, muestra un catálogo de sugerencias genéricas definidas en Kuestion, no en QBK. Esto mantiene la separación: QBK sugiere sobre su propio contenido; Kuestion sugiere sobre contenido genérico cuando no hay contenido específico.

> *Mark como propuesta:* la lógica exacta de "cuándo QBK devuelve vacío vs cuándo devuelve sugerencias genéricas" queda a confirmar.

---

## 4. Operacional

| Aspecto | Definición |
|---|---|
| **Sincronía** | La consulta de sugerencias es síncrona, pero no bloquea la carga de la pantalla. La pantalla se muestra primero con el campo de texto, y las sugerencias aparecen cuando llegan (típicamente 1-2 segundos). |
| **Timeout** | 5 segundos. Si QBK no responde a tiempo, la pantalla se muestra sin sugerencias (no hay error visible). |
| **Cache** | 10 minutos por usuario y por sesión de navegador. Si el usuario envía una pregunta, el cache se invalida para ese usuario. Si cierra sesión y vuelve, las sugerencias se recalculan. |
| **Rate limiting** | El endpoint se llama una vez por carga de pantalla. No hay riesgo de saturación. |
| **Manejo de error** | Si QBK falla o devuelve error, la pantalla se muestra sin sugerencias. No se muestra error al usuario (la ausencia de sugerencias no es un fallo, es un estado válido). |
| **Fallback para usuario nuevo** | Si QBK devuelve lista vacía (grafo insuficiente), Kuestion usa un catálogo local de sugerencias genéricas. |
| **Persistencia** | Las sugerencias no se persisten. Se calculan al vuelo, se cachean temporalmente y se descartan. No hay historial. |

---

## 5. Qué se le pide a cada equipo

### A QuBeKa

1. Confirmar viabilidad de generar sugerencias sin LLM. El mecanismo propuesto (sección 1.3) se basa en consultas directas al grafo, sin generación de lenguaje natural. Confirmar si esto es viable con el modelo de datos actual o si requiere algún ajuste.
2. Definir la lógica de selección de sugerencias, incluyendo el mecanismo de diversidad de fuentes (sección 1.2). Cómo se priorizan las cuatro fuentes, cómo se aplica el límite de "máximo 2 por fuente", y cómo se evita la repetición de temas.
3. Confirmar el criterio de "pregunta abierta" (sección 1.2): toda Q/SQ existente en el grafo es candidata, sin cierre automático por parte del sistema. Identificar si esto genera algún problema técnico (por ejemplo, si hay Q/SQ archivadas que no deberían sugerirse).
4. Exponer el endpoint `GET /suggestions` con la estructura de la sección 3.2. La estructura exacta queda a definir por el equipo técnico, pero debe incluir al menos: texto de la sugerencia, fuente de origen e identificador del nodo de origen.
5. Definir el comportamiento cuando el grafo está vacío. Confirmar si QBK devuelve lista vacía o si genera sugerencias genéricas desde su lado (ver sección 3.3).

### A Kuestion

1. Construir la UI de sugerencias en la pantalla de entrada, debajo del campo de texto. Lista de 3-5 items, clickeables, que precargan la pregunta en el campo.
2. Implementar la consulta al endpoint de QBK con timeout de 5 segundos y manejo de error silencioso (sin sugerencias si falla).
3. Implementar el cache de 10 minutos por usuario y por sesión de navegador, invalidado cuando el usuario envía una pregunta y descartado al cerrar sesión.
4. Definir el catálogo de sugerencias genéricas para usuarios sin grafo suficiente (sección 1.7).
5. Definir el diseño visual de la sección de sugerencias: dónde va, cómo se ve, cómo se comporta cuando hay 0, 3 o 5 items.
6. **No construir la lógica de generación de sugerencias.** Esa vive en QBK. Kuestion solo consume y muestra.

---

## 6. Decisiones abiertas

Este punto tenía una decisión abierta asignada en `definicion_OLA_3.md`, sección 7 (#3 sobre detección de huecos de conocimiento), que quedó cerrada en este documento (sección 1.5). También cerraba la #5 sobre perfilado de usuario (sección 1.5). La decisión sobre "pregunta sin cerrar" quedó cerrada en la sección 1.2 con el criterio de "ninguna pregunta se cierra automáticamente".

Las únicas decisiones que quedan genuinamente abiertas para este punto son:

| # | Decisión | Propuesta a evaluar |
|---|---|---|
| 1 | Ajuste fino del mecanismo de diversidad de fuentes (¿máximo 2 por fuente es el valor correcto?) | Propuesta tentativa: máximo 2 por fuente, con completado por prioridad. Requiere validación con datos reales. |
| 2 | ¿QBK devuelve sugerencias genéricas o Kuestion las tiene hardcodeadas? | Propuesta tentativa: Kuestion las tiene hardcodeadas cuando QBK devuelve vacío. Requiere confirmación. |
| 3 | Tiempo de cache (10 minutos). ¿Es el valor correcto? | Propuesta tentativa: 10 minutos por sesión. Puede ajustarse según el costo real del cálculo. |
| 4 | ¿Qué pasa si el usuario nunca hace clic en ninguna sugerencia? ¿Se sigue mostrando el mismo catálogo? | Propuesta tentativa: sí, se siguen mostrando. No hay penalización por ignorar. Requiere validación con uso real. |
| 5 | ¿Las sugerencias se muestran también en el feed, o solo en la pantalla de entrada? | Propuesta tentativa: solo en la pantalla de entrada. El feed es para contenido ya existente, no para sugerencias. |
| 6 | ¿Existe algún mecanismo futuro de "cerrar pregunta" (manual por el usuario o automático por el sistema)? | No en esta versión. Si en uso real se detecta que las sugerencias se vuelven repetitivas o irrelevantes por falta de cierre, se evalúa en una ola posterior. |

---

*Documento de definición fina del punto 3 de la Ola 3. Las decisiones firmes de `Ecosistema_Cambios_Arquitectonicos_Post_Ola2.md` son base no negociable. Las decisiones #3 y #5 de `definicion_OLA_3.md` sección 7 quedan cerradas por este documento. El criterio de "pregunta abierta" y el mecanismo de diversidad de fuentes quedan cerrados en la sección 1.2. La detección de huecos de conocimiento queda explícitamente fuera de alcance de esta versión, con validación de viabilidad pendiente para una ola posterior. Los detalles técnicos del contrato y de la implementación quedan a confirmar por los equipos de QuBeKa y Kuestion.*