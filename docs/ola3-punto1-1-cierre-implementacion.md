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
- **Verificación visual en navegador real (E.3):** **PENDIENTE — sin navegador disponible en este
  entorno.** Cubierto por tests de render (`assertSee` del copy, descripciones y botones; flujo
  feliz sin rastro del panel). Pendiente de inspección devtools por parte de producto/QA
  (~10 min: bandeja → "Revisar documento" → deseleccionar un padre → "Aprobar seleccionados"
  → advertencia → "Volver a la selección" → checkboxes intactos → "Confirmar aprobación").
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

## 7. Pendientes

- **E.3 — verificación visual devtools en `:8001`** (declarado en §5; requiere navegador).
- Limpieza de las sesiones de prueba 64/65 en QuBeKa si producto lo considera (quedaron
  `promocionadas` con nodos reales en el grafo de prueba).
