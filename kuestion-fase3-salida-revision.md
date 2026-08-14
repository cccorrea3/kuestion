# Kuestion — Fase 3 · Salida para revisión (Bloques 11–14)

> **Versión:** 1.0 | **Fecha:** 2026-08-14 | **Fuente:** `kuestion-fase3-plan-implementacion.md` v1.1 | **Estado:** Fase 3 implementada en su totalidad
>
> Documento de cierre siguiendo el formato del plan §5: por bloque — resumen ejecutivo, evidencia por criterio de aceptación, desviaciones, riesgos no previstos y preguntas abiertas. Autocontenido: no requiere volver al plan ni al maestro.

---

## §0 Resumen global

- **Alcance:** 4 bloques de la Fase 3 (usabilidad y claridad), implementados en orden **11 → 13 → 12 → 14** (12 al final del plan sugería la dependencia con 1.5, pero el Bloque 5 de la Fase 1 ya estaba hecho, así que se adelantó antes de 14).
- **Commits:** M26 `625225c` (Bloque 11) → M27 `8fe9501` (Bloque 13) → M28 `f8b98dc` (Bloque 12) → M29 `b279c13` (Bloque 14). Todos pusheados a `origin/main`.
- **Suite final:** **105 tests (291 assertions) pasan** (arrancó la fase con 83/243 — Fase 2 — y sumó 22 tests/48 assertions de la Fase 3).
- **Naturaleza de la fase:** **100% UI/frontend** + 3 migraciones inocuas de columnas de usuario (`has_seen_example`, `team_dashboard_access`). **No se tocó** REST/hash/job: el `git diff` de la fase no incluye `KuaforiaService`, `ChangeDetector` ni `CheckQuestionUpdatesJob`.
- **Hallazgos:** un **gap pre-existente corregido** (el feed ignoraba `?tag=` — ningún enlace de tag del índice funcionaba, Bloque 13) y una **decisión de HTML** (cards de tag con `<a>` anidados evitados, Bloque 13).
- **Todas las resoluciones de revisión v1.1 se aplicaron** tal cual: 11.4 pospuesto, 12.4 tendencias incluidas, 14.3 flag apagado por defecto, 12.3 solo activas, 11.3 flag persistente por usuario.

---

## §1 Por bloque

### Bloque 11 — Primera experiencia: onboarding con ejemplo interactivo (M26)

**Resumen ejecutivo:** `OnboardingExample` (Livewire) con datos **hardcodeados** (pregunta ficticia + respuestas v1/v2) cuyo diff se genera con **`DiffGenerator`** — el mismo motor del diff real — sobre strings ficticios, cero consultas a BD (11.1). Embed en el empty state del feed, debajo del CTA existente (11.2). "Omitir" persiste `has_seen_example` (boolean default false + cast) y oculta el ejemplo; no reaparece entre sesiones (11.3). 11.4 pospuesto según resolución de revisión.

**Evidencia por criterio de aceptación:**

| Criterio (maestro) | Cómo se verificó | Resultado |
|---|---|---|
| Diff visual antes de la primera pregunta (3.1) | Test: feed vacío renderiza el ejemplo; `simulateChange` → `status=diff` + línea añadida visible + botones Aceptar/Descartar | ✅ |
| El ejemplo no persiste en BD (3.1) | Test: tras simular + aceptar + descartar, `questions`/`answer_versions`/`notifications` en 0 | ✅ |
| Onboarding se puede saltar (3.1) | Test: `skip()` → `hidden=true` + `has_seen_example=true` en BD; el feed ya no lo renderiza | ✅ |

**Desviaciones:** ninguna — quedó exactamente como el plan. Los estados `accepted`/`dismissed` muestran tarjetas explicativas del flujo real. **Riesgos:** ninguno. **Preguntas abiertas:** ninguna.

### Bloque 13 — Señales de estado en el flujo existente (M27)

**Resumen ejecutivo:** badges de "sin revisar" por tag en `TagIndex` (segunda query, sin filtro de status para coincidir con lo que muestra el feed al hacer clic) (13.1); badge en cada card, oculto si 0, que enlaza a `filter=changes&tag=X` (13.2/13.4); **gap pre-existente cerrado**: `QuestionFeed` ahora soporta `?tag=` (queryString + `whereJsonContains` + `resetPage`) (13.3); timeline de versiones con ícono 👍/👎 por versión y trend "mejoró"/"empeoró" comparando con la versión anterior en el tiempo, todo en `@php` del loop sin tocar `QuestionDetail` (13.5); `FeedbackButtons` intacto (13.6).

**Evidencia por criterio de aceptación:**

| Criterio (maestro) | Cómo se verificó | Resultado |
|---|---|---|
| Badge de tag dinámico (3.3) | Tests: badge visible con cambios; ausente si 0; **desaparece al aceptar el cambio** | ✅ |
| Click en badge filtra tag + "con cambios" (3.3) | Tests: el badge enlaza a `filter=changes&tag=X`; el feed filtra por tag y combina tag+changes | ✅ |
| Línea de tiempo de feedback (3.4) | Test: versiones con feedback renderizan 👍/👎; sin feedback → sin ícono | ✅ |
| Mejoró/empeoró indicado (3.4) | Tests: `not_helpful→helpful` muestra "mejoró"; `helpful→not_helpful` muestra "empeoró"; sin feedback previo → sin etiqueta | ✅ |
| Feedback sigue simple (3.4) | `FeedbackButtons` sin cambios | ✅ |

**Desviaciones:**
1. La etiqueta de trend va en la **versión más nueva** de cada par (comparando con la anterior en el tiempo) — interpretación validada contra la verificación 3.1 del plan.
2. Card de tag reestructurado: `div` relativo + enlace interno + badge absoluto, en lugar de `<a>` anidados (HTML inválido y clics impredecibles); comportamiento del card intacto.

**Riesgos no previstos:** ninguno; el gap del feed era conocido del plan (§1.4). **Preguntas abiertas:** ninguna.

### Bloque 12 — Panorama de equipo: salud del tenant (M28)

**Resumen ejecutivo:** migración `team_dashboard_access` (string(20) default `'none'`) + cast (12.1); ruta `/team` + `TeamDashboard` con gate `abort_unless(readonly, 403)` (12.2); agregados **en vivo por `tenant_slug`** (única vista que cruza usuarios, aislada y protegida): total de activas, % con cambios sin revisar, top 5 tags — **solo activas** (resolución §6.4) (12.3); tendencias semanales desde `daily_metrics` (últimas 8 semanas por semana ISO, barras CSS puras) con **degradación con gracia** (12.4); nota de pie de solución temporal por roles + nota de privacidad (12.5). Link "Equipo" en la nav para usuarios `readonly` (mejora de UX agregada).

**Evidencia por criterio de aceptación:**

| Criterio (maestro) | Cómo se verificó | Resultado |
|---|---|---|
| Total, % sin revisar, top tags (3.2) | Test: 2 usuarios del mismo tenant → dashboard muestra **5 preguntas (40% con cambios)** incluyendo las del otro usuario | ✅ |
| Acceso controlado por `team_dashboard_access` (3.2) | Test: usuario `none` → **403** (`assertForbidden`); `readonly` → renderiza | ✅ |
| Solo lectura (3.2) | Revisión: el componente no expone métodos de escritura; solo computed properties de lectura | ✅ |
| Solo activas (resolución §6.4) | Test: preguntas `archived` con cambios **no cuentan** | ✅ |
| Tendencias con degradación (12.4) | Tests: sin `daily_metrics` → sección oculta (no falla); con datos → "5 creadas · 2 cambios" visibles | ✅ |

**Desviaciones:** link "Equipo" en la nav (no estaba en el plan — sin él, `/team` solo era accesible por URL); barras CSS puras sin librerías. **Riesgos:** ninguno. **Preguntas abiertas:** ninguna.

### Bloque 14 — Red de relaciones: visualización (M29)

**Resumen ejecutivo:** componente Blade `x-relations-graph` — ego-network de 1 salto (salientes + entrantes directos, nodos deduplicados) en **SVG server-side** (layout radial, centro teal / vecinos naranja, **sin librerías**), nodos clicables que navegan al detalle, aristas con label en tooltip (14.1); embed en el detalle tras backlinks (14.2); flag `config('kuestion.features.relations_graph')` default **`false`** (14.3), apagado hasta que el piloto acumule ~10 preguntas relacionadas (resolución §6.2).

**Evidencia por criterio de aceptación:**

| Criterio (maestro) | Cómo se verificó | Resultado |
|---|---|---|
| Grafo visible de un vistazo (3.5) | Tests: 2 relaciones → **3 círculos** (N+1 nodos) + aristas con label + URLs de los vecinos; dedupe de vecino compartido | ✅ |
| Visualización liviana (3.5) | `package.json`/`package-lock.json` **sin cambios** (verificado con `git diff`); SVG server-side | ✅ |
| Masa crítica (3.5) | Test: flag `false` (default) → el detalle no renderiza el grafo; `true` → aparece (test on/off sobre `QuestionDetail`) | ✅ |

**Desviaciones:** componente Blade en lugar de Livewire (sin estado → más liviano, testeable con `View::make`); label de arista en `<title>` (tooltip) en vez de texto sobre la línea (ilegible sobre el trazo); el componente es autocontenido y se oculta solo si no hay relaciones. **Riesgos:** ninguno. **Preguntas abiertas:** ninguna nueva — el punto de activación (masa crítica del piloto) es decisión de producto futura ya resuelta en §6.2.

---

## §2 Decisiones técnicas (con alternativa descartada)

| # | Decisión | Alternativa descartada | Razón |
|---|---|---|---|
| 1 | Diff del ejemplo con `DiffGenerator` sobre strings hardcodeados | Diff simulado a mano en la vista | Mismo motor y formato visual exacto que el diff real, cero BD |
| 2 | Flag `has_seen_example` persistente por usuario | Flag solo en sesión | Resolución §6.1: un onboarding que se repite deja de ser onboarding |
| 3 | Badge de tag sin filtro de status (igual que el feed `changes`) | Solo tags de preguntas activas | El número del badge coincide con lo que muestra el feed al hacer clic |
| 4 | Card de tag: `div` + enlace interno + badge absoluto | `<a>` anidado (badge dentro del card) | HTML inválido y clics impredecibles con `<a>` anidados |
| 5 | Trend de feedback en la versión más nueva del par | Etiqueta en la versión más vieja | Lectura natural "la percepción mejoró/empeoró respecto a la anterior" (validada contra la verificación 3.1) |
| 6 | Agregados del dashboard por `tenant_slug` (solo activas) | Por `current_user_id()` o incluyendo archivadas | Única vista que cruza usuarios (protegida por gate); resoluciones §6.4 |
| 7 | Tendencias desde `daily_metrics` incluidas con degradación con gracia | Sección "opcional" o excluida | Resolución §6.3: el Bloque 5 de Fase 1 ya existía |
| 8 | Barras CSS puras para tendencias | Librería de gráficos JS | Convención "crear, no instalar"; sin dependencias |
| 9 | Grafo como componente Blade (sin estado) | Componente Livewire | No hay estado ni acciones → más liviano y testeable |
| 10 | SVG server-side con nodos `<a>` + `wire:navigate` | Motor de grafo JS (d3, vis.js) | Criterio "visualización liviana"; masa crítica pequeña |
| 11 | Label de arista en `<title>` (tooltip) | Texto visible sobre la línea | Ilegible sobre el trazo; el dato clave son las conexiones |
| 12 | Flag `relations_graph` default false | Flag true (mostrar siempre) | Resolución §6.2: un grafo casi vacío resta más de lo que suma |

---

## §3 Resoluciones de producto/tecnología/ingeniería (v1.1) y cómo se aplicaron

| # | Resolución | Aplicación en la implementación |
|---|---|---|
| 1 | "Omitir" persistente por usuario (`has_seen_example`) | 11.3: columna boolean default false + cast; `skip()` setea el flag; el feed lo oculta (no reaparece entre sesiones) |
| 2 | Grafo apagado por defecto (`relations_graph = false`) | 14.3: `config('kuestion.features.relations_graph')` default false con `env` override; test on/off del render |
| 3 | Tendencias del dashboard desde `daily_metrics` incluidas | 12.4: últimas 8 semanas por semana ISO con degradación con gracia (oculta si no hay datos) |
| 4 | Agregados del dashboard solo activas | 12.3: `where('status', 'active')` (el scope SoftDeletes del modelo excluye archivadas/eliminadas) |
| 5 | Ejemplo de onboarding solo en el feed vacío (11.4 pospuesto) | Confirmado: el ejemplo no se duplicó en `onboarding.blade.php` |

---

## §4 Pendientes fuera de fase

1. **Coordinación con Kuaforia (heredado de Fases 1/2):** catálogo real de tools MCP (pendiente #2), `workspace_id` por defecto en la validación apikey→tenant, contrato del puente MCP y SMTP real en producción.
2. **Activación del grafo de relaciones (Bloque 14):** cuando el piloto de Ispend acumule ~10 preguntas relacionadas, setear `KUESTION_FEATURE_RELATIONS_GRAPH=true` (o el flag en config). Feature construida y testeada completa, apagada por defecto.
3. **Sistema de roles:** `team_dashboard_access` es una solución temporal documentada; reemplazarla por roles cuando se necesite granularidad dentro del tenant.
4. **Integración manual pendiente:** smoke visual del flujo completo (registro → onboarding → crear → tags → feedback → grafo con flag) y la conexión de Claude Code al MCP (Fase 2) — ambos documentados, con protocolo ya cubierto por tests.

---

## §5 Riesgos no previstos en el plan maestro

1. **Gap pre-existente del feed (`?tag=` ignorado)** — ya conocido del plan (§1.4) pero con impacto real: ningún enlace de tag del índice funcionaba. Cerrado con 13.3 (prop `tag` + `whereJsonContains` + `resetPage`), con test de combinación tag+changes para no romper los filtros existentes.
2. **HTML inválido con `<a>` anidados** en los cards de tag (si el badge se hubiera puesto dentro del enlace): evitado con estructura `div` + enlace interno + badge absoluto.
3. **Default en columnas JSON de MySQL < 8.0.13** (heredado de Fase 2): la convención "default en Eloquent vía `$attributes`" se aplicó para `scopes`; en esta fase las columnas nuevas son boolean/string con default estándar (sin problema).
4. **`mb_*` functions para texto con acentos** en las etiquetas de los nodos del grafo (`mb_strtoupper`, `mb_substr`, `mb_strimwidth`): sin ellas, caracteres multibyte se cortarían mal.

---

## §6 Preguntas abiertas nuevas

No surgieron preguntas abiertas de producto durante la implementación: las 5 resoluciones de revisión v1.1 se aplicaron sin necesidad de reabrir ninguna. Los únicos puntos abiertos son los de coordinación con Kuaforia (heredados, §4) y el momento de activación del grafo (decisión de rollout, no de implementación).

---

*Documento generado a partir de la implementación de `kuestion-fase3-plan-implementacion.md` v1.1. Suite final: 105 tests (291 assertions). Fase 3 completa: Bloques 11, 12, 13 y 14 — con esto quedan implementadas las 3 fases del plan maestro.*
