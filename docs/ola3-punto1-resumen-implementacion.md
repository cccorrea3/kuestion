# Resumen de implementación — Ola 3, Punto 1: Subida de Documentos

*Equipo de Kuestion · Septiembre 2026*
*Plan: `docs/ola3-punto1-plan-implementacion-kuestion.md` · Cierre técnico: `docs/ola3-punto1-cierre-implementacion.md`*

---

## 1. Qué se implementó

El flujo completo "Subir documento": un tercer botón en la pantalla de entrada que permite
cargar TXT/MD/PDF/DOCX, extrae el texto con metadata de origen (páginas/headings), lo divide
en chunks, lo envía al pipeline de clasificación de QuBeKa (`POST /contribute/document`),
hace polling del estado contra el detalle de sesión existente, y revisa el resultado en la
bandeja extendida: un ítem por documento, con contradicciones como advertencias,
deselección individual de nodos y "Aprobar seleccionados" / "Rechazar todo".

El gate humano nunca se salta: nada entra al grafo sin aprobación explícita (§2 del spec).

## 2. Implementación por tarea (referencia al plan)

| Tarea | Entregable | Archivos |
|---|---|---|
| A.1–A.6 | Extracción tipada (TXT/MD/PDF/DOCX), chunking provisional aislado (3000/300), escaneado→"No OCR", límites (20MB/100p/600s) + php.ini ajustado, hash de dedup | `app/Services/DocumentProcessing/{DocumentExtractor,ChunkerProvisional,DocumentChunker,DocumentUnit,Chunk,DocumentHash,DocumentoEscaneadoException}.php`, `config/kuestion.php` |
| B.1 | Botón "Subir documento" en el feed (ambas variantes, con y sin preguntas) + página `/documents/upload` | `question-feed.blade.php`, `routes/web.php` |
| B.2 | Validación de formato/tamaño con mensajes por caso + aviso de duplicado con confirmar/cancelar | `UploadDocument.php` |
| B.3 | `contributeDocument()` con `hash_documento` (contrato v1.6), patrón credential/401/403/504 y sobre `{success,data}` | `QbkContributionService.php` |
| B.4 | Tabla `document_uploads` (estado, hash, session_id, chunks, error, intentos) — chunks NO persistidos más allá del envío (§4) | migración `2026_09_13_000001`, `app/Models/DocumentUpload.php` |
| B.5–B.7 | Estados idle/subiendo/procesando/listo/error, polling 5s con progreso, reintentos con backoff (solo red), retoma de cargas pendientes, fallo visible siempre | `UploadDocument.php`, `upload-document.blade.php` |
| C.1–C.3 | Panel de documento en la bandeja: nodos agrupados por tipo con explicación (reutiliza `x-classification-explanation` de Ola 2 P4), checkboxes preseleccionados, todos/ninguno | `ReviewTray.php`, `review-tray.blade.php` |
| C.4 | `approve()` con `nodos_aprobados[]`/`nodos_rechazados[]` (§3.4) + `revisado_por_*` (v1.3 intacto) | `QbkContributionService.php` |
| C.5 | Advertencia de contradicciones (ámbar, no bloqueante) con el par (propuesto, existente, descripción) | `review-tray.blade.php` |
| C.6 | Decisión abierta #6 (preselección por confianza): **registrada, no construida** | plan §6 |

## 3. Verificación

### 3.1 Tests automatizados

| Suite | Cobertura | Resultado |
|---|---|---|
| `DocumentProcessingTest` | FA-1…FA-7: TXT chunking/overlap límites de frase, PDF multipágina con página de origen, DOCX con headings, escaneado rechazado, DOCX corrupto legible, >100 páginas rechazado, hash determinista + bordes | 9 passed |
| `UploadDocumentTest` | FB: formulario visible, envío válido→procesando, payload §3.1 exacto, formato/tamaño rechazados, duplicado (continuar/cancelar), polling con progreso y cierre, QBK caído con carga retenida, 3 reintentos, sesión error, retoma | 10 passed |
| `ReviewTrayDocumentosTest` | FC: ítem único, preselección total, payload de subconjunto exacto (3 aprobados + 2 rechazados verificados en el request), guard sin selección, contradicciones visibles, rechazo total, approve 500 conserva selección + reintento, regresión flujo simple, guard doble envío | 9 passed |
| **Suite completa del proyecto** | Regresión total | **548 passed / 1554 assertions / 0 fallos** |

### 3.2 E2E real contra QuBeKa (servicio real, no mock)

QuBeKa ya había entregado su parte (commits F2–F5, contrato v1.6), por lo que la Fase D se
ejecutó completa:

```
documento TXT real → 1 chunk → POST /contribute/document real
  → sesión 62: {status: procesando, chunks_recibidos: 1}   (respuesta inmediata)
  → job de QuBeKa procesa con su IA real (Ollama Cloud)
  → polling: chunks_procesados=1/1 → lista_para_revision
  → 2 nodos H propuestos, con explicación real (confidence, reasons, patterns)
  → approve con subconjunto (1 aprobado, 1 rechazado) → aprobada
  → promoción → promocionada, exactamente 1 nodo promovido al grafo
```

Límites reales confirmados: `MAX_CHUNKS=120` (422 al exceder, sin truncar), texto por chunk
≤6000, timeout 10 min.

### 3.3 Ítems obligatorios (sección 3 del plan)

- **Assets**: `npm run build` OK; clases nuevas verificadas en el CSS compilado/servido
  (variantes `hover:`/`file:`/`focus:` buscadas escapadas).
- **Compatibilidad**: `WithFileUploads` (Livewire 4 vendor), APIs de pdfparser/phpword
  (vendor), semántica de `Http::fake` (Laravel vendor) — nada asumido de documentación.
- **Fallo visible en runtime**: cada camino de error tiene test y mensaje con cómo proceder.
- **Servicio real**: ejecutado (§3.2). Único matiz: la sesión de prueba no produjo
  contradicciones reales (`contradicciones: null`); el render está testeado con la
  estructura exacta del contrato v1.6.

## 4. Hallazgos y decisiones

1. **H1 quedó superado**: el plan se escribió con QuBeKa sin endpoints de documentos;
   durante la ejecución ya tenía F2–F5 desplegados. Consecuencia: Fase D ejecutada en vez
   de condicionada, y el contrato se tomó de `CONTRATO_API_REVISION.md` §2.5 (v1.6) sin
   duplicarlo.
2. **`hash_documento`** existe en el contrato real (dedup del lado QBK) y no estaba en el
   payload propuesto del spec — agregado en B.3/D.1.
3. **Patrón de claves**: `document_uploads` usa `user_id`→`users.uuid` y
   `repository_id`→uuid nullable, igual que `contribution_drafts`. Detectado por el FK en
   el primer migrate (la versión inicial usaba bigint).
4. **phpword no serializa `pStyle Heading1` al escribir**: los fixtures DOCX inyectan el
   estilo en el XML como Word real; el extractor detecta headings por el elemento `Title`
   que produce el reader (verificado contra vendor).
5. **`Http::fake()` acumula stubs** (gana el primero que matchea): los tests usan fakes
   secuenciales. Patrón registrado para toda la suite futura.
6. **`RepositoryMigrationTest` usa conteo fijo de rollback** (8→9 pasos): cada migración
   nueva debe actualizar ese número o cascaba ~113 tests ajenos por corrupción de esquema.
   Documentado como trampa recurrente.
7. **Infraestructura de QuBeKa (transitorio)**: la primera sesión E2E (61) falló porque el
   proveedor de IA de QuBeKa no respondió (`cURL error 28` contra `ollama.com`, 180s). El
   reintento completo funcionó. Kuestion maneja ese caso como sesión fallida con mensaje
   visible (B.7), que es el comportamiento especificado.

## 5. Pendientes

| Pendiente | Motivo | Quién |
|---|---|---|
| Verificación visual DevTools de la UI nueva | Requiere navegador con sesión humana | Usuario |
| Chunking definitivo de QBK (su tarea 7) | No publicado aún; `ChunkerProvisional` está aislado detrás de interfaz para reemplazo sin tocar el resto | QuBeKa |
| Contradicciones con datos reales | La detección es capacidad de QBK; la sesión de prueba no generó conflictos | QuBeKa |
| Consolidar `CONTRATO_API_OLA3.md` | El contrato de documentos vive en `CONTRATO_API_REVISION.md` §2.5 (v1.6); decidir si se consolida | Producto + equipos |
| Reconectar repo QBK en `/settings` si corresponde | El E2E dejó el repo activo apuntando al token `qk:1:e2e-doc-ola3` (expira 2026-10-15) | Usuario |

## 6. Estado final

**Plan completamente cerrado (fases A–E)**, incluida la validación real contra QuBeKa.
Suite completa en verde (548/1554), assets verificados, Pint limpio. El flujo de punta a
punta funciona con IA real: documento → extracción → chunking → clasificación asíncrona →
bandeja con revisión por lote y deselección → promoción del subconjunto aprobado al grafo.
