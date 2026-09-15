# Plan de Implementación — Ola 3, Punto 1: Subida de Documentos a Kuestion

*Equipo de Kuestion · Septiembre 2026*
*Documento de entrada: `OLA_3_Punto_1.md` (especificación cerrada de producto)*
*Base cerrada y no reabierta: `Ecosistema_Cambios_Arquitectonicos_Post_Ola2.md`, `definicion_OLA_3.md`, `OLA_1_Punto_3.md`, `OLA_2_Punto_1.md`, `OLA_2_Punto_4.md`*

---

## 1. RESUMEN DE ALCANCE

Construimos el flujo "Subir documento": un tercer botón junto a "Preguntar" y "Aportar" que permite cargar TXT/MD/PDF/DOCX, extraer su texto (con metadata de origen: páginas/headings), dividirlo en chunks, enviarlo al pipeline de clasificación de QuBeKa por un endpoint nuevo propuesto (`POST /contribute/document`), hacer polling del estado contra el endpoint de detalle de sesión **existente** (`GET /api/v1/sesiones-analisis/{id}`, decisión cerrada §3.3), y revisar el resultado en la bandeja de revisión de Ola 2 Punto 1 extendida: el documento es **un ítem**, con resumen por tipo, advertencias de contradicciones (no bloqueantes), checkboxes de deselección por nodo, y acciones "Aprobar seleccionados" / "Rechazar todo".

Confirmamos que entendimos correctamente:

- **El gate humano nunca se salta** (§2): nada entra al grafo sin aprobación explícita. La revisión es por lote con deselección opcional (decisión cerrada §1.7); no hay edición estructural.
- **La extracción y el chunking son de Kuestion** (§1.2/§1.3, propuesta); QuBeKa recibe chunks ya extraídos, no el binario (§3.1). La estrategia de chunking final la define QBK (su tarea 7) — construimos con la propuesta (3000/300 overlap) marcándola como provisional.
- **La trazabilidad de origen vive en QBK** (§1.6): Kuestion envía `pagina_origen`/`orden` por chunk y no reconstruye nada localmente. La metadata no se expone al usuario en v1.
- **La comparación intra-sesión (universo 2) y la detección de contradicciones son de QBK** (§1.4/§1.5, tareas 3–4 de QBK): Kuestion solo consume sus resultados en el polling y los muestra en la bandeja.
- **El análisis es asíncrono** (§4): carga → respuesta inmediata con `session_id` → polling cada 5s → resultado. Reintentos (3, backoff exponencial) ante error de red; error de LLM = fallo notificado.

### Verificación previa contra código real (hecha antes de proponer fases)

| # | Hallazgo | Evidencia | Consecuencia en el plan |
|---|---|---|---|
| H1 | QuBeKa **no tiene** `POST /contribute/document`, ni contradicciones, ni approve con deselección: su `POST /contribute` valida `texto` máx 2000 chars y no hay más endpoints de aporte | `../QuBeKa/qubeka/routes/api.php` + `ContributeController` | Todo el ciclo documento se construye contra el contrato **propuesto** (mock) hasta que QBK entregue; validación real queda condicionada (Fase D) |
| H2 | El pipeline interno de QuBeKa **ya es chunk-based**: `chunks_procesados`/`chunks_totales` en `SesionAnalisis`, `dividirEnChunks()`, `procesarChunk()`, `ProcesarSesionAnalisisJob` itera chunks | Su modelo/job/service | Su tarea 2 (extender a sesión multi-chunk vía API) es plausible; el campo de progreso ya existe para el polling |
| H3 | Kuestion **no tiene librerías de extracción**: no hay `smalot/pdfparser` ni `phpoffice/phpword` en `composer.json` | `composer.json` + `vendor/` | Fase A instala y valida las librerías con documentos reales antes de que nada más las use |
| H4 | Límite PHP real: `upload_max_filesize=2M`, `post_max_size=8M` — el límite de 20 MB propuesto (§4, "A confirmar con Kuestion") **no cabe hoy** | `php -r ini_get(...)` | Fase A incluye el ajuste de configuración local y declara la decisión de límite definitivo |
| H5 | La pantalla de entrada (`question-feed.blade.php`), el servicio (`QbkContributionService` con patrón credential/401/403/504) y la bandeja (`ReviewTray` con `aprobar/rechazar/edit/explicaciones`) existen y son extensibles | Código actual | Reutilizamos patrones existentes (regla operativa del proyecto): nada se reimplementa |

### Preguntas abiertas ANTES de las fases (no las asumimos)

1. **[No bloqueante — decisión de ingeniería, nos toca a nosotros]** El spec deja "librería y método de extracción" a confirmación de Kuestion (§1.2). Proponemos `smalot/pdfparser` (PDF, preserva saltos de página) y `phpoffice/phpword` (DOCX, lee headings). Se validan empíricamente en Fase A con documentos reales antes de comprometer el resto.
2. **[No bloqueante — configuración local]** Límite de 20 MB (§4): requiere subir `upload_max_filesize`/`post_max_size` del entorno (hoy 2M/8M) o reducir el límite. Lo resolvemos en Fase A con configuración reproducible y lo declaramos en el cierre. No es decisión de producto: el spec lo asigna a Kuestion ("A confirmar con Kuestion").
3. **[Pendiente de QuBeKa — bloquea Fase D, no A–C]** Estrategia de chunking definitiva (§1.3, tarea 7 de QBK), formato de contradicciones (§3.3, decisión abierta #2), extensión del endpoint de detalle (`chunks_procesados`/`chunks_totales`) y del approve (§3.4, `nodos_aprobados`/`nodos_rechazados`). Mientras tanto: mock fiel al contrato propuesto, como en Ola 2.
4. **[Pendiente de QuBeKa — no bloquea nada de Kuestion]** Viabilidad de detección de contradicciones (§1.5, tarea 3 de QBK): si requiere capacidad nueva, su plan arranca con fase de validación (mismo criterio que explicabilidad en Ola 2). Kuestion la consume; no la construimos.

---

## 2. FASES Y TAREAS

### Fase A — Extracción, chunking e infraestructura de carga

**Objetivo:** que un archivo TXT/MD/PDF/DOCX real se convierta en chunks validados con metadata de origen, sin tocar todavía la UI ni QuBeKa.

**Tareas:**
- **A.1** Instalar y validar las librerías de extracción (propuesta del Resumen §1: `smalot/pdfparser`, `phpoffice/phpword`), verificando compatibilidad con PHP 8.2/Laravel 11 antes de usarlas.
- **A.2** `DocumentExtractor` (servicio): TXT/MD lectura directa; PDF con preservación de saltos de página (§1.2); DOCX con headings. Output tipado: lista de unidades de texto con metadata (página para PDF, heading/estructura para DOCX).
- **A.3** `DocumentChunker`: tamaño objetivo ~3000 chars, overlap ~300, cada chunk conserva página(s) de origen y heading inicial si aplica (§1.3). Estrategia **provisional y aislada** detrás de una interfaz — cuando QBK defina la suya (tarea 7), se reemplaza sin tocar el resto.
- **A.4** Validación de PDF escaneado (sin texto) → error controlado "No OCR" (§2).
- **A.5** Configuración de límites (H4): subir `upload_max_filesize`/`post_max_size` a 25M+ en el entorno local y documentarlo; constantes de validación (20 MB, 100 páginas, formatos) en `config/kuestion.php`.
- **A.6** Deduplicación por hash (§4): función de hash del contenido extraído para el aviso previo "este documento ya se subió".

**Dependencias:** ninguna externa. H3/H4 resueltas aquí.
**Entregable verificable:** fixture real de cada formato → chunking demostrable con metadata correcta; PDF escaneado rechazado con mensaje claro.
**Validación antes de seguir:** tests con fixtures reales (PDF/DOCX generados en el repo de tests), incluyendo casos borde (texto corto = 1 chunk, documento > 100 páginas rechazado, overlap no corta una frase a la mitad).

### Fase B — Flujo de carga asíncrono (UI + servicio + polling)

**Objetivo:** el ciclo usuario completo hasta ver el resultado: subir → "Procesando documento..." → "Listo. Se propusieron N nodos..." → [Revisar].

**Tareas:**
- **B.1** Componente Livewire `UploadDocument` (botón "Subir documento" en `question-feed.blade.php` junto a Preguntar/Aportar §1.1): selector de archivo + campo opcional de contexto "¿De qué trata este documento?".
- **B.2** Validación de archivo (formato, tamaño, páginas) con mensajes por caso (§5.7): no soportado / muy grande / muy largo / escaneado / duplicado (aviso por hash, no bloqueo).
- **B.3** `contributeDocument()` en `QbkContributionService`: `POST /contribute/document` con `documento_nombre`, `chunks[]`, `origen: "kuestion"`, `contexto_opcional` (§3.1), reutilizando el patrón existente del servicio (credential, 401→invalid repo, 403, 504, sobre `{success, data}`).
- **B.4** Persistencia local de la carga (nueva tabla `document_uploads`: nombre, hash, estado, session_id, chunks, error) — mismo rol que `ContributionDraft` para aportes cortos: recuperación ante fallo y base del aviso de duplicado. Los chunks **no se persisten** más allá del envío (§4: se descartan tras clasificar).
- **B.5** Flujo asíncrono: respuesta inmediata → estado "Procesando documento... esto puede tomar unos minutos." (§1.1.4) → **polling cada 5s** contra `getSession()` (endpoint existente, decisión cerrada §3.3) leyendo `chunks_procesados`/`chunks_totales` cuando vengan → estado final con resumen y botón [Revisar] hacia la bandeja.
- **B.6** Reintentos: 3 con backoff exponencial ante error de red (§4); error de LLM/timeout (10 min) = sesión fallida con mensaje claro y retenida (§4).
- **B.7** Fallo visible en cada estado: nunca "cargando…" infinito; error legible + cómo proceder (reintentar / ir a la bandeja).

**Dependencias:** Fase A completa. Endpoint de QBK **no disponible** (H1) → mock fiel al contrato propuesto.
**Entregable verificable:** con mock, el ciclo completo de B recorre pantalla a pantalla: archivo → validación → envío → polling con progreso → resultado con [Revisar]. Y cada camino de error muestra su mensaje.
**Validación antes de seguir:** tests de feature del componente (estados idle/validando/procesando/listo/error), fake HTTP del contrato §3.1/§3.2/§3.3, y checklist funcional FA-B (sección 3).

### Fase C — Bandeja de revisión para documentos

**Objetivo:** revisar el documento como un ítem con resumen por tipo, contradicciones como advertencias, deselección individual y aprobación por lote (§1.7).

**Tareas:**
- **C.1** `ReviewTray`: el ítem "documento" muestra nombre, fecha, conteo por tipo ("32 nodos: 4 Q, 6 SQ, 12 H, 10 N-K"), y la advertencia destacada "Este documento contradice N nodos existentes. [Ver detalles]" cuando `contradicciones[]` venga en el detalle (§3.3).
- **C.2** Vista expandida del documento: nodos agrupados por tipo, cada uno con texto + indicador de confianza (metadatos de explicabilidad de Ola 2 P4 — ya normalizados por `ExplicacionNormalizer`, se reutilizan tal cual).
- **C.3** Checkbox por nodo (todos preseleccionados por defecto, §1.7) + botones "Aprobar seleccionados" y "Rechazar todo".
- **C.4** Extensión de `approve()` en el servicio: `nodos_aprobados[]`/`nodos_rechazados[]` (§3.4) sobre el endpoint existente, manteniendo `revisado_por_*` (contrato v1.3) y el patrón de estados transitorios (`aprobada` → `promocionada`).
- **C.5** "Ver detalles" de contradicciones: vista del par (nodo nuevo propuesto, nodo existente, descripción) — advertencia, nunca error ni bloqueo (§1.8).
- **C.6** La decisión abierta #6 del spec (preselección según confianza) **no se implementa**: es "a evaluar" con validación de UX, no alcanzable desde la ingeniería. Se registra, no se construye.

**Dependencias:** Fase B. Extensión de approve en QBK no disponible (H1) → mock.
**Entregable verificable:** con mock: bandeja muestra el ítem documento con sus diferencias respecto al aporte corto; deselección de nodos → approve envía exactamente los IDs correctos; rechazo total descarta; contradicciones visibles sin bloquear.
**Validación antes de seguir:** tests del componente (selección/deselección, approve payload correcto, rechazo), checklist FC (sección 3) incluyendo verificación visual DevTools.

### Fase D — Validación real contra QuBeKa

**Objetivo:** cerrar el ciclo con el servicio real, cuando QuBeKa entregue su parte.

**Tareas:**
- **D.1** Alineación de contrato: con las respuestas de QBK (chunking definitivo, formato de contradicciones, nombres de campos), actualizar `CONTRATO_API_OLA2.md` → `CONTRATO_API_OLA3.md` y ajustar el normalizador si el naming real difiere (patrón `listSessions`: aceptar ambas variantes).
- **D.2** E2E real: documento real → extracción → chunking → `POST /contribute/document` real → polling contra el detalle real → bandeja → aprobar subconjunto → verificar promoción en QuBeKa → rechazar otro documento.
- **D.3** Validación de contradicciones reales: si la capacidad nueva de QBK ya produce metadatos, verificar el render con datos reales; si no llegó, queda declarado como pendiente condicionado (no se finge).
- **D.4** Revalidación de límites: `MAX_CHUNKS` real de QuBeKa y timeout de 10 min (§4) contra documentos grandes reales.

**Dependencias:** **entrega de QuBeKa** (tareas 1–8 de su spec §5). Sin ella, esta fase no se ejecuta y queda declarada pendiente — no se simula.
**Entregable verificable:** el flujo completo funciona contra QBK levantado con un documento real, o la fase queda abierta con su motivo y qué falta de cada lado.
**Validación antes de cerrar:** checklist FD (sección 3) contra servicio real.

### Fase E — QA integral y cierre

**Objetivo:** regresión completa y entregables del proyecto.

**Tareas:**
- **E.1** Suite completa en verde + Pint.
- **E.2** Rebuild de assets (`npm run build`) y verificación de las clases nuevas en el CSS **compilado** (regla obligatoria §3 del prompt).
- **E.3** Verificación visual en navegador real (DevTools) de toda la UI nueva: botón en el feed, formulario de carga, estados de progreso, bandeja extendida, advertencias de contradicciones.
- **E.4** Documento de cierre + resumen de implementación + sync GitHub.

**Dependencias:** A–D según corresponda (D puede estar pendiente; se declara).
**Entregable verificable:** cierre con matriz mock-vs-real explícita.

---

## 3. PRUEBAS FUNCIONALES Y DE INTEGRACIÓN (además de las pruebas unitarias)

> Estado de disponibilidad del otro equipo (declarado al generar este plan, verificado en código): **QuBeKa no tiene ningún endpoint de documentos hoy** (H1). Todas las fases A–C se validan con mock fiel al contrato propuesto; la prueba contra servicio real es **imposible hasta su entrega** y queda condicionada en Fase D. Esto replica el patrón ya usado en Ola 2 (Punto 1 y Punto 4): construir contra contrato propuesto, validar real cuando el otro lado entrega.

### Fase A — Extracción y chunking

- [ ] FA-1: TXT real de N páginas equivalentes → chunks ~3000 chars con overlap ~300; ningún chunk arranca a mitad de frase.
- [ ] FA-2: PDF real (múltiples páginas) → cada chunk conserva su página de origen; saltos de página preservados.
- [ ] FA-3: DOCX real con headings → los chunks que inician sección conservan el heading.
- [ ] FA-4: PDF escaneado (imagen) → error controlado "documento escaneado no soportado", sin excepción cruda.
- [ ] FA-5: DOCX corrupto → error legible, no stack trace.
- [ ] FA-6: Documento > 100 páginas → rechazado con mensaje.
- [ ] FA-7: Mismo archivo dos veces → mismo hash (base del aviso de duplicado de B.2).

### Fase B — Flujo de carga (checklist funcional manual/E2E, contra mock)

- [ ] FB-1: En el feed aparecen **tres** acciones: Preguntar, Aportar, **Subir documento** (DevTools: botón visible, texto legible, contraste correcto).
- [ ] FB-2: Clic en "Subir documento" → selector de archivo + campo de contexto opcional visible y usable.
- [ ] FB-3: Archivo válido → "Procesando documento... esto puede tomar unos minutos." visible (no queda colgado).
- [ ] FB-4: Polling: con mock que demora, el progreso (`chunks_procesados`/`chunks_totales`) se refleja; al terminar: "Listo. Se propusieron N nodos... [Revisar]".
- [ ] FB-5: [Revisar] navega a la bandeja (clic real, no solo href presente).
- [ ] FB-6: Archivo de 3 MB con límites PHP sin ajustar → se confirma que tras el ajuste de A.5 la subida funciona; si el entorno no permite el ajuste, el error es claro y temprano (no timeout a ciegas).
- [ ] FB-7: Error de red simulado → reintento automático (hasta 3, backoff) observable en logs; agotados → mensaje claro con acción.
- [ ] FB-8: Duplicado por hash → aviso **antes** de procesar; el usuario puede continuar o cancelar.
- [ ] FB-9: QBK caído → error visible inmediato ("No se pudo conectar con QuBeKa..."), estado de UI recuperable, y `document_uploads` conserva la carga para reintentar.

### Fase C — Bandeja con documentos (checklist funcional manual/E2E, contra mock)

- [ ] FC-1: El documento aparece como **un ítem** (no N) con nombre, fecha y conteo por tipo legible.
- [ ] FC-2: Advertencia de contradicciones destacada (no estilo error) + "Ver detalles" muestra pares (nuevo, existente, descripción).
- [ ] FC-3: Expansión: nodos agrupados por tipo con texto y confianza (indicador de Ola 2 P4 reutilizado, mismo vocabulario).
- [ ] FC-4: Deseleccionar 2 de 5 nodos → "Aprobar seleccionados" envía `nodos_aprobados` con los 3 correctos y `nodos_rechazados` con los 2 (verificado en el payload del mock, no asumido).
- [ ] FC-5: "Rechazar todo" → sesión descartada; el ítem sale de la bandeja.
- [ ] FC-6: Fallo del approve (mock 500) → mensaje visible, los checkboxes no se pierden, se puede reintentar.
- [ ] FC-7: DevTools de toda la pantalla nueva: elementos visibles, botones con fondo, contraste correcto.
- [ ] FC-8: Regresión del flujo aporte corto (Ola 1 P3) y de la bandeja existente: nada de lo ya entregado cambia de comportamiento.

### Ítems obligatorios de UI/integración (todas las fases)

1. **Rebuild y verificación de assets:** todo cambio de vista pasa por `npm run build` y grep de las clases nuevas en el CSS de `public/build/assets/` (patrón ya aplicado en Ola 2 P4/P5; las variantes con `hover:`/`focus:` se buscan escapadas).
2. **Verificación visual en navegador real:** checklists FB/FC incluyen inspección DevTools explícita; un test en verde no reemplaza la pantalla.
3. **Compatibilidad de versiones:** Livewire 4 (verificar `WithFileUploads` y polling en el código del vendor instalado, no en docs); las librerías de A.1 se validan contra la versión instalada antes de usar sus APIs.
4. **Fallo visible en runtime:** cada estado de error definido en B.7/C.6 es un requisito, no un detalle; un estado colgado en "cargando" se reporta como bug.
5. **Prueba contra servicio real:** imposible en A–C (H1). Se ejecuta en D; hasta entonces queda declarado como pendiente con su motivo, y nada se declara "cerrado" apelando al mock.

---

## 4. DUDAS Y BLOQUEOS

**Bloqueantes (impiden cerrar fases, no impiden avanzar):**

| # | Bloqueo | Hacia quién | Fase que espera |
|---|---|---|---|
| B1 | Estrategia definitiva de chunking (tarea 7 de QBK) | QuBeKa | D (A construye con la provisional aislada) |
| B2 | Endpoint `POST /contribute/document` real | QuBeKa | D |
| B3 | Extensión del detalle con `chunks_procesados`/`chunks_totales` y `contradicciones[]` | QuBeKa | D |
| B4 | Extensión del approve con `nodos_aprobados`/`nodos_rechazados` | QuBeKa | D |
| B5 | Viabilidad/formato de contradicciones (tarea 3 de QBK; si requiere fase de validación, su plan lo arranca) | QuBeKa | D.3 |

**No bloqueantes (avanzamos con supuesto razonable, a confirmar antes de cerrar la fase que corresponda):**

| # | Supuesto | Dónde se confirma |
|---|---|---|
| N1 | Librerías de extracción propuestas (`smalot/pdfparser`, `phpoffice/phpword`) | Fase A, con fixtures reales |
| N2 | Límite 20 MB requiere ajuste de PHP del entorno (hoy 2M/8M) | Fase A.5; si el entorno de producción no lo permite, se eleva como restricción a producto |
| N3 | Naming del contrato (§3 marcado como propuesta) | D.1 con las respuestas reales |
| N4 | Decisión abierta #6 del spec (preselección por confianza) — no se construye | Producto, ola posterior |

---

## 5. ESFUERZO ESTIMADO

| Fase | Estimado | Nota |
|---|---|---|
| A — Extracción/chunking | 2–3 días | La de **mayor incertidumbre**: la calidad de extracción real (PDFs malformados, DOCX variados) solo se confirma con fixtures reales; es la base de todo lo demás |
| B — Flujo asíncrono | 2–3 días | Polling + estados de UI + reintentos; mock fiel |
| C — Bandeja extendida | 2 días | Reutiliza `ReviewTray`, `ExplicacionNormalizer` y el patrón approve existentes |
| D — Validación real | 1 día (cuando QBK entregue) | Condicional; no consumes el estimado hasta que exista |
| E — QA integral | 0.5–1 día | Suite, assets, visual, cierre |
| **Total Kuestion (sin D)** | **~6.5–9 días** | |

---

## 6. FUERA DE ALCANCE

Aunque el documento de origen los mencione, **no construimos**:

1. La lógica de QuBeKa: endpoint `/contribute/document`, sesión multi-chunk vía API, comparación intra-sesión (universo 2), detección de contradicciones, metadata de trazabilidad, extensión del approve (§5.A es suyo; nuestro alcance es §5.B).
2. Exposición al usuario de la trazabilidad de origen (§1.6: metadata interna; decisión cerrada).
3. Edición estructural de nodos (§1.7 decisión cerrada).
4. Fusión de nodos duplicados (§2) — solo sugerencias vía `alternatives_considered` que consume.
5. OCR, imágenes, audio, video (§2).
6. Multi-documento por sesión (§2: 1 documento = 1 sesión).
7. Detección de contradicciones intra-sesión (§2, fuera de alcance v1).
8. Sincronización con el documento original (§2 / arquitectura §3 "Fuente externa").
9. Política post-aprobación de contradicciones (decisión abierta #4: ola posterior).
10. Preselección por confianza (decisión abierta #6: requiere validación de UX).
11. Instrumentación de medición del gate (decisión abierta #5: se define con producto en la ola; solo registramos que existe).
12. Kuaforia — fuera de este punto (ecosistema de trabajo actual: Kuestion ↔ QuBeKa).

---

## Reglas de ejecución aplicadas a este plan

- No se reabren decisiones cerradas del spec (#1 lote+deselección, #2 contradicciones advertencia, #4 sin edición, #5 metadata interna).
- El contrato §3 se trata como **propuesta**: los nombres reales se congelan con QuBeKa en D.1 (mismo proceso que produjo `CONTRATO_API_OLA2.md` v1.x con revisiones cruzadas).
- Ninguna fase se declara cerrada sin sus ítems obligatorios (assets rebuild, verificación visual, compatibilidad de versiones, fallo claro, prueba contra real cuando esté disponible) — los pendientes se declaran con motivo, no se asumen.
