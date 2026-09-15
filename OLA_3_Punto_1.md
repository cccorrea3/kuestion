**Documentos base que este documento asume como cerrados y no reabre:**
- `Ecosistema_Cambios_Arquitectonicos_Post_Ola2.md` — decisiones firmes de arquitectura.
- `definicion_OLA_3.md` — qué es este punto a nivel producto.
- `OLA_1_Punto_3.md` — flujo de "Aportar" original.
- `OLA_2_Punto_1.md` — bandeja de revisión rápida.
- `OLA_2_Punto_4.md` — explicabilidad (metadatos `alternatives_considered`).

---

## 1. Alcance funcional

### 1.1 Flujo completo (usuario)

1. El usuario entra a Kuestion y ve el campo de entrada de siempre (dos botones: **Preguntar** y **Aportar**), más un tercer botón nuevo: **Subir documento**.
2. Al hacer clic en "Subir documento", se abre un selector de archivo y un campo opcional de contexto ("¿De qué trata este documento?").
3. El usuario selecciona el archivo y confirma.
4. Kuestion valida el archivo (formato soportado, tamaño bajo el límite) y muestra: *"Procesando documento... esto puede tomar unos minutos."*
5. Kuestion sube el archivo, extrae el texto, y lo envía al servicio de clasificación de QBK (mismo pipeline que "Aportar", extendido).
6. Cuando QBK termina el análisis, Kuestion muestra: *"Listo. Se propusieron 32 nodos a partir de este documento. [Revisar]"*
7. Al hacer clic en "Revisar", el usuario va a la bandeja de revisión (Ola 2, punto 1) con el documento como ítem.
8. El usuario revisa el resumen del documento (cantidad de nodos por tipo, contradicciones detectadas si las hay), y puede:
   - **Aprobar todo:** todos los nodos propuestos entran al grafo activo.
   - **Deseleccionar algunos:** el usuario marca individualmente los nodos que no quiere incluir, y aprueba el resto.
   - **Rechazar todo:** la sesión se descarta completa.
9. Los nodos aprobados se promueven al grafo activo. Los deseleccionados o rechazados se descartan.

### 1.2 Extracción de texto

**Decisión propuesta:** Kuestion maneja la extracción de texto, no QBK. Los formatos soportados en v1:

| Formato | Método de extracción |
|---|---|
| **TXT / MD** | Lectura directa. |
| **PDF** | Extracción de texto con preservación de saltos de página (para trazabilidad de origen). |
| **DOCX** | Extracción de texto con preservación de headings (para estructura). |

> *Mark como propuesta:* los detalles de librería y método de extracción quedan a confirmar por el equipo de Kuestion.

### 1.3 Chunking

Un documento de 20 páginas no entra en un solo chunk de análisis. Kuestion divide el texto en chunks antes de enviarlo a QBK.

**Decisión propuesta para v1:** chunking por tamaño fijo con overlap.
- **Tamaño objetivo:** ~3000 caracteres por chunk.
- **Overlap:** ~300 caracteres entre chunks consecutivos (evita cortar ideas a la mitad).
- **Preservación de metadata:** cada chunk sabe de qué página(s) viene, y si empieza al inicio de una sección con heading, lo preserva.

> *Mark como propuesta:* la estrategia exacta de chunking (por tamaño, por heading, por párrafo) queda a confirmar por el equipo de QBK, dado que es su pipeline el que la procesa.

### 1.4 Clasificación

Cada chunk pasa por el mismo pipeline de clasificación que un aporte corto (Ola 1, punto 3, sección 2). El clasificador de QBK:

1. Analiza el chunk.
2. Propone nodos Q/SQ/H/N-K/N-A.
3. Para cada nodo propuesto, decide si se vincula a algo existente o si queda suelto.
4. Incluye los metadatos de explicabilidad ya definidos en Ola 2, punto 4 (`decision_type`, `confidence`, `reasons`, `alternatives_considered`, `detected_patterns`).
5. Aplica el mecanismo de sugerencia de vinculación ya cerrado en el documento de arquitectura (sección 4): busca candidatos y los incluye en `alternatives_considered`, nunca vincula automáticamente.
6. **Nuevo respecto al aporte corto:** detecta contradicciones con nodos ya validados en el grafo activo. Ver sección 1.5.

**Extensión obligatoria del mecanismo de vinculación (decisión cerrada acá):** el clasificador debe aplicar el mecanismo de sugerencia de vinculación **contra dos universos de comparación**, no solo uno:

- **Universo 1 — grafo activo:** contenido ya validado de sesiones anteriores. Es el comportamiento ya definido en el documento de arquitectura (sección 4).
- **Universo 2 — nodos propuestos en la misma sesión:** los nodos que el propio documento ya propuso en chunks anteriores del mismo análisis.

**Por qué es obligatorio:** un documento real tiene ideas repetidas entre secciones — es literalmente la razón por la que el chunking usa overlap, para no cortar una idea a la mitad. Si el chunk 3 y el chunk 15 del mismo documento tocan la misma idea con palabras distintas, el clasificador los procesa de forma independiente y puede proponer dos nodos casi idénticos. Sin esta extensión, el usuario terminaría revisando un lote de 32 nodos donde varios son duplicados internos del mismo documento — exactamente el resultado inconsistente que la prueba #1 de la Ola 3 ("dos documentos parecidos producen estructuras parecidas, no resultados arbitrarios") está pensada para detectar como fallo.

**Cómo se implementa:** no es un mecanismo nuevo; es el mismo mecanismo de `alternatives_considered` aplicado a un segundo universo. Cada nodo propuesto compara contra el grafo activo y contra los nodos ya propuestos en la sesión, y en ambos casos incluye candidatos cercanos como sugerencia, nunca como vinculación automática.

> *Mark como propuesta:* la mecánica exacta de comparación intra-sesión (cómo se decide qué es "cercano" dentro de la sesión, en qué momento del pipeline se hace la comparación) queda a confirmar por el equipo de QBK.

### 1.5 Detección de contradicciones

Distinto del mecanismo de vinculación (que resuelve duplicación, tanto contra el grafo activo como intra-sesión), la detección de contradicciones resuelve un caso nuevo: cuando el contenido del documento **dice algo opuesto** a un nodo ya validado en el grafo.

**Decisión propuesta para v1:**
- El clasificador detecta contradicciones durante el análisis, contra el grafo activo (no intra-sesión — dos nodos del mismo documento que se contradicen entre sí es un caso más raro y más complejo, fuera de alcance de esta versión).
- Cada contradicción se registra como metadata de la sesión (no de un nodo individual), con: el nodo nuevo propuesto, el nodo existente con el que contradice, y una breve descripción de la contradicción.
- **No bloquea la aprobación.** El usuario puede aprobar el documento completo aunque haya contradicciones, porque puede tener razones legítimas para hacerlo (el conocimiento nuevo puede ser correcto y el viejo obsoleto).
- En la bandeja de revisión, las contradicciones se muestran como advertencias destacadas, no como errores. El usuario ve: *"Este documento contradice 2 nodos existentes. [Ver detalles]"*.

> *Mark como propuesta:* la detección automática de contradicciones es una capacidad nueva de QBK. Requiere validación técnica antes de comprometerse (ver sección 5, QuBeKa punto 3).

### 1.6 Trazabilidad de origen

**Decisión cerrada en el documento de arquitectura (sección 1):** la trazabilidad de origen vive en QBK, no se reconstruye en Kuestion.

**Decisión propuesta para v1:**
- Cada nodo generado desde un documento lleva metadata interna: `documento_origen_id`, `pagina_origen`, `chunk_origen_id`.
- Esa metadata **no se expone al usuario en v1**. Es solo para reconstrucción interna si se necesita.
- La decisión de exponerla (ej: "esta respuesta viene del documento X, página 3") se deja para una ola posterior.

> *Mark como propuesta:* la estructura exacta de la metadata queda a confirmar por el equipo de QBK, dado que es su modelo de datos el que la almacena.

### 1.7 Revisión en la bandeja

La bandeja de revisión de Ola 2, punto 1, se extiende para soportar documentos:

- Un documento aparece como **un solo ítem** en la bandeja, no como N ítems separados.
- El ítem muestra: nombre del documento, fecha de carga, cantidad de nodos propuestos por tipo (ej: "32 nodos: 4 Q, 6 SQ, 12 H, 10 N-K"), y advertencias si hay contradicciones.
- Al expandir el ítem, el usuario ve la lista de nodos propuestos agrupados por tipo, cada uno con su texto y su indicador de confianza (de los metadatos de explicabilidad de Ola 2, punto 4).
- Cada nodo tiene un checkbox que permite deseleccionarlo. Por defecto, todos están seleccionados.
- Botón **"Aprobar seleccionados"** y botón **"Rechazar todo"**.

**Decisión cerrada acá (era decisión abierta #1 en `definicion_OLA_3.md`):** la revisión es **por lote a nivel de documento**, con deselección individual opcional. No es por nodo individual (sería inviable para 30 nodos), ni es aprobación ciega del lote completo (daría al usuario menos control del que necesita cuando el clasificador comete errores). El punto medio — lote por defecto, deselección disponible — resuelve ambos extremos.

> **Tensión reconocida (no resuelta en este documento):** "Aprobar seleccionados" con 32 nodos preseleccionados por defecto es, en la práctica, un botón de aprobación masiva con un clic. Es coherente con la decisión de lote con deselección opcional, pero choca con la prueba #4 que la Ola 3 se propuso pasar: si la revisión se vuelve carga, la promesa se rompe. El riesgo real no es que la revisión sea una carga — es lo contrario: que nadie la lea de verdad y el gate humano se vuelva ceremonial. Esta tensión se registra como decisión abierta (ver sección 6, decisión #5) para que se mida con uso real, no se resuelva por adelantado.

**Decisión cerrada acá (era decisión abierta #4 en `definicion_OLA_3.md`):** el usuario **no edita la estructura** de los nodos propuestos en esta versión. Puede aprobar, deseleccionar o rechazar, pero no modificar el texto, el tipo, ni las relaciones de un nodo. La edición estructural requiere una UI de complejidad comparable a la de QBK, lo cual contradice la decisión de que el usuario nunca navegue QBK. Si el usuario necesita cambiar algo, puede rechazar el documento y volver a cargarlo, o usar "Aportar" para piezas específicas.

### 1.8 Contradicciones — decisión cerrada

**Decisión cerrada acá (era decisión abierta #2 en `definicion_OLA_3.md`):** las contradicciones se **detectan, se registran como metadata de la sesión, y se muestran al usuario durante la revisión como advertencias**. No bloquean la aprobación. La política completa de qué hacer con contradicciones después de aprobadas (¿se marcan en el grafo? ¿disparan alertas?) queda fuera de alcance de este punto y se define en una ola posterior.

---

## 2. Qué NO debe hacer (en esta versión)

- **No exponer el grafo al usuario.** El usuario ve la lista de nodos propuestos, no el grafo ni las relaciones entre ellos.
- **No permitir edición estructural.** El usuario aprueba, deselecciona o rechaza, pero no modifica nodos.
- **No promoción automática.** Ningún nodo entra al grafo activo sin aprobación humana explícita. El gate humano nunca se salta.
- **No fusión de nodos duplicados.** El mecanismo de vinculación sugiere candidatos; la fusión (unir dos nodos en uno) no existe en esta versión.
- **No revisión nodo por nodo obligatoria.** El flujo por defecto es por lote con deselección opcional.
- **No exposición de trazabilidad de origen.** La metadata existe internamente, pero no se muestra al usuario en v1.
- **No soporte de imágenes, audio, video.** Solo texto plano, PDF y DOCX.
- **No OCR.** Si un PDF es escaneado (imagen sin texto), no se procesa.
- **No multi-documento en una sola sesión.** Un documento = una sesión. Subir 5 PDFs = 5 sesiones.
- **No sincronización con el documento original.** Si el documento cambia en el origen (el usuario lo edita), el sistema no lo detecta. Eso es una capacidad futura (ver documento de arquitectura, sección 3, "Fuente externa").
- **No detección de contradicciones intra-sesión.** Dos nodos del mismo documento que se contradicen entre sí es un caso fuera de alcance de esta versión.

---

## 3. Contrato entre Kuestion y QuBeKa

> *Mark como propuesta:* los nombres exactos de endpoints, campos y estructuras son propuestas de producto. Los equipos técnicos los confirmarán o ajustarán con evidencia de código real, igual que en Olas anteriores.

### 3.1 Request (Kuestion → QuBeKa)

```http
POST {QUBKA_API_URL}/contribute/document
Authorization: Bearer {token}
Content-Type: application/json
```

```json
{
  "documento_nombre": "informe-onboarding-q3.pdf",
  "chunks": [
    {
      "chunk_id": "chunk-001",
      "texto": "El abandono en el paso 3 del onboarding aumentó un 12% en el último trimestre...",
      "pagina_origen": 3,
      "orden": 1
    },
    {
      "chunk_id": "chunk-002",
      "texto": "...",
      "pagina_origen": 4,
      "orden": 2
    }
  ],
  "origen": "kuestion",
  "contexto_opcional": "Análisis trimestral del equipo de producto"
}
```

> *Propuesta:* Kuestion envía los chunks ya extraídos, no el archivo binario. Esto mantiene el endpoint de QBK enfocado en clasificación, no en extracción.

### 3.2 Response inmediata (Kuestion ← QuBeKa)

```json
{
  "session_id": "ses-2026-09-13-0042",
  "status": "processing",
  "documento_nombre": "informe-onboarding-q3.pdf",
  "chunks_recibidos": 8
}
```

> *Propuesta:* la respuesta es inmediata con `status: processing`. El análisis se hace de forma asíncrona.

### 3.3 Polling de estado (Kuestion → QuBeKa)

**Decisión cerrada acá (corrección de naming):** el polling reutiliza el endpoint de detalle de sesión ya existente desde Ola 2, punto 1: `GET /api/v1/sesiones-analisis/{id}`. No se crea un endpoint nuevo ni en inglés ni con un path distinto. La razón es mantener la consistencia con el contrato existente, que usa español y ya tiene un endpoint que devuelve estado y metadatos de la sesión.

El endpoint existente se extiende para que, cuando la sesión corresponda a un documento en procesamiento, incluya dos campos adicionales:

```json
{
  "session_id": "ses-2026-09-13-0042",
  "status": "processing",
  "chunks_procesados": 3,
  "chunks_totales": 8
}
```

Cuando el análisis termina:

```json
{
  "session_id": "ses-2026-09-13-0042",
  "status": "pending_review",
  "resumen": "Se propusieron 32 nodos: 4 preguntas, 6 sub-preguntas, 12 hipótesis, 10 notas de conocimiento.",
  "contradicciones": [
    {
      "nodo_nuevo_propuesto": "H: El campo de teléfono causa fricción",
      "nodo_existente": "NK-0451",
      "descripcion": "El nodo existente afirma que el campo de teléfono no afecta la conversión."
    }
  ]
}
```

> *Mark como propuesta:* los campos `chunks_procesados` y `chunks_totales` son extensiones propuestas al endpoint existente. La estructura exacta de contradicciones queda a confirmar por el equipo de QBK.

### 3.4 Aprobación (Kuestion → QuBeKa)

Reutiliza el mismo mecanismo de aprobación que ya existe para sesiones de aporte corto (Ola 2, punto 1). Extensión para soportar deselección:

```json
{
  "session_id": "ses-2026-09-13-0042",
  "nodos_aprobados": ["nodo-prop-001", "nodo-prop-003", "nodo-prop-004"],
  "nodos_rechazados": ["nodo-prop-002"]
}
```

> *Mark como propuesta:* el nombre exacto del endpoint y la estructura de la aprobación se confirmarán con el equipo de QBK. El endpoint existente puede extenderse sin crear uno nuevo, siguiendo el mismo criterio que la sección 3.3.

---

## 4. Operacional

| Aspecto | Definición |
|---|---|
| **Tamaño máximo de archivo** | Propuesta: 20 MB. A confirmar con Kuestion. |
| **Cantidad máxima de páginas** | Propuesta: 100 páginas. A confirmar con Kuestion. |
| **Formatos soportados** | TXT, MD, PDF, DOCX. |
| **Sincronía** | El análisis es asíncrono. La carga responde inmediatamente con `session_id`, y Kuestion hace polling cada 5 segundos mientras `status = processing`. |
| **Timeout de análisis** | Propuesta: 10 minutos máximo por documento. Si excede, la sesión falla con error controlado. |
| **Reintentos** | Si el análisis falla por error de red, Kuestion reintenta el envío hasta 3 veces con backoff exponencial. Si falla por error del LLM, se marca como fallido y se notifica al usuario. |
| **Deduplicación de cargas** | Si el usuario sube el mismo archivo dos veces en un período corto, el sistema detecta el hash y advierte antes de procesar. |
| **Retención de sesiones fallidas** | Propuesta: las sesiones fallidas se retienen por 7 días antes de descartarse. |
| **Persistencia de chunks** | Los chunks extraídos por Kuestion se retienen solo durante el procesamiento. Una vez que QBK clasifica, los chunks se descartan (el conocimiento quedó estructurado en QBK). |

---

## 5. Qué se le pide a cada equipo

### A QuBeKa

1. Confirmar viabilidad del modelo de datos actual para documentos. El pipeline de clasificación actual está pensado para chunks individuales. Confirmar si soporta bien el flujo de "N chunks → 1 sesión → M nodos propuestos" con la metadata de origen, o si requiere ajuste de diseño.
2. Extender `AnalisisService` (o su equivalente actual) para aceptar múltiples chunks en una sola sesión, manteniendo la agrupación y la metadata de origen por nodo propuesto.
3. Confirmar si la detección de contradicciones es viable con el estado actual del pipeline, o si requiere una capacidad nueva. Si requiere capacidad nueva, proponer que la primera fase del plan sea de validación de viabilidad, no de construcción directa (mismo criterio que se usó con la explicabilidad en Ola 2).
4. Extender el mecanismo de sugerencia de vinculación para que compare contra dos universos: el grafo activo (comportamiento ya existente) y los nodos propuestos en la misma sesión (extensión nueva). Confirmar viabilidad técnica y mecánica de comparación intra-sesión.
5. Extender el endpoint de aprobación de sesiones para soportar deselección individual de nodos propuestos (aprobar un subconjunto, rechazar el resto). Reutilizar el endpoint existente, no crear uno nuevo.
6. Extender el endpoint de detalle de sesión (`GET /api/v1/sesiones-analisis/{id}`) para incluir `chunks_procesados` y `chunks_totales` cuando la sesión corresponda a un documento en procesamiento.
7. Definir la estrategia de chunking (por tamaño, por heading, por párrafo) que mejor se adapte al pipeline actual de QBK. Puede ser que la decisión óptima requiera prueba empírica.
8. Definir la estructura de la metadata de trazabilidad de origen en el modelo de datos (`documento_origen_id`, `pagina_origen`, `chunk_origen_id`, o equivalente).

### A Kuestion

1. Construir el botón **"Subir documento"** en la pantalla de entrada, junto a "Preguntar" y "Aportar".
2. Implementar la extracción de texto para PDF, DOCX, TXT, MD, con preservación de metadata de origen (páginas, headings).
3. Implementar el chunking siguiendo la estrategia que defina QBK.
4. Implementar el flujo asíncrono: subir → responder inmediatamente → polling de estado (contra el endpoint de sesión existente) → mostrar resultado cuando esté listo.
5. Extender la bandeja de revisión (Ola 2, punto 1) para soportar el ítem "documento" con sus diferencias respecto al ítem "aporte corto": resumen por tipo, advertencias de contradicción, checkbox por nodo, botón "Aprobar seleccionados".
6. Implementar la vista de contradicciones en la bandeja (advertencia destacada, con opción "Ver detalles").
7. Implementar el manejo de errores: archivo no soportado, archivo muy grande, fallo de análisis, timeout.
8. Considerar (a evaluar) el uso activo del indicador de confianza en la bandeja: por ejemplo, deseleccionar por defecto los nodos con confianza roja, o marcarlos visualmente más destacados. No es indispensable para esta versión; se registra como propuesta a evaluar (ver sección 6, decisión #6).

---

## 6. Decisiones abiertas

Las decisiones abiertas #1, #2, #4 y #5 del documento `definicion_OLA_3.md` (sección 7) quedaron cerradas en este documento:

- **#1 (lote vs. individual)** → cerrada en sección 1.7.
- **#2 (contradicciones)** → cerrada en sección 1.8.
- **#4 (edición estructural)** → cerrada en sección 1.7.
- **#5 (exposición de trazabilidad de origen)** → cerrada en sección 1.6 (metadata interna, no expuesta al usuario en v1).

La decisión abierta #3 (¿QBK puede detectar huecos de conocimiento?) no aplica a este punto; pertenece al punto 3 de la Ola 3.

Las decisiones que quedan genuinamente abiertas para este punto son:

| # | Decisión | Propuesta a evaluar |
|---|---|---|
| 1 | Estrategia exacta de chunking. ¿Por tamaño fijo, por heading, por párrafo? ¿Con qué tamaño objetivo y overlap? | Propuesta tentativa: por tamaño fijo con overlap (3000 chars / 300 overlap). Requiere validación empírica con documentos reales antes de comprometerse. |
| 2 | Formato exacto de los metadatos de contradicción. ¿Cómo se estructuran para que Kuestion los pueda mostrar y QBK los pueda registrar? | Propuesta tentativa en sección 3.3. Requiere confirmación de QuBeKa. |
| 3 | Nombre exacto de endpoints y campos del contrato. | Los propuestos en la sección 3 son tentativos. Los equipos técnicos los ajustan. |
| 4 | ¿Qué pasa si un documento tiene contradicciones y el usuario lo aprueba? ¿Se marcan en el grafo de alguna forma? ¿Disparan algo? | Fuera de alcance de este punto. Se define en una ola posterior. |
| 5 | ¿El gate humano de documentos se vuelve ceremonial? Con 32 nodos preseleccionados por defecto, ¿la gente deselecciona algo o aprueba todo sin mirar? | No se resuelve por adelantado. Requiere medición con uso real: instrumentar cuántos usuarios deseleccionan al menos un nodo, y con qué frecuencia. Si la mayoría aprueba todo sin modificar, hay que revisar el diseño del gate. |
| 6 | ¿Se usa el indicador de confianza para guiar la revisión? Por ejemplo, deseleccionar por defecto los nodos de confianza roja, o marcarlos de forma destacada. | Propuesta a evaluar: preseleccionar solo nodos con confianza verde/amarilla, dejar los rojos deseleccionados por defecto para que el usuario los mire antes de aprobar. Requiere validación de UX antes de comprometer. |

---

*Documento de definición fina del punto 1 de la Ola 3. Las decisiones firmes de `Ecosistema_Cambios_Arquitectonicos_Post_Ola2.md` son base no negociable. Las decisiones #1, #2, #4 y #5 de `definicion_OLA_3.md` sección 7 quedan cerradas por este documento. La extensión del mecanismo de vinculación para comparación intra-sesión (sección 1.4) es una corrección obligatoria identificada antes de pasar a plan de implementación. El polling reutiliza el endpoint existente de sesión (sección 3.3), sin introducir endpoints nuevos ni inconsistencias de naming. Los detalles técnicos del contrato y de la implementación quedan a confirmar por los equipos de QuBeKa y Kuestion.*