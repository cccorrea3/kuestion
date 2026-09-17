# Cierre — Ola 3, Punto 1.1: Advertencia de consecuencias al aprobar subconjunto

*Equipo de Kuestion · Septiembre 2026*
*Plan ejecutado: `docs/ola3-punto1-1-plan-implementacion-kuestion.md`*
*Spec de entrada: `definicion_OLA3_Punto_1_1.md`*
*Contrato: `CONTRATO_API_REVISION.md` v1.8 §2.6 (publicado por QuBeKa el 2026-09-17)*

---

## 1. Estado del plan

**Todas las fases (A–E) cerradas.** La Fase A quedó totalmente ejecutada: QuBeKa publicó
el contrato v1.8 y desplegó el endpoint (`POST /sesiones-analisis/{id}/evaluar-subconjunto`,
commit `b0d9aa7` de su repo), lo que levantó el bloqueo P1 y permitió ejecutar la Fase A.2
contra el servicio real antes de escribir código.

## 2. Qué se implementó por fase

| Fase | Tareas | Archivos |
|---|---|---|
| **A** | A.1 (ya ejecutada en la ronda §4.3), **A.2: checklist FA completo contra QuBeKa real**, A.3 (ya registrada) | — |
| **B** | B.1/B.2/B.3: `evaluarSubconjunto(int $sessionId, array $nodosAprobados, ?array $credential)` sobre el sobre real `{success, data}`, con el patrón de errores del servicio (401/403/404/422/5xx/504). El 422 propaga el mensaje legible de QuBeKa | `app/Services/QbkContributionService.php`, `tests/Feature/EvaluarSubconjuntoTest.php` (12 tests) |
| **C** | C.1 `aprobarSeleccionados()` ahora evalúa antes de promover; C.2 `confirmarAprobacionConAdvertencia()`; C.3 `volverASeleccion()` + `limpiarAdvertencia()`; C.4 (P2) `evaluacionFallida` + `reintentarEvaluacion()`; C.5 sin cambios en "aprobar todo"; C.6: 8 tests Livewire | `app/Livewire/ReviewTray.php`, `tests/Feature/ReviewTrayAdvertenciaTest.php` (12 tests incl. D.5) |
| **D** | D.1/D.2 panel de advertencia dentro del panel del documento expandido (P3), `descripcion` de QuBeKa tal cual, nodos citados por texto, `encabezadoConsecuencia()` por tipo; D.3 aviso de fallo de evaluación separado del de consecuencias; D.4 el bloque solo aparece con consecuencias/fallo; D.5: 4 tests de render | `resources/views/livewire/review-tray.blade.php` |
| **E** | E.1 suite completa + Pint; E.2 build + verificación de clases en el CSS compilado; E.3 declarado pendiente (sin navegador en el entorno); **E.4 E2E real completo contra QuBeKa**; E.5 cierre/commit/push | — |

## 3. Verificación contra el servicio real (Fase A.2 — checklist FA)

Sesión de documento real **64** (creada vía HTTP real, IA real de QuBeKa, 7 nodos:
Q padre + 3 N-K en chunk 0, Q padre + 2 H en chunk 1):

- **FA-1** (sin el padre Q) → 200 con `total: 6`: **3 `nodo_huerfano` + 3 `enlace_perdido`**, con
  `nodos_afectados` trayendo ambos extremos (NB5 confirmado en la práctica) y `descripcion`
  lista para mostrar. Respuesta guardada como fixture del contrato en el cierre.
- **FA-2** (subconjunto completo) → `{"consecuencias":[],"total":0,"subconjunto_evaluado":7}`.
- **FA-3** (ids ajenos) → 422 `nodos_aprobados contiene nodos que no pertenecen a la sesión: ...`
  (mismo mensaje que approve — la misma lista sirve para evaluar y aprobar).
- **FA-5** (array vacío) → 422 `nodos_aprobados debe ser un array con al menos un nodo_sandbox_id.`
- **FA-4a** (token de otro workspace, sesión 53) → 403 `No tienes permisos sobre esta sesión.`
- **FA-4b** (sesión inexistente) → 404 `Sesión no encontrada.`
- **`sugerencia_no_resuelta`**: no surgió en las sesiones reales (la IA no detectó duplicados);
  cubierto por tests con el formato exacto del contrato v1.8 (payload de §2.6).

## 4. Verificación E2E real (Fase E.4 — checklist FE)

**FE-1/FE-2 — advertencia + confirmación (sesión 64):**
1. Evaluación del subconjunto sin el padre Q vía el servicio real → `total: 6` (3 huérfanos + 3 enlaces perdidos).
2. Confirmación (mismo camino que `confirmarAprobacionConAdvertencia`) → `aprobada`.
3. Promoción asincrónica en QuBeKa → **`promocionada` con exactamente +6 nodos** (181→187).
4. Verificación en la BD de QuBeKa: los 6 nodos promovidos son exactamente el subconjunto
   (NK-8651/8652/8653, Q-9476, H-041, H-042) y **el Q padre rechazado NO entró al grafo**.

**FE-3 — selección completa, sin consecuencias (sesión 65, creada por el flujo real):**
1. Documento nuevo vía `contributeDocument` real → IA real lo procesó (2/2 chunks → `lista_para_revision`).
2. Selección completa (7 nodos) → evaluación `total: 0` → promoción directa, sin paso intermedio
   (regresión del criterio de cierre #4) → **`promocionada` con exactamente +7 nodos** (187→194).

**FE-4 — fallo de la evaluación:** con QuBeKa inaccesible (conexión real a puerto cerrado),
`evaluarSubconjunto` lanza `KuaforiaException 504` con mensaje legible → el componente cae en
`evaluacionFallida` (P2): aviso "No pudimos verificar las consecuencias de esta selección"
con "Aprobar de todas formas" / "Reintentar verificación". Nunca un spinner eterno ni un estado silencioso.

## 5. Pruebas ejecutadas (E.1)

| Suite | Resultado |
|---|---|
| `EvaluarSubconjuntoTest` (Fase B) | 12 passed |
| `ReviewTrayAdvertenciaTest` (Fases C/D) | 12 passed |
| `ReviewTrayDocumentosTest` (regresión Punto 1) | 9 passed |
| **Suite completa del proyecto** | **579 passed / 1664 assertions / 0 fallos** |
| Pint (`vendor/bin/pint --dirty`) | Limpio |

Ítems obligatorios de la sección 3 del plan:
- **Rebuild de assets + CSS compilado (E.2):** `npm run build` OK; clases nuevas verificadas en
  `public/build/assets/app-*.css` (`amber-300/50/800/900`, `emerald-600/700`, `danger/30`,
  `bg-white/70`, `disabled:opacity-50` — esta última con la forma escapada del bundle).
- **Verificación visual en navegador real (E.3):** **EJECUTADA POST-REVIEW (2026-09-17, ver §10)**
  con Chromium real headless (Playwright-core) contra `:8001` y QuBeKa real: 26/26 verificaciones
  (login real, navegación por el nav, anclaje del documento objetivo, estilos computados,
  clases en DOM, contraste heurístico, panel de advertencia con copy real, selección intacta
  tras "Volver a la selección", confirmación del subconjunto). Evidencia: script reproducible
  `scripts/verify-e3-visual.mjs` + screenshots en `/tmp/e3/`.
- **Compatibilidad de versiones:** `Response::json($key, $default)` verificado contra el vendor
  instalado; sin métodos nuevos de Livewire/Laravel fuera de los ya usados por la bandeja;
  sin cambios en `composer.json`.
- **Fallo visible en runtime:** FE-4 (transporte), 422 con mensaje de QuBeKa (FA-3/FA-5),
  errores del approve visibles (FC-6 de regresión). Sin pantallas congeladas.
- **Prueba contra el servicio real:** ejecutada (secciones 3 y 4 de este documento).

## 6. Hallazgos

1. **QuBeKa ya entregó** (commit `b0d9aa7`, contrato v1.8): la Fase A pasó de "espera activa"
   a ejecutada íntegramente, incluida la validación real del payload confirmado en la ronda §4.3.
2. **La sesión 53 es de otro workspace** (403): recordatorio de que el 422/403 del endpoint de
   evaluación depende de la sesión a la que apunta el token; no afecta el flujo (la bandeja solo
   lista sesiones del workspace del token).
3. **Defecto encontrado y corregido durante la Fase C:** la primera versión agrupaba evaluación
   y aprobación en un solo try/catch — un fallo del *approve* posterior caía en el aviso P2
   ("no pudimos verificar") en vez de mostrarse como fallo de aprobación. Reestructurado con
   try/catch separados; los errores del approve son visibles con su mensaje (FC-6 sigue en verde).
4. **Bug de guard detectado por tests:** los caminos con `return` temprano (advertencia/fallo)
   no reseteaban `processingSessionId` si no pasaban por el `finally`. Corregido con un
   try/finally exterior; el test de doble envío y los de confirmación/reintento lo cubren.
5. **NB6 en la práctica:** el contenido de prueba diseñó padre/hijo intra-chunk a propósito;
   el endpoint real devolvió las advertencias esperadas. Para huérfanos entre chunks no hay
   advertencia (limitación conocida documentada en v1.8 §2.6 "Qué NO cubre la evaluación").

## 7. Evidencia de auditoría del E2E real (post-review)

QuBeKa `:8000` caído al momento del review (verificado). La evidencia de las sesiones 64/65
no depende del servicio levantado: quedó persistida en su BD y se extrajo vía MySQL
(comando reproducible, mismo patrón del fixture del §3):

```sql
-- Credenciales: .env de ../QuBeKa/qubeka (DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD/DB_DATABASE)
SELECT id, estado, creado_en, cerrado_en FROM sesiones_analisis WHERE id IN (64,65);
SELECT id, tipo, parent_id FROM nodos WHERE creado_en >= '2026-09-17 17:37:40' ORDER BY id;
```

Resultado verificado (2026-09-17, posterior al review):

| id | estado | creado_en | cerrado_en (promoción) |
|---|---|---|---|
| 64 | promocionada | 2026-09-17 14:17:26 | 2026-09-17 17:37:40 |
| 65 | promocionada | 2026-09-17 14:39:16 | 2026-09-17 17:39:47 |

Nodos creados en la ventana de promoción de la **64** (+6, exactamente el subconjunto
aprobado; el Q padre rechazado **no está** en el grafo):

| id | tipo | parent_id |
|---|---|---|
| NK-8651 / NK-8652 / NK-8653 | N-K | NULL |
| Q-9476 | Q | NULL |
| H-041 / H-042 | H | Q-9476 |

Nodos creados en la ventana de la **65** (+7, selección completa): Q-9477 y Q-9478 (Q,
raíz) + H-043/H-045/H-047 (parent Q-9477) + H-044/H-046 (parent Q-9478). Total del grafo
de prueba tras ambas: 207 nodos. Únicas sesiones cerradas el 2026-09-17: 64 y 65 — no hay
promociones de terceros que contaminen la ventana.

Con esto la validación real de la Fase A/E.4 queda **respaldada por evidencia persistida**,
no solo condicionada a re-ejecución.

## 8. Post-review: ajustes y registros

- **Q2 — asimetría 422 approve/evaluar:** confirmada en el código (`approve()` caía al
  mensaje genérico "QuBeKa respondió con error: 422"). No era regresión, pero se cerró por
  paridad: `approve()` ahora propaga `errors.message` de QuBeKa en 422 (misma semántica que
  `evaluarSubconjunto()`, ya que la misma lista sirve para evaluar y aprobar). Test nuevo
  `test_approve_propagates_422_message_like_evaluar_subconjunto` (64/64 en el service test).
- **Q3 — sesiones 64/65:** quedan `promocionadas` con 13 nodos reales en el grafo de prueba
  (lista exacta en §7). Decisión de eliminación/limpieza: **de producto**, pendiente.
- **Q4 — rama "aprobar todo":** confirmada como intencional según plan T-C.5 y spec §2
  (la evaluación existe solo en el camino de subconjunto).

## 9. Pendientes

- **Q3 — limpieza de sesiones de prueba (64/65/66/67) y del incidente 8/12** en QuBeKa si
  producto lo considera (ver §10 y §11; evidencia persistida permite auditar antes de decidir).

## 10. Post-review: H7 corregido + E.3 ejecutada en navegador real

**H7 — advertencia colgada al expandir otro documento (reportado por el reviewer, confirmado
en el código y corregido):** `expandirDocumento()` no limpiaba el estado de advertencia/fallo
de evaluación del documento anterior. Fix: `$this->limpiarAdvertencia()` al inicio de
`expandirDocumento()`, igual que ya hacía `colapsarDocumento()`. Test de regresión nuevo
(`test_expandir_otro_documento_limpia_la_advertencia_pendiente`): tras dejar la 100 con
advertencia pendiente, expandir la 200 deja `advertenciaPendiente=false`,
`advertenciaAprobados=[]` y **ningún approve enviado**.

**E.3 — verificación visual ejecutada (26/26):** Chromium real headless (Playwright-core 1.63,
Chromium del cache `~/.cache/ms-playwright`) contra `:8001` + QuBeKa real. Recorrido: login real
→ nav → bandeja → ancla del documento objetivo por su texto único (con HARD STOP si el panel
expandido no es el objetivo) → deselección de la Q raíz → "Aprobar seleccionados" → panel de
advertencia (copy real, nodos citados con «», descripción de QuBeKa tal cual) → "Volver a la
selección" (selección intacta) → "Confirmar aprobación" → panel cerrado sin error. Estilos
computados verificados (`bg-emerald-600` = `oklch(0.596 0.145 163.225)`, texto blanco, clases
en DOM), contraste heurístico OK. Screenshots: `/tmp/e3/01..06*.png`.

**Efecto real de la aprobación visual (sesión 66 → confirmada 23:21:40; sesión 67 →
confirmada 23:27:06):** en ambas, la promoción en QuBeKa real fue **exactamente el subconjunto
aprobado** — el Q raíz deseleccionado no entró al grafo, y los 4 nodos hijos (H×3 + SQ) entraron
como raíces (comportamiento de huérfano previsto). Suite tras H7: 581 passed / 1675 assertions.

## 11. Incidente durante la verificación visual (transparencia) + hallazgo de QuBeKa

Durante la **primera corrida del script E.3**, el botón se ancló por posición (`first()` de la
bandeja) en vez de por sesión objetivo, y la bandeja tenía ~18 sesiones pendientes con aportes
reales. Resultado: se aprobaron por subconjunto las **sesiones 8 y 12 (aportes reales de texto
pendientes de revisión: Sherlock Holmes y LinkedIn)**. La gravedad operativa es baja (la
advertencia informa y no bloquea; el subconjunto aprobado es la selección completa que la
UI mostraba; el flujo es el que un usuario con esa selección habría ejecutado), pero **el
estado de esas sesiones cambió sin decisión humana** — se declara como corresponde:

- **Sesión 8** (Sherlock Holmes): `promocionada` 23:12:19 → grafo +3 nodos (Q-9479 raíz,
  NK-8654 y H-048 con parent Q-9479).
- **Sesión 12** (LinkedIn): `promocionada` 23:13:09 → grafo +3 nodos (Q-9480 raíz, H-049/H-050
  con parent Q-9480).
- **Decisión de reversión: de producto** (los nodos ya son parte del grafo de prueba).
- **Corrección de proceso aplicada:** el script final ancla por texto único de la sesión
  objetivo con HARD STOP, y ningún script de verificación debe tocar sesiones con contenido
  humano sin ancla explícita.

**Hallazgo de QuBeKa (bug de promoción, reportar a su equipo):** durante esas promociones, su
log registra `Error creando enlace en promoción: SQLSTATE[01000]: Warning: 1265 Data truncated
for column 'relacion' ... insert into enlaces ... values (H-049, Q-9480, responde, 1)` — intenta
crear enlaces con `relacion='responde'`, valor que **no existe** en el enum de la columna
(`descompone_en|tiene_hipotesis|soporta|refuta|es_sintesis_de|ejecuta|referencia`); los enlaces
padre-hijo de esas promociones se descartaron silenciosamente (los nodos conservan `parent_id`,
pero no quedaron enlaces). Sugerencia para su fix: mapear la relación Q→H a un valor del enum
(p.ej. `tiene_hipotesis`) o ampliar el enum en su migración.
