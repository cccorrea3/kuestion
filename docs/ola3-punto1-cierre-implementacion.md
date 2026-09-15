# Cierre — Ola 3, Punto 1: Subida de documentos a Kuestion

*Equipo de Kuestion · Septiembre 2026*
*Plan ejecutado: `docs/ola3-punto1-plan-implementacion-kuestion.md`*
*Spec de entrada: `OLA_3_Punto_1.md`*

---

## 1. Estado del plan

**Todas las fases (A–E) cerradas**, incluida la Fase D de validación real — que el plan
declaraba condicionada a la entrega de QuBeKa (H1) — porque **QuBeKa ya entregó su parte**
(verificado contra su repo: commits F2–F5 "documentos Ola3 P1", contrato v1.6 en
`CONTRATO_API_REVISION.md` §2.5, `POST /contribute/document` + subconjunto de aprobación +
`chunks_procesados/totales` + `contradicciones` en el detalle de sesión).

## 2. Reporte por fase

| Fase | Resultado | Archivos |
|---|---|---|
| **A** — Extracción/chunking | A.1 librerías instaladas y validadas contra vendor (`smalot/pdfparser ^2.12`, `phpoffice/phpword ^1.4`); A.2 `DocumentExtractor` (TXT/MD/PDF/DOCX, errores legibles, escaneado → "No OCR"); A.3 `ChunkerProvisional` aislado detrás de `DocumentChunker` (3000/300, sin cortar frases, sin cruzar páginas/headings) + `cuentaPaginas()` con guardia FA-6; A.5 límites en config (20MB/100p/600s) + `upload_max_filesize=25M`/`post_max_size=30M` en php.ini; A.6 `DocumentHash` (sha256) | `app/Services/DocumentProcessing/*` |
| **B** — Flujo asíncrono | B.1 componente `UploadDocument` + vista con estados idle/subiendo/procesando/listo/error; B.2 validación (formato, tamaño, duplicado con confirmación FB-8); B.3 `contributeDocument()` en el servicio (patrón credential/401/403/504, sobre `{success,data}`); B.4 tabla `document_uploads` (patrón real de claves: `user_id` uuid, `repository_id` uuid) + modelo; B.5 polling 5s contra `getSession()` (decisión cerrada §3.3) leyendo `chunks_procesados/totales`; B.6 reintentos (3, backoff exponencial, solo errores de red); B.7 fallo visible en cada estado + retoma de cargas pendientes | `app/Livewire/UploadDocument.php`, `resources/views/livewire/upload-document.blade.php`, `app/Services/QbkContributionService.php`, migración `2026_09_13_000001`, `routes/web.php`, `question-feed.blade.php` |
| **C** — Bandeja extendida | C.1/C.2 ítem documento → botón "Revisar documento" + panel expandido con nodos agrupados por tipo, texto y explicación (componente Ola 2 P4 reutilizado); C.3 checkboxes (todos preseleccionados §1.7) + seleccionar todos/ninguno; C.4 `approve()` extendido con `nodos_aprobados[]`/`nodos_rechazados[]` (§3.4) manteniendo `revisado_por_*` (v1.3) y estados transitorios; C.5 contradicciones como advertencia ámbar (no error, no bloquea §1.8); C.6 decisión abierta #6 registrada, no construida; FC-6 fallo visible conservando la selección | `app/Livewire/ReviewTray.php`, `resources/views/livewire/review-tray.blade.php`, `app/Services/QbkContributionService.php` |
| **D** — Validación real | Ejecutada completa contra QuBeKa real (ver §3) | — |
| **E** — QA | Suite completa 548 passed / 1554 assertions / 0 fallos; Pint limpio; rebuild de assets con verificación de clases en el CSS compilado (incluidas variantes escapadas); contenido de UI asertado en tests (FB-1/FB-2) | — |

## 3. Validación E2E real contra QuBeKa (Fase D)

Entorno: `dev-qbk.sh start` (Kuestion :8001, QuBeKa :8000), token de agente real creado con
el mismo flujo de su UI (`qk:1:e2e-doc-ola3`, expira 2026-10-15).

| Paso | Resultado |
|---|---|
| D.1 — Alineación de contrato | El contrato real v1.6 matchea lo implementado (payload §3.1, respuesta inmediata, detalle extendido, approve con subconjunto). **Gap cerrado en el camino**: QuBeKa acepta `hash_documento` para su dedup → agregado al payload |
| D.2 — E2E completo | Documento real → extracción (1 chunk) → `POST /contribute/document` real → **sesión 62, respuesta inmediata `procesando`** → job de QuBeKa procesa el chunk con su IA real → polling con `chunks_procesados=1/1` → `lista_para_revision` con **2 nodos H propuestos por la IA**, cada uno con explicación real (`confidence`, `reasons`, `detected_patterns`) |
| D.2 — Contradicciones | El detalle real expone `contradicciones: null` (sin conflictos en el documento de prueba) → la UI no muestra la advertencia. El render con datos está cubierto por test con la estructura exacta del contrato v1.6 |
| D.2 — Approve con subconjunto | `approve(62, ..., nodos_aprobados=[sandbox_62_c0_n0], nodos_rechazados=[sandbox_62_c0_n1])` → `aprobada` → job de promoción → **`promocionada` con exactamente 1 nodo promovido al grafo** (el subconjunto se respetó) |
| D.4 — Límites | `MAX_CHUNKS = 120` confirmado en `AnalisisService` (exceder → 422, no truncado); timeout de análisis 10 min en config; texto por chunk ≤6000 (nuestro troceo de 3000 cabe con margen) |

## 4. Hallazgos principales

1. **QuBeKa ya entregó su parte** (el plan asumía H1 = no disponible): F2–F5 implementados
   y contrato v1.6 publicado. La Fase D pasó de "condicionada" a "ejecutada". El D.1
   (`CONTRATO_API_OLA2.md` → OLA3) no se hizo como reescritura: el contrato de documentos
   vive en `CONTRATO_API_REVISION.md` §2.5 (v1.6) del lado de QuBeKa; se referenció en
   lugar de duplicarlo.
2. **`hash_documento`**: el contrato v1.6 lo define (dedup del lado QBK) y no estaba en el
   payload propuesto del spec §3.1 — agregado (D.1).
3. **Claves del proyecto**: `document_uploads` usa el patrón real (`user_id` → `users.uuid`,
   `repository_id` → uuid nullable), igual que `contribution_drafts`. La primera versión de
   la migración usó bigint y el FK lo detectó en el primer migrate.
4. **El writer de phpword no serializa `pStyle Heading1`**: los DOCX generados con
   `addTitle()` se releen como `TextRun` sin estilo. El fixture del test inyecta el
   `pStyle` en el XML como lo haría Word real (verificado contra vendor). El extractor
   distingue encabezado por elemento `Title` (señal real del reader).
5. **`Http::fake()` acumula stubs y gana el primero que matchea** (verificado en vendor,
   `Factory::fake()` hace `merge`): cualquier fake posterior no reemplaza al anterior. Los
   tests usan fakes secuenciales por cierre/callable.
6. **Ecosistema de pruebas**: `RepositoryMigrationTest` hace rollback con conteo fijo de
   migraciones (8 → 9); sin el ajuste corrompía el esquema compartido y cascaba 113 tests
   ajenos. Registrado porque volverá a ocurrir con cada migración nueva.
7. **Hallazgo de infraestructura de QuBeKa (no de Kuestion)**: la primera sesión E2E real
   (61) falló con `cURL error 28` contra `https://ollama.com/api/chat` (timeout 180s,
   0 bytes) — el proveedor de IA no respondió en ese momento. El reintento (sesión 62)
   funcionó completo, lo que confirma que fue transitorio. Kuestion muestra ese fallo
   correctamente (`estado error` → mensaje visible en el paso de polling, B.7).

## 5. Pruebas

| Suite | Resultado |
|---|---|
| `DocumentProcessingTest` (FA-1…FA-7 + bordes) | 9 passed |
| `UploadDocumentTest` (FB-1…FB-9 + formulario) | 10 passed |
| `ReviewTrayDocumentosTest` (FC-1…FC-8 + guards) | 9 passed |
| **Suite completa** | **548 passed / 1554 assertions / 0 fallos** |

Ítems obligatorios §3 del plan:

- **Rebuild + CSS compilado**: `npm run build` OK; clases nuevas verificadas en el bundle
  (`bg-amber-50`, `border-amber-300`, `hover:bg-amber-100`, `file:bg-primary`,
  `focus:ring-primary`, `bg-emerald-600`, `disabled:opacity-50`, etc. — las variantes
  buscadas escapadas).
- **Compatibilidad de versiones**: `WithFileUploads` y `TemporaryUploadedFile` verificados
  contra el vendor de Livewire 4 instalado; APIs de pdfparser/phpword contra el vendor;
  semántica de `Http::fake` contra el vendor de Laravel.
- **Fallo visible en runtime**: cada camino de error de B.7/C.6 tiene test (formato,
  tamaño, QBK caído, sesión en error, approve 500, sin selección).
- **Prueba contra servicio real**: ejecutada (§3) — la fase D no quedó pendiente.

## 6. Pendientes declarados

| Pendiente | Motivo |
|---|---|
| Verificación visual DevTools de la UI nueva (feed con 3er botón, formulario, progreso, panel del documento en la bandeja) | Requiere navegador con sesión humana; el render HTTP y el contenido de UI están asertados en tests, la inspección de contraste/devtools queda para el usuario |
| Chunking definitivo (tarea 7 de QBK) | `ChunkerProvisional` sigue aislado detrás de la interfaz; QuBeKa no ha publicado una estrategia distinta — cuando lo haga, se implementa la interfaz sin tocar el resto |
| Comparación intra-sesión (universo 2) y contradicciones con datos reales | La sesión de prueba real no produjo contradicciones (`contradicciones: null`); el render está testeado con la estructura del contrato, la detección es capacidad de QBK |
| **Reconectar el repo QBK en /settings** si el token del entorno cambió | El E2E usó un token nuevo (`qk:1:e2e-doc-ola3`); el repositorio activo de Kuestion quedó apuntando a él |
| `D.1` formal: crear `CONTRATO_API_OLA3.md` consolidado | El contrato de documentos vive hoy en `CONTRATO_API_REVISION.md` §2.5 (v1.6) de QuBeKa; decidir con producto si se consolida en un documento propio |

## 7. Cómo probarlo (resumen para el usuario)

1. Levantar servicios: `./scripts/dev-qbk.sh start`.
2. En http://localhost:8001 → botón **"Subir documento"** junto a Preguntar/Aportar.
3. Subir un PDF/DOCX/TXT/MD real → "Procesando documento..." → al terminar,
   "Se propusieron N nodos... [Revisar]".
4. En **Revisar** → el documento aparece como un ítem → "Revisar documento" →
   nodos agrupados por tipo con checkboxes → deseleccionar algunos →
   "Aprobar seleccionados" (o "Rechazar todo").
5. Si QuBeKa detecta contradicciones, la advertencia ámbar aparece antes de aprobar
   (no bloquea).
