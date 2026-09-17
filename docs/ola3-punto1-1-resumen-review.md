# Resumen del review — Ola 3, Punto 1.1: Advertencia de consecuencias al aprobar subconjunto

*Equipo de Kuestion · Septiembre 2026*
*Implementación: `docs/ola3-punto1-1-resumen-implementacion.md` · Cierre técnico: `docs/ola3-punto1-1-cierre-implementacion.md`*
*Alcance de este documento: cronología, hallazgos del review y su resolución — no repite el detalle técnico del cierre.*

---

## 1. Contexto

El Punto 1.1 fue implementado y sincronizado (commit `e60e6ae`), y luego pasó por **tres rondas
de review** (dos del reviewer externo y una auditoría del código generado). Este documento resume
qué llegó en cada ronda, qué se resolvió y con qué evidencia. El detalle técnico de cada fix está
en el cierre (§8, §10 y §11); aquí solo se referencia.

## 2. Cronología de las rondas

| Ronda | Origen | Contenido | Resultado |
|---|---|---|---|
| **1** | Review del código generado (mensaje del equipo) | 1 pregunta bloqueante (E.4: cómo se validó la sesión 62) + 4 observaciones (límite 100 páginas solo PDF, `chunks_procesados` mezclado con nodos, duplicados/idempotencia, higiene de repo) | **Todo respondido y cerrado** — commits `5f3745f` y `ba83dd7`. Nota: corresponde al Punto 1 (Ola 3), no al 1.1; quedó documentado en `docs/ola3-punto1-resumen-revision.md` |
| **2** | Review del Punto 1.1 (post-implementación) | 4 preguntas: evidencia reproducible del E2E, asimetría 422 approve/evaluar, limpieza de sesiones 64/65, rama "aprobar todo" intencional | **Todo respondido** — commit `f4cac29` (paridad 422 + evidencia de auditoría de BD en cierre §7) |
| **3** | Segunda ronda del reviewer del Punto 1.1 | 1 hallazgo pendiente: advertencia colgada al expandir otro documento (`expandirDocumento()` no llama a `limpiarAdvertencia()`) | **Confirmado, corregido y verificado** — commit `dacf4be` (fix + test de regresión + E.3 visual ejecutada) |

## 3. Hallazgos recibidos y su resolución

### H1 — Evidencia reproducible del E2E real (ronda 2, Q1)

**Pedido:** las sesiones 64/65 no eran audibles con `:8000` caído; se pidió dejar servicios arriba
o un extracto para auditoría, como el fixture del cierre §3.

**Resolución:** la evidencia **no dependía del servicio levantado** — quedó persistida en la BD de
QuBeKa. Se extrajo vía MySQL con SQL reproducible y se agregó al cierre como **§7 "Evidencia de
auditoría"**: sesiones 64/65 `promocionada`, ventana de la 64 con exactamente +6 nodos (el Q padre
rechazado **no** entró al grafo), ventana de la 65 con +7, y verificación de que fueron las únicas
sesiones cerradas ese día (sin contaminación de terceros). La validación real quedó respaldada por
evidencia persistida, no condicionada a re-ejecución.

### H2 — Asimetría 422 entre approve y evaluarSubconjunto (ronda 2, Q2)

**Pedido:** confirmar que el 422 del approve propagaría también `errors.message`, o dejar la
asimetría documentada.

**Resolución:** asimetría confirmada en el código (`approve()` caía al mensaje genérico) y **cerrada
por paridad**: dado que la misma lista sirve para evaluar y aprobar, `approve()` ahora propaga el
mensaje legible de QuBeKa en 422 con la misma semántica. Test nuevo
`test_approve_propagates_422_message_like_evaluar_subconjunto`. No era una regresión (el approve no
había cambiado), pero el review tuvo razón en que valía cerrarla antes de que alguien dependiera del
comportamiento genérico.

### H3 — Limpieza de sesiones de prueba (ronda 2, Q3)

**Pedido:** decisión de producto sobre las sesiones 64/65 `promocionadas` en el grafo de prueba.

**Resolución:** quedan como están, **pendiente de decisión de producto** — con la lista exacta de
los 13 nodos (id/tipo/parent_id) en el cierre §7 para auditar antes de decidir. Posteriormente se
agregaron las sesiones 66/67 (creadas para la E.3) y las 8/12 del incidente (ver §4). Todas
agrupadas en el mismo pendiente del cierre §9.

### H4 — Rama "aprobar todo" intencional (ronda 2, Q4)

**Pedido:** confirmar que la rama "aprobar todo" (hallazgo 4.3) es intencional, ya que el plan
literal pedía no tocar ese camino.

**Resolución:** confirmado como **intencional** según plan T-C.5 y spec §2: la evaluación existe
solo en el camino de subconjunto; `aprobar()` mantiene su flujo directo sin evaluación. Verificado
contra el código, no solo contra el plan.

### H5 (H7 del cierre) — Advertencia colgada al expandir otro documento (ronda 3)

**Hallazgo del reviewer (confirmado empíricamente por él):** `expandirDocumento()`
(`ReviewTray.php:420-460`) no llama a `limpiarAdvertencia()`. Tras dejar la sesión A con advertencia
pendiente y expandir la B, quedan `advertenciaPendiente=true`, `advertenciaAprobados` con ids
congelados de A y `docSessionId=B` — el panel "raja" el copy de la advertencia de A sobre el
documento B, y `confirmarAprobacionConAdvertencia()` intentaría aprobar B con los ids de A
(QuBeKa devolvería 422/404: fallo visible pero con contexto engañoso). **Fix propuesto de una
línea:** `$this->limpiarAdvertencia();` al inicio de `expandirDocumento()`, igual que hace
`colapsarDocumento()`.

**Resolución:** el reviewer tenía razón. Se aplicó exactamente el fix propuesto
(`ReviewTray.php:441`) con un test de regresión que replica su escenario: la 100 con advertencia
pendiente → expandir la 200 → `advertenciaPendiente=false`, `advertenciaAprobados=[]`,
`evaluacionFallida=false` y **ningún approve enviado** (ni a la 100 ni a la 200). La misma corrida
cerró la verificación visual E.3 (ver H6).

### H6 — Verificación visual E.3 pendiente (rondas 2 y 3)

**Pedido implícito en ambas rondas:** la E.3 había quedado declarada como pendiente ("sin navegador
en el entorno"); el reviewer pidió antes cerrar el punto una prueba que ejerciera el camino visual
real.

**Resolución:** **ejecutada de verdad** — 26/26 verificaciones en Chromium real headless
(Playwright-core 1.63, Chromium del cache del entorno) contra `:8001` + QuBeKa real. Recorrido:
login real → nav → bandeja → ancla del documento objetivo por texto único (con HARD STOP si el
panel no es el objetivo) → deselección de la Q raíz → advertencia con el copy real → "Volver a la
selección" (selección intacta) → "Confirmar aprobación" → promoción exacta del subconjunto en
QuBeKa real. Estilos computados y contraste verificados; screenshots en `/tmp/e3/`; script
reproducible en `scripts/verify-e3-visual.mjs`. Con esto **el Punto 1.1 no tiene pendientes de la
sección 3 del plan**.

## 4. Incidente declarado durante la E.3 (transparencia)

La **primera corrida** del script E.3 ancló el click por posición (`first()` de la bandeja) en vez
de por sesión objetivo, y la bandeja tenía ~18 sesiones pendientes con **aportes reales**. Resultado:
se aprobaron por subconjunto las **sesiones 8 (Sherlock Holmes) y 12 (LinkedIn)** a las 23:12/23:13
— grafo +6 nodos en total. La operativa equivale a la que un humano habría ejecutado con esa
selección (la advertencia informa y no bloquea), pero **el estado cambió sin decisión humana**: se
declara como corresponde y la reversión/limpieza es decisión de producto.

**Corrección de proceso aplicada:** el script final ancla por texto único de la sesión objetivo con
HARD STOP, y ningún script de verificación debe tocar sesiones con contenido humano sin ancla
explícita. Detalle completo en el cierre §11.

## 5. Hallazgo de QuBeKa descubierto en el proceso

Durante las promociones del incidente, el log de QuBeKa registra
`Error creando enlace en promoción: ... Data truncated for column 'relacion' ... values (..., responde, ...)`:
su promoción intenta crear enlaces con `relacion='responde'`, valor **inexistente** en el enum de
`enlaces.relacion` (`descompone_en|tiene_hipotesis|soporta|refuta|es_sintesis_de|ejecuta|referencia`)
→ los enlaces padre-hijo de esas promociones se descartan silenciosamente (los nodos conservan
`parent_id`, pero no quedan enlaces). **Reportar a QuBeKa**: mapear Q→H a un valor del enum
(p. ej. `tiene_hipotesis`) o ampliar el enum en su migración. Detalle en el cierre §11.

## 6. Estado final

| Ítem | Estado |
|---|---|
| Hallazgos del review (H1–H5) | **Todos cerrados** con fix/test/evidencia según corresponde |
| E.3 (verificación visual) | **Ejecutada** — 26/26 en Chromium real; sin pendientes de la sección 3 del plan |
| Suite completa | **581 passed / 1675 assertions / 0 fallos**, Pint limpio |
| Commits de la resolución | `f4cac29` (paridad 422 + evidencia de auditoría) → `dacf4be` (H7 + E.3 visual) |
| Pendiente de producto | Limpieza/reversión de sesiones de prueba (64/65/66/67) e incidente (8/12) — cierre §9/§11 |
| Pendiente para QuBeKa | Bug del enum `relacion` en su promoción — cierre §11 |

---

*Resumen generado por el equipo de Kuestion tras cerrar las tres rondas de review. Fuentes:
`docs/ola3-punto1-1-cierre-implementacion.md` (§7, §8, §10, §11) y commits `f4cac29`/`dacf4be`.*
