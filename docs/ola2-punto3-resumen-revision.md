# Resumen de Revisión — Ola 2, Punto 3: Indicador de vigencia

*Revisor independiente · Septiembre 2026*
*Objetivo auditado: commit `e52c713` (HEAD original) de Kuestion*

---

## 1. Veredicto

**APROBADO con correcciones.** La implementación del Punto 3 es fiel al plan
(`docs/ola2-punto3-plan-implementacion-kuestion.md`) en casi todo: componente unificado,
confirmador tratado como *valor variable* (D-Confirmador), tooltip con fecha + quién reconfirmó,
acción extendida a `sin_dato`. La auditoría encontró **1 defecto real de UI**, mejoró la cobertura
de tests en los puntos que el cierre declaraba cubiertos sin estarlo, y resolvió un choque de
planes en el feed aplicando el criterio del usuario: **el Punto 3 es la evolución y manda sobre el
Punto 2**.

Estado final verificado: **467 passed / 1315 assertions / 0 failed**, Pint limpio global.

---

## 2. Hallazgos y acciones aplicadas

### 2.1 Defecto de UI — botón "Reconfirmar" visible en estado Vigente (Media)

**Hallazgo:** en el detalle, una pregunta QBK `confirmada` (verde "Vigente") mostraba el botón
"Reconfirmar". Causa raíz: `question-detail.blade.php` pasaba `action-method="reconfirmar"`
incondicionalmente y el componente solo evaluaba `@if ($actionMethod)`, sin guard por estado.
Contradecía la decisión D3 del plan (*"vigente → sin acción"*) y el propio cierre que afirmaba
"oculto en confirmada".

**Fix aplicado (raíz, protege a cualquier caller):**
`resources/views/components/vigencia-indicator.blade.php`
```blade
@if (in_array($estado, ['vencida', 'sin_dato'], true) && $actionMethod)
```

### 2.2 Decisión de evolución D.1 — el feed queda informativo (producto)

**Choque de planes:** Punto 2 (C.2, "la ligereza") pedía reconfirmar desde la card del feed;
Punto 3 (D.1, §2.2) ordena que el mini-badge sea informativo y la acción viva en el detalle.

**Criterio aplicado (indicado por el dueño de producto):** el roadmap itera funcionalidad, no
congela el estado T0 → **manda el Punto 3**. Se eliminó:

- El botón Reconfirmar de la card del feed (`question-card.blade.php`).
- El método `QuestionFeed::reconfirmar()` y sus imports huérfanos (`QuestionFeed.php`, −59 líneas).
- El toast que escuchaba sus eventos `reconfirmar-ok/error` (`question-feed.blade.php`, −18 líneas).

La acción queda disponible en detalle y bandeja.

### 2.3 Cobertura de tests mejorada

| Hueco encontrado | Acción |
|---|---|
| `test_detail_estado_verde_vigente` no verificaba la ausencia de botón (por eso el defecto 2.1 pasó el CI) | agregado `assertDontSee('Reconfirmar')` |
| Test del mini-badge del feed declaraba "sin botón dentro del enlace" sin verificarlo | renombrado a `test_feed_mini_badge_coherente_sin_boton` y ajustado a la decisión D.1 (badge sin botón) |
| 5 tests del feed cubrían el método eliminado `reconfirmar()`/copy de botón | eliminados o resemantizados (el patrón 401→`invalid` queda cubierto por detalle y bandeja) |

### 2.4 Flaky pre-existente corregido (fuera del scope P3, encontrado en la verificación)

`F38ReconfirmacionNoDisparaNotificacionTest:70` fallaba intermitente en suite completa: comparaba
contra `$fecha` capturado al inicio, pero `aplicarReconfirmacionLocal()` estampa `now()` fresco
(actualización optimista). Si el reloj cruzaba el segundo entre ambas llamadas, el test fallaba.
Se reemplazó por una tolerancia de 2 s con el motivo documentado (`ponytail:`).

### 2.5 Claims del cierre matizados

- **"Cubierto por test" (fix del feed):** era optimista — no había aserción estructural. Quedó sin
  efecto tras la decisión D.1 (el feed ya no tiene botón).
- **"Pint limpio":** fallaba en 2 archivos de Ola 1 (`ButtonLayoutTest`, migración
  `2026_09_03_000001`). Se ejecutó Pint sobre ellos y quedó limpio global.

---

## 3. Estado final de la solución

| Superficie | Comportamiento |
|---|---|
| **Detalle** | Indicador completo (4 estados), tooltip con fecha exacta + confirmador variable, botón Reconfirmar solo en `vencida`/`sin_dato` |
| **Feed** | Mini-badge informativo (estado + fecha), **sin botón** — la acción se abre en el detalle |
| **Bandeja** | Indicador completo con botón en vencida/sin_dato, expone confirmador + fecha |

## 4. Pendientes de producto (no resueltos por el revisor)

- **B2 (Kuaforia):** estado "Posiblemente obsoleto" mapeado en el componente pero sin señal real por
  respuesta — sigue bloqueado por el hallazgo 2 del plan. No se inventó un mecanismo.
- **Verificación visual en navegador real (FB.1–FB.6, FD.1):** checklist pendiente con los
  servicios arriba (`bash scripts/dev-qbk.sh start` → http://localhost:8001).
- **E2E real contra QuBeKa (PATCH reconfirmar):** claim del cierre no reproducible por el revisor
  sin servicios; avalado por mock FC y precedentes verificados del P2.

## 5. Archivos tocados por la revisión

| Archivo | Cambio |
|---|---|
| `resources/views/components/vigencia-indicator.blade.php` | guard de estado en el botón (defecto 2.1) |
| `app/Livewire/QuestionFeed.php` | eliminado `reconfirmar()` + imports |
| `resources/views/components/question-card.blade.php` | eliminado botón del feed (D.1) |
| `resources/views/livewire/question-feed.blade.php` | eliminado toast de eventos huérfanos |
| `tests/Feature/VigenciaIndicatorTest.php` | negativo en verde + test de feed sin botón |
| `tests/Feature/QuestionVigenciaReconfirmacionTest.php` | resemantización y limpieza de tests del feed |
| `tests/Feature/F38ReconfirmacionNoDisparaNotificacionTest.php` | fix de flaky (tolerancia 2 s) |
| `tests/Feature/ButtonLayoutTest.php`, `database/migrations/2026_09_03_000001_*.php` | Pint |
| `docs/ola2-punto3-cierre-implementacion.md` | refleja decisión D.1 y correcciones |

**Sin cambios de contrato:** no se añadió ni modificó ningún endpoint; el Punto 3 consume
`fecha_ultima_confirmacion` / `ultimo_confirmador_nombre` de `sources[]` (§5.2/§5.3) y el
endpoint de reconfirmación del Punto 2 (§5.1).