# Plan de Implementación — Ola 1, Puntos 5 y 6: Vigilancia y feed para QBK

*Equipo de Kuestion · Agosto 2026 · v2 (revisado tras revisión profunda del código)*
*Documento de entrada: OLA_1_Puntos_5_y_6.md*

---

## 1. RESUMEN DE ALCANCE

### Qué voy a construir

Ajustes de copy y UI en el feed y las notificaciones de Kuestion para que funcionen correctamente con preguntas conectadas a QBK. **El mecanismo de vigilancia no cambia** — el job horario, el `ChangeDetector` y el `DiffGenerator` son agnósticos del origen del texto. El trabajo real es de ajuste, no de construcción nueva.

En concreto:
1. **Copy de vigencia honesto** para contenido de QBK: mostrar "agregado hace X — sin reconfirmaciones registradas" en vez de pretender que hay una fecha de última revisión que no existe (QBK no tiene `fecha_ultima_confirmacion`).
2. **Fuente visible por pregunta** cuando haya más de un repositorio activo conectado (reutilizando el patrón del sistema de conectores).
3. **Clasificación "de sin respuesta a con respuesta"**: cuando la versión anterior tenía `found: false` y la nueva tiene `found: true`, mostrar el copy especial: "Ahora hay información sobre algo que preguntaste." — tanto en la card del feed como en la notificación (in-app y mail).
4. **Confirmar que la vigilancia automática por defecto** ya cubre preguntas con `found: false` sin ningún gate condicional — validar con una prueba real.

### Hallazgos de la revisión profunda (impactan el alcance)

- **El tag de fuente ya existe en el feed** (implementado incondicionalmente en Ola 1 Punto 1, Fase 5: `resources/views/components/question-card.blade.php`). La especificación (§2.3) pide mostrarlo **solo cuando hay más de un repositorio activo**. La Fase 2 de este plan pasa de "crear el tag" a "hacerlo condicional según el conteo de repos activos" — ver duda D1.
- **`KuaforiaResponse` ya tiene el campo `found`** (`public readonly bool $found = true`) y `QbkService` ya lo puebla desde el contrato de QBK (`found: $data['found'] ?? true`). La detección de "sin respuesta" debe basarse en este campo, NO en `answer_text === ''`: QBK devuelve texto informativo ("No encontré información relevante...") cuando `found: false` (confirmado por el equipo de QuBeKa, NB4 del Punto 1), por lo que `answer_text` nunca queda vacío en ese caso. Esto resuelve la duda NB1 del plan anterior.
- **`AnswerVersion` no persiste `found` hoy.** Para detectar la transición `false → true` hace falta una migración que agregue `found` (y `was_empty_prev`) a `answer_versions` — ver Fase 3.
- **El copy especial del mail requiere tocar `AnswerChangedMail` + `emails/answer-changed.blade.php`**, no solo la notificación in-app — la notificación tiene dos canales (database + mail).

### Lo que NO construyo

- El **mecanismo de vigilancia** — ya existe, no cambia.
- El **`ChangeDetector`** — es agnóstico del origen.
- El **`DiffGenerator`** — es agnóstico del origen.
- La **fecha de última confirmación** de QBK — es trabajo futuro de QBK, no de esta Ola.
- Un **filtro del feed por fuente** — no está especificado; queda para evolución futura.

---

## 2. FASES Y TAREAS

### Fase 1 — Copy de vigencia honesto para QBK

**Objetivo:** que el feed y el detalle muestren la vigencia del contenido de QBK de forma honesta, sin pretender una precisión que no existe.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| 1.1 | En `resources/views/components/question-card.blade.php`, condicionar el copy de fecha por `connector_type`: si `$question->repository->connector_type === 'qbk'`, reemplazar el `{{ $question->created_at->diffForHumans() }}` actual por "Agregado hace X — sin reconfirmaciones registradas" (proxy: `created_at` de la pregunta; ver duda D2). Para `kuaforia` (y cualquier otro conector), mantener el copy actual sin cambios. | Ninguna | Copy diferenciado por conector en la card. |
| 1.2 | En `resources/views/livewire/question-detail.blade.php`, misma lógica sobre la fecha de creación mostrada (`isoFormat('D [de] MMMM [de] YYYY')`): para `qbk` agregar el sufijo " — sin reconfirmaciones registradas". | 1.1 | Copy consistente en feed y detalle. |
| 1.3 | Tests Livewire/feature: una pregunta con repo `qbk` muestra la frase honesta; una con repo `kuaforia` muestra el copy actual (sin la frase). | 1.1, 1.2 | Tests verdes. |

**Entregable verificable:** una pregunta de QBK muestra "Agregado hace X días — sin reconfirmaciones registradas" en feed y detalle. Una de Kuaforia muestra el copy actual.

**Validación:** tests + checklist funcional F1 (sección 3).

---

### Fase 2 — Fuente visible condicional (>1 repo activo)

**Objetivo:** que el feed indique de qué fuente viene cada pregunta **solo cuando** el usuario tiene más de un repositorio activo, alineado con la especificación §2.3.

**Estado base (hallazgo):** el tag de fuente ya existe en `question-card.blade.php` (Punto 1, Fase 5) y se muestra **siempre** que hay repositorio. Esta fase lo hace condicional.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| 2.1 | En `QuestionFeed`, agregar un cómputo `activeRepoCount` (o `showSource`): contar repositorios del usuario con `status = 'active'`. Pasarlo a la vista del feed (y a la card). | Ninguna | Variable disponible en la vista. |
| 2.2 | En `resources/views/components/question-card.blade.php`, envolver el tag de fuente existente con `@if ($showSource ?? false)`. Mantener el mismo markup y `config('kuestion.connectors.<tipo>.display_name')`. | 2.1 | Tag condicional: invisible con 1 repo, visible con ≥2. |
| 2.3 | Tests: con un solo repo activo no se muestra la fuente; con dos repos activos se muestra. (Se puede testear la condición directamente sobre el componente/blade o vía `assertSee` en el feed.) | 2.1, 2.2 | Tests verdes. |

**Entregable verificable:** con un solo repo conectado, el feed se ve igual que antes. Con dos repos, cada pregunta muestra de qué fuente viene.

**Validación:** tests + inspección visual con dos repos (checklist F2).

---

### Fase 3 — Clasificación "de sin respuesta a con respuesta"

**Objetivo:** detectar y comunicar el caso donde una pregunta que no tenía respuesta ahora la tiene, con el copy especial "Ahora hay información sobre algo que preguntaste." — en feed y notificaciones.

**Decisión técnica (resuelve NB1 del plan anterior):** la señal de "sin respuesta" es el campo `found` de `KuaforiaResponse` (ya poblado por `QbkService`), NO el texto vacío. `was_empty_prev` se persiste en la versión al crearla.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| 3.1 | **Migración**: agregar a `answer_versions` las columnas `found` (boolean, default `true`) y `was_empty_prev` (boolean, default `false`). | Ninguna | Migración aplicada. |
| 3.2 | En `CreateQuestion::save()`, al crear la versión 1, persistir `found` = `$response->found` (y `was_empty_prev` = `false`). | 3.1 | La versión inicial registra su estado `found`. |
| 3.3 | En `QuestionChecker::check()`, al crear cada versión nueva: persistir `found` = `$response->found` y `was_empty_prev` = (`$response->found === true` AND versión anterior existe AND versión anterior `found === false`). Agregar `was_empty_prev` al array de retorno de `check()`. | 3.1, 3.2 | Cada versión registra su `found` y la transición `false → true` queda marcada. |
| 3.4 | En `AnswerChangedNotification`, agregar parámetro `bool $wasEmptyPrev = false`; incluirlo en el payload de `toDatabase()` (clave `was_empty_prev`, solo cuando sea `true`, para no tocar el payload base) y pasarlo a `AnswerChangedMail`. | 3.3 | La notificación sabe si fue una transición "sin respuesta → con respuesta". |
| 3.5 | En `AnswerChangedMail` + `resources/views/emails/answer-changed.blade.php`: cuando `was_empty_prev` es true, reemplazar la fila "Tipo de cambio" por el copy "Ahora hay información sobre algo que preguntaste" (y el asunto del mail puede mantenerse). | 3.4 | El mail muestra el copy especial. |
| 3.6 | En `QuestionFeed`, eager-load `currentVersion` (además de `repository`) para que la card pueda leer `was_empty_prev` sin N+1. | 3.1 | Sin N+1 en el feed. |
| 3.7 | En `resources/views/components/question-card.blade.php`, cuando `has_unreviewed_changes` es true y `$question->currentVersion->was_empty_prev` es true, mostrar el copy especial "Ahora hay información sobre algo que preguntaste" en vez del badge "Cambio sin revisar" (mismo estilo visual, texto distinto). | 3.3, 3.6 | Copy especial visible en la card. |
| 3.8 | Tests: (a) versión 2 creada con `was_empty_prev = true` cuando la anterior tenía `found: false`; (b) `was_empty_prev = false` cuando la anterior ya tenía `found: true`; (c) la card muestra el copy especial; (d) la notificación y el mail llevan `was_empty_prev`; (e) payload de notificación sin la clave cuando `false` (compatibilidad con consumidores existentes). | 3.1–3.7 | Tests verdes. |

**Entregable verificable:** una pregunta que tenía "sin respuesta" (`found: false`) y ahora tiene una respuesta real (`found: true`) muestra "Ahora hay información sobre algo que preguntaste" en la card, en la notificación in-app y en el mail.

**Validación:** tests + flujo manual con QBK real (checklist F3). **No cerrar esta fase sin validar contra QBK real** (ver sección 3).

---

### Fase 4 — Validación de vigilancia automática para `found: false`

**Objetivo:** confirmar con una prueba real que las preguntas con `found: false` quedan vigiladas automáticamente sin ningún gate condicional.

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| 4.1 | Test E2E (mock del proveedor QBK): crear pregunta con repo `qbk`; primera consulta devuelve `found: false` con texto informativo (NO vacío — comportamiento real de QBK). Verificar: se crea versión 1 con `found = false`; `last_consulted_at` seteado. Luego simular re-consulta que devuelve `found: true` con respuesta real: se crea versión 2 con `was_empty_prev = true` y `has_unreviewed_changes = true`. | Fase 3 | Test que valida el flujo completo "sin respuesta → con respuesta". |
| 4.2 | Verificar por inspección que `CheckQuestionUpdatesJob` y `QuestionChecker` no tienen ningún gate que excluya preguntas con `found: false` (confirmado en la revisión: el job itera `status = 'active'` y solo filtra por `isDue`). Agregar un test que lo fije: una pregunta con `found: false` figura en `isDue` y es procesada por el job. | 4.1 | Sin gates condicionales, fijado por test. |
| 4.3 | Test de regresión: preguntas con respuesta existente (Kuaforia y QBK) siguen funcionando igual (cambio menor/mayor, aceptar/descartar, diff). | Todas las fases | Regresión confirmada. |

**Entregable verificable:** una pregunta con `found: false` queda vigilada, se re-consulta, y si la respuesta cambia a `found: true`, se detecta, se versiona con `was_empty_prev = true` y se notifica con el copy especial.

**Validación:** test E2E + validación manual contra QBK real (checklist F4).

---

### Fase 5 — QA, regresión y cierre

| # | Tarea | Dependencias | Entregable |
|---|---|---|---|
| 5.1 | Verificar que el flujo de "Preguntar" y "Aportar" (puntos 1 y 3) no se tocó: correr las suites de `CreateQuestion*`, `ContributeAporte*`, `QbkContributionService*` y `ContributionReview*`. | Todas las fases | Regresión confirmada. |
| 5.2 | Revalidar que el job horario funciona con preguntas de QBK (checklist F4 en entorno real). | Todas las fases | Job funcional con QBK real. |
| 5.3 | Rebuild de assets (`npm run build`) + verificación de que las clases usadas en las vistas modificadas están en el bundle compilado (checklist obligatorio, sección 3). | Todas las fases | Assets compilados y verificados. |
| 5.4 | Suite completa + Pint + cierre documentado (hallazgos y evidencia de cumplimiento). | Todas las fases | Verde y documentado. |

---

## 3. PRUEBAS FUNCIONALES Y DE INTEGRACIÓN

Los tests unitarios y de integración con mocks validan que el código hace lo que dice que hace, no que el flujo completo tenga sentido para una persona real. Por eso cada fase con UI o integración tiene checklist funcional propio, **además** de los tests de las tareas.

### Ítems obligatorios en toda fase con UI o integración (no negociables)

1. **Rebuild y verificación de assets compilados.** Todo cambio en una vista o componente de UI (Fases 1, 2, 3, 5) exige `npm run build` y verificar que las clases de estilo usadas aparecen en el CSS **compilado/servido** (`public/build/`), no solo en el fuente. Un elemento presente en el DOM sin su clase en el bundle es un bug de entrega. Verificación: `grep` de las clases usadas (p. ej. `text-orange-700`, `bg-amber-50`, `line-clamp-2`) contra el bundle generado.
2. **Verificación visual en el navegador real, no solo tests.** Inspeccionar con DevTools: elemento visible, texto legible, contraste correcto, badge con su fondo. Un test que pasa con mock no valida la experiencia real: confirmar en pantalla que cada acción (badge, enlace, redirección) ocurre y es visible.
3. **Compatibilidad con la versión instalada de las dependencias.** Antes de usar un método/API de una librería, comprobar que existe en la versión real instalada según `composer.json`/`package.json` y el código del vendor. **Ejemplo real en este proyecto:** con `livewire/livewire: ^4.0` instalado, existe `redirect()` (`HandlesRedirects`) pero **NO existe `redirectExternal()`** — usarlo rompe el flujo en runtime. Si una tarea necesita redirección externa (no aplica en este plan, pero queda como regla), debe resolverse con `redirect()` o navegación estándar, verificada contra el vendor.
4. **Fallo visible y claro en runtime.** Si un flujo real falla (servicio externo, carga de datos), el usuario debe ver un error legible que indique qué pasó y cómo proceder — nunca una pantalla congelada en "cargando..." ni un estado silencioso. Un cambio de estado incorrecto (quedar colgado en `loading`) se reporta como bug. Los componentes Livewire de este plan deben tener manejo de error visible (patrón ya usado en `QuestionDetail::checkNow` con `checkResult`/`checkResultType`).
5. **Prueba contra el servicio real cuando esté disponible.** El Motor de Consulta de QBK (`POST /api/v1/query`) está construido y sincronizado del lado de QuBeKa (Ola 1 Punto 1). Por lo tanto, **las Fases 3, 4 y 5 se validan contra QBK real**, no solo con mocks. Si QBK estuviera indisponible al momento de la fase, declararlo explícitamente y dejar definido qué se probó con mock y qué queda pendiente de validación real antes de cerrar la fase.

### Checklist F1 — Copy de vigencia honesto (UI)

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| F1.1 | Feed con una pregunta QBK (repo `qbk` conectado) | Navegador real + DevTools | La card muestra "Agregado hace X — sin reconfirmaciones registradas", legible, sin overflow |
| F1.2 | Detalle de la misma pregunta QBK | Navegador real | El detalle muestra la misma frase honesta |
| F1.3 | Feed con una pregunta Kuaforia | Navegador real | El copy actual se mantiene (sin la frase honesta) |
| F1.4 | Rebuild de assets | `npm run build` + grep de clases en el bundle | Las clases usadas están en el CSS compilado |
| F1.5 | Fallo visible | Si el repo de la pregunta está `invalid`, la card muestra el badge de estado (ya existente) y el copy no rompe el layout | Sin estados silenciosos |

### Checklist F2 — Fuente visible condicional (UI)

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| F2.1 | Usuario con 1 repo activo (QBK o Kuaforia) | Navegador real + DevTools | Sin tag de fuente en las cards |
| F2.2 | Usuario con 2 repos activos (QBK + Kuaforia) | Navegador real (crear el segundo repo en `/settings`) | Cada card muestra su fuente ("QuBeKa" / "Kuaforia") |
| F2.3 | Cambiar entre estados (desconectar un repo) | `/settings` → desconectar; volver al feed | El tag desaparece/aparece según el conteo, sin recargar página manual |
| F2.4 | Rebuild de assets | `npm run build` + grep | Clases del tag en el bundle |

### Checklist F3 — Clasificación "sin respuesta → con respuesta" (UI + integración real)

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| F3.1 | Pregunta QBK sin respuesta | Crear pregunta que QBK no responde (nodo inexistente) → `found: false` | Se crea versión 1 con `found = false` (verificar en BD `answer_versions.found`) |
| F3.2 | Alguien aporta contenido que la responde | Agregar nodo N-K en QuBeKa (flujo Aportar del Punto 3) | Al re-consultar, versión 2 con `was_empty_prev = true` (verificar en BD) |
| F3.3 | Card del feed muestra el copy especial | Navegador real | "Ahora hay información sobre algo que preguntaste" en vez de "Cambio sin revisar" |
| F3.4 | Notificación in-app | Badge de notificaciones → click | Redirige a la pregunta; el copy del cambio es el especial |
| F3.5 | Mail (si `email_notifications` activo) | Mailtrap/Mailpit o `Mail::fake` + inspección del HTML | El mail muestra el copy especial en la fila "Tipo de cambio" |
| F3.6 | Caso contrario: cambio normal | Pregunta QBK con respuesta previa que cambia (ambas `found: true`) | Copy normal "Cambio sin revisar" / "Nueva versión", sin el copy especial |
| F3.7 | Fallo visible | Si QBK devuelve error al re-consultar (p. ej. 500), el detalle muestra el mensaje de error y no queda colgado en "Comprobando..." | Error legible, estado recuperable |

### Checklist F4 — Vigilancia automática para `found: false` (integración real)

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| F4.1 | Pregunta con `found: false` queda vigilada | Crear pregunta QBK sin respuesta; correr el job (`php artisan schedule:run` o dispatch manual) | El job la procesa (no la salta) |
| F4.2 | Transición detectada por el job | Después de aportar contenido en QuBeKa, correr el job | `found: true` detectado, versión nueva con `was_empty_prev = true` |
| F4.3 | Sin gates condicionales | Inspección del código del job + test fijado | El job itera `status = 'active'` sin filtrar por `found`/texto |
| F4.4 | Regresión Kuaforia | Pregunta Kuaforia con respuesta existente, job + "Comprobar ahora" | Sigue funcionando como antes (sin regresiones) |

### Checklist F5 — Cierre

| # | Prueba | Cómo | Resultado esperado |
|---|---|---|---|
| F5.1 | Preguntar (Punto 1) intacto | Suite `CreateQuestion*` + flujo real | Verde / funciona |
| F5.2 | Aportar (Punto 3) y Revisión (Punto 4) intactos | Suite `ContributeAporte*`, `QbkContributionService*`, `ContributionReview*` + flujo real | Verde / funciona |
| F5.3 | Suite completa | `php artisan test` | Sin nuevas fallas (comparar contra baseline previo) |
| F5.4 | Pint | `vendor/bin/pint` | PASS |
| F5.5 | Assets | `npm run build` | Compila sin errores; clases verificadas en el bundle |

### Matriz mock vs real

| Fase | Con mock (siempre) | Contra servicio real (cuando esté disponible) |
|---|---|---|
| F1 — Copy vigencia | Tests de copy según `connector_type` | Visual en navegador con repo QBK real conectado |
| F2 — Fuente condicional | Tests de conteo de repos | Visual con 2 repos reales (QBK + Kuaforia) |
| F3 — Sin→con respuesta | Tests de versionado y payload | **Obligatorio contra QBK real** (Motor de Consulta ya construido): crear pregunta sin respuesta, aportar en QuBeKa, re-consultar |
| F4 — Vigilancia automática | Test E2E con proveedor mock | **Obligatorio contra QBK real**: correr el job sobre una pregunta `found: false` y verificar la transición |
| F5 — Cierre | Suite completa | Flujo completo de punta a punta en navegador |

**Regla de cierre:** ninguna fase se declara cerrada hasta completar los ítems obligatorios de esta sección (rebuild de assets, verificación visual en navegador, compatibilidad de versiones, fallo claro en runtime, prueba contra el servicio real). Si alguno no pudo hacerse, se declara explícitamente como pendiente con su motivo.

---

## 4. DUDAS Y BLOQUEOS

### Bloqueantes

No hay puntos bloqueantes. El documento de origen lo confirma y la revisión del código no encontró ninguno: el mecanismo de vigilancia ya cubre `found: false` sin gates, y el campo `found` ya existe en `KuaforiaResponse`.

### No bloqueantes

| # | Pregunta | Supuesto / estado | Para quién |
|---|---|---|---|
| NB1 | ~~¿El `found: false` se detecta desde `answer_text === ''` o desde un campo explícito?~~ | **RESUELTO en la revisión:** `KuaforiaResponse` ya tiene `found` y `QbkService` lo puebla. La detección usa `found`. QBK devuelve texto informativo con `found: false` (no vacío) — confirmado por QuBeKa en el Punto 1 (NB4). | Interno |
| NB2 | ¿"Más de un repositorio activo" = conteo de repos con `status = 'active'`, o de repos con preguntas vigiladas? | Asumo >1 repos **activos** (independiente de si tienen preguntas). Consistente con el selector de `CreateQuestion` (P11: solo `active`). | Interno |
| D1 | **El tag de fuente ya se muestra siempre** (Punto 1 Fase 5 lo implementó incondicional). La especificación §2.3 pide mostrarlo solo con >1 repo activo. ¿Hacemos la condición (spec) o dejamos siempre visible (estado actual)? | El plan propone alinear con la spec (condicional). Alternativa aceptable: dejarlo siempre visible por simplicidad. **Decisión de producto menor — confirmar antes de cerrar Fase 2.** | Producto |
| D2 | **Proxy de vigencia:** "Agregado hace X" usa `created_at` de la **pregunta** (cuándo el usuario preguntó), no del contenido de QBK (que Kuestion no conoce con precisión). ¿Es aceptable como proxy, o preferimos "agregado hace X" sobre `created_at` de la primera versión de la respuesta? | Asumo `created_at` de la pregunta (lo que ya muestra la card hoy, solo que con la frase honesta agregada). Alternativa: `currentVersion->created_at` (más cercano al momento en que Kuestion vio el contenido por primera vez). | Interno / Producto |
| D3 | El copy especial en el mail: ¿el asunto del correo cambia ("Ahora hay información...") o solo el cuerpo? | Asumo solo el cuerpo (la fila "Tipo de cambio"); el asunto se mantiene para no tocar filtros de correo existentes. | Interno |
| D4 | Caso borde de `was_empty_prev`: si la pregunta nunca tuvo versión (p. ej. primera consulta falló o devolvió texto vacío — caso Kuaforia 1.8, que no versiona) y la primera versión creada es `found: true`, ¿se considera "de sin respuesta a con respuesta"? | Asumo que **no**: `was_empty_prev = true` solo cuando existe una versión anterior con `found = false` (el caso real de QBK, que sí versiona el texto informativo). El caso "sin versión previa" queda como está (copy normal). | Interno |

---

## 5. ESFUERZO ESTIMADO

| Fase | Esfuerzo estimado | Incertidumbre |
|---|---|---|
| **Fase 1** — Copy de vigencia honesto | S (0.5 d) | Baja — cambio de copy, patrón conocido. |
| **Fase 2** — Fuente condicional | S (0.5 d) | Baja — ya existe el tag; solo se condiciona. Duda D1 de producto. |
| **Fase 3** — Clasificación "sin respuesta → con respuesta" | **M (1.5–2 d)** | **Media-alta — es la fase de mayor incertidumbre**: toca migración (`answer_versions`), versionado (`QuestionChecker`), payload de notificación y mail, y la card. Requiere validación contra QBK real (F3.1/F3.2). |
| **Fase 4** — Validación de vigilancia automática | S (0.5 d) | Baja — validación y tests del flujo existente. |
| **Fase 5** — QA y cierre | S (0.5–1 d) | Baja — regresión + assets + suite. |
| **TOTAL** | **S–M (3.5–4.5 d)** | La fase 3 concentra el riesgo (migración + integración real con QBK). |

**Nota:** este sigue siendo el punto de menor esfuerzo de la Ola 1. La Fase 3 creció respecto del plan anterior (de 1 d a 1.5–2 d) porque la revisión detectó que `found` no se persiste hoy en `answer_versions` y que el copy especial debe llegar también al mail, no solo a la notificación in-app.

---

## 6. FUERA DE ALCANCE

| Elemento | Por qué queda fuera |
|---|---|
| **Fecha de última confirmación de QBK** | Trabajo futuro de QBK. Esta Ola usa `created_at` como proxy con copy honesto. |
| **Mecanismo de vigilancia** | Ya existe, no cambia. |
| **ChangeDetector / DiffGenerator** | Son agnósticos del origen. No se modifican. |
| **Filtros avanzados en el feed** (por fuente, por estado de aporte) | Podrían ser útiles pero no están especificados. Quedan para evolución futura. |
| **Dashboard de métricas por fuente** | Fuera de alcance de esta Ola. |
| **Selector de repositorio por pregunta** | Ya existe (Fase C del sistema de conectores). No se agrega lógica nueva acá. |
| **Cambios en el contrato de QBK** (p. ej. exponer `fecha_ultima_confirmacion`) | No se pide nada nuevo a QuBeKa en este documento — confirmado por su equipo. |