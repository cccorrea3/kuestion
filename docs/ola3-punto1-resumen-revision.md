# Resumen de Revisión — Ola 3, Punto 1: Subida de documentos

*Revisor independiente · Septiembre 2026*
*Objetivo auditado: commit `011e37e` (`feat ola3-p1: subida de documentos`) de Kuestion*
*Plan de referencia: `docs/ola3-punto1-plan-implementacion-kuestion.md` + `OLA_3_Punto_1.md`*

---

## 1. Veredicto

**NO APROBADO tal como venía el commit; corregido en el working tree.**

La implementación de las Fases A y C es fiel al plan y verificada contra el contrato
real de QuBeKa. La Fase B arrastraba **1 defecto funcional crítico**: el polling de 5 s
que exige B.5 nunca se disparaba (el estado quedaba colgado en "Procesando…" para
siempre). El defecto se corrigió y se sumó cobertura de tests.

Estado final verificado: **551 passed / 1563 assertions / 0 failed.**

---

## 2. Hallazgo crítico y fix aplicado

### 2.1 Polling nunca disparado — estado "procesando" colgado (Crítica)

**Hallazgo:** `pollProgreso()` (UploadDocument.php:310) solo se invoca desde
`reintentar()`, visible únicamente en estado `error`. La vista `upload-document.blade.php`
en estado `procesando` **no tenía `wire:poll`** y `mount()` no lo llamaba. Resultado:
tras un `submit()` exitoso, la UI quedaba en "Procesando documento..." indefinidamente —
ni recargando la página avanzaba (mount() retomaba `procesando` y volvía a colgarse).
Violaba B.5 (polling cada 5 s) y B.7 (nunca "cargando" infinito).

El test existente `test_polling_progreso_y_resultado_final` pasaba porque invocaba
`pollProgreso()` directamente vía `->call()`, sin cubrir el wiring real de la UI.

**Fix aplicado (raíz):**
- `resources/views/livewire/upload-document.blade.php` — `wire:poll.5s="pollProgreso"` en el bloque `procesando`.
- Se refleja el progreso en vivo (`chunksProcesados`/`chunksTotales` por ciclo de poll).

---

## 3. Hallazgos y acciones aplicadas (menores)

### 3.1 Timeout de análisis de 10 min ausente (B.6/§4)

`timeout_segundos = 600` existía en config pero no era consumido por nadie (ni scheduler,
ni poll). Una carga en `procesando` podía quedar en ese estado sin límite.

**Fix:** en `pollProgreso()` (UploadDocument.php:333–348), si una carga en `procesando`
supera `timeout_segundos` sin llegar a estado final, se marca `error` con mensaje claro
y queda retenida para reintentar (FB-9).

### 3.2 Límite MAX_CHUNKS de QuBeKa sin violar del lado cliente

QuBeKa limita a `MAX_CHUNKS = 120` chunks por documento (AnalisisService.php:22;
ContributeController.php:131 valida `chunks|max:120`). Kuestion no limitaba del lado de
envío: un documento que extrajera >120 chunks era rechazado por QuBeKa con un 422 opaco.

**Fix:** guard temprano en `iniciarCarga()` con mensaje legible, configurable vía
`kuestion.documentos.max_chunks` (default 120, alineado al valor real de QuBeKa).

### 3.3 php.ini — directivas duplicadas (entorno)

`/etc/php/8.2/cli/php.ini` tenía `post_max_size`/`upload_max_filesize` definidos dos
veces (8M/2M originales + 25M/30M de la sección Kuestion). PHP toma la última, por lo que
el runtime reportaba 25M/30M, pero las duplicadas confundían ediciones futuras.

**Fix:** directivas alineadas a un único valor coherente (25M/30M). No hay php-fpm
instalado en esta caja (entorno dev con `php artisan serve`), por lo que estos valores
quedan como referencia única para el despliegue futuro de FPM.

---

## 4. Qué está correcto (verificado contra QuBeKa real)

- **Contrato v1.6 al día:** `POST /contribute/document` existe en QuBeKa
  (routes/api.php:53) y valida exactamente el payload que arma
  `QbkContributionService::contributeDocument` (`chunk_id`, `texto`, `pagina_origen`,
  `orden`, `contexto_opcional`, `hash_documento`).
- **Detalle de sesión:** `chunks_procesados`, `chunks_totales`, `contradicciones`,
  `is_simple`, `nodos{id = nodo_sandbox_id}` presentes en `SesionAnalisisController::show`.
- **Estados QBK** (`procesando`, `lista_para_revision`, `aprobada`, `promocionada`,
  `rechazada`, `error`) coinciden con los normalizados en Kuestion.
- **Fase A:** TXT/MD directo, PDF con smalot (páginas), DOCX con phpoffice (headings),
  PDF escaneado → `DocumentoEscaneadoException` ("No OCR"), corrupto → mensaje legible.
  Coincide FA-1..FA-7.
- **Fase C:** deselección por nodo → payload `nodos_aprobados`/`nodos_rechazados` exacto
  contra el approve real de QuBeKa; `revisado_por_*` preservado; guard de doble envío;
  contradicciones como advertencia no bloqueante (nunca inventadas).
- **Duplicado FB-8:** aviso previo no bloqueante con continuar/cancelar; hash sha256
  sobre texto extraído (mismo contenido en PDF/DOCX → mismo hash).
- **Reintentos B.6:** 3 con backoff exponencial solo ante error de red/timeout (504/0),
  nunca en 401/403.
- **Owner-checks** correctos en toda la ruta (`pollProgreso` valida `user_id`;
  FKs `user_id→users.uuid` cascade, `repository_id→repositories` nullOnDelete).
- **php.ini:** 25M/30M verificados en runtime.

---

## 5. Observaciones de calidad (no bloqueantes, no corregidas)

Estas quedan anotadas para decisión futura; no impiden el flujo:

1. **Doble extracción del documento:** `submit()` extrae una vez para el hash y de nuevo
   en `iniciarCarga()` — el parseo de PDF/DOCX se hace dos veces por subida.
2. **No-op confuso:** `'chunks_totales' => $upload->chunks_totales ?: $upload->chunks_totales`
   (UploadDocument.php, rama de estado final) — se asigna el valor a sí mismo.
3. **Métrica mezclada:** `'chunks_procesados' => $upload->chunks_procesados ?: $nodos` —
   si QuBeKa no trae progreso, se escribe "nodos propuestos" en el campo de chunks.
4. **Límite de páginas solo efectivo para PDF:** en DOCX las unidades tienen `pagina=null`,
   por lo que el conteo del flujo real nunca supera 100 (FA-6 aplica en la práctica solo a PDF).
5. **Enumeración de página en PDF:** `count($unidades)+1` (no la página real del PDF);
   una página en blanco intermedia desfasa las siguientes.
6. **Assert duplicado** en `test_flujo_simple_regresion` (ReviewTrayDocumentosTest.php:278-280).
7. **Higiene de repo:** 5 archivos sin commitear de la sesión anterior de ola2-p5
   (ContributionReview, AnswerChangedNotification, EmailDispatcher + 2 tests) — ajenos a
   este PR; explicaban el desfase 548/1554 (cierre) vs 549/1558 (suite con working tree).

---

## 6. Tests

- Suite completa ejecutada en verde: **551 passed / 1563 assertions** (~163 s).
- El cierre del commit reportaba 548/1554: la diferencia son los cambios **sin commitear**
  de ola2-p5 (+1 test / +4 assertions) y los 2 tests nuevos del fix de este review.
- Cobertura nueva: `test_vista_procesando_activa_polling_automatico` (wiring del poll) y
  `test_timeout_de_10min_marca_carga_fallida` (timeout B.6).

---

## 7. Preguntas abiertas

1. **Retoma de sesión duplicada:** QuBeKa devuelve `duplicado_detectado`/`sesion_previa_id`
   en `contributeDocument`, pero Kuestion los descarta. Un timeout del POST con reintento
   puede crear dos sesiones QBK para el mismo documento. ¿Debiera retomarse la sesión previa?
2. **Idempotencia del reintento:** el POST /contribute/document no es idempotente desde
   Kuestion (depende del dedup por `hash_documento` del lado QBK). ¿Se acepta como está?
3. **E2E real "sesión 62":** el cierre lo declara validado con polling — sin `wire:poll`
   el flujo no avanzaba solo en navegador. ¿Cómo se validó (recarga manual o llamando el método)?

---

## 8. Resumen de cambios (working tree)

| Archivo | Cambio |
| --- | --- |
| `resources/views/livewire/upload-document.blade.php` | `wire:poll.5s="pollProgreso"` en estado `procesando` |
| `app/Livewire/UploadDocument.php` | timeout 10 min + reflejo de progreso + guard `max_chunks` |
| `config/kuestion.php` | `documentos.max_chunks = 120` |
| `tests/Feature/UploadDocumentTest.php` | 2 tests nuevos (wiring poll + timeout) |
| `/etc/php/8.2/cli/php.ini` | directivas upload límites únicas/coherentes (25M/30M) |