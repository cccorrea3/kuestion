# Resumen de Implementación — Ola 1, Puntos 5 y 6: Vigilancia y feed para QBK

*Equipo de Kuestion · Septiembre 2026*
*Plan de origen: `ola1-puntos5y6-plan-implementacion-kuestion.md` (v2, revisado)*
*Documento de entrada: `OLA_1_Puntos_5_y_6.md`*
*Commit de referencia: `8de02db` — "Ola 1 P5/6: copy de vigencia honesto, fuente condicional y transición sin→con respuesta"*

---

## 1. Qué se implementó

El plan se ejecutó completo, fase por fase, sin combinar pasos. La premisa central se confirmó contra el código y contra el servicio real: **el mecanismo de vigilancia no cambió** — el trabajo fue de ajuste de copy y UI, no de construcción nueva.

### Fase 1 — Copy de vigencia honesto para QBK (tareas 1.1–1.3)

| Tarea | Implementación |
|---|---|
| 1.1 | `resources/views/components/question-card.blade.php`: cuando `connector_type === 'qbk'`, la fecha se muestra como "Agregado hace X — sin reconfirmaciones registradas" (usando `longAbsoluteDiffForHumans` para evitar el doble "hace"). Para Kuaforia y demás conectores se conserva el copy anterior intacto. |
| 1.2 | `resources/views/livewire/question-detail.blade.php`: mismo sufijo " — sin reconfirmaciones registradas" sobre la fecha ISO para preguntas `qbk`. |
| 1.3 | Tests nuevos: `tests/Feature/QuestionVigenciaCopyTest.php` (5 tests). |

### Fase 2 — Fuente visible condicional con más de un repositorio activo (tareas 2.1–2.3)

| Tarea | Implementación |
|---|---|
| 2.1 | `app/Livewire/QuestionFeed.php`: propiedad computada `showSource` (repositorios del usuario con `status = 'active'` > 1), pasada a la vista en `render()`. |
| 2.2 | La card recibe `:show-source` y el tag de fuente existente (del Punto 1, Fase 5) se envuelve con `@if ($showSource && ...)` — mismo markup, misma config `display_name`. |
| 2.3 | Tests nuevos: `tests/Feature/QuestionSourceTagTest.php` (3 tests), incluye el caso "segundo repo inactivo no cuenta" (supuesto NB2 del plan). |

### Fase 3 — Clasificación "de sin respuesta a con respuesta" (tareas 3.1–3.8)

| Tarea | Implementación |
|---|---|
| 3.1 | Migración `2026_09_03_000001_add_found_was_empty_prev_to_answer_versions_table.php`: columnas `found` (boolean, default true) y `was_empty_prev` (boolean, default false) en `answer_versions`. Se actualizaron `fillable` y `casts` del modelo `AnswerVersion`. |
| 3.2 | `app/Livewire/CreateQuestion.php`: la versión 1 persiste `found = $response->found` y `was_empty_prev = false`. |
| 3.3 | `app/Services/QuestionChecker.php`: cada versión nueva persiste `found` y `was_empty_prev` (true solo si la versión anterior existía con `found = false` y la nueva respuesta tiene `found = true`). El array de retorno de `check()` incluye `was_empty_prev`. |
| 3.4 | `app/Notifications/AnswerChangedNotification.php`: parámetro `wasEmptyPrev` (default false); la clave `was_empty_prev` se agrega al payload de `toDatabase()` **solo cuando es true** (el payload base queda byte a byte igual para no romper consumidores existentes). |
| 3.5 | `app/Mail/AnswerChangedMail.php` + `resources/views/emails/answer-changed.blade.php`: cuando `was_empty_prev` es true, la fila "Tipo de cambio" muestra "Ahora hay información sobre algo que preguntaste". |
| 3.6 | `QuestionFeed`: eager-load de `currentVersion` (además de `repository`) para evitar N+1. |
| 3.7 | `question-card.blade.php`: cuando hay cambios sin revisar y la versión actual tiene `was_empty_prev = true`, se muestra el copy especial en badge teal, en lugar del badge naranja "Cambio sin revisar". |
| 3.8 | Tests nuevos: `tests/Feature/AnswerWasEmptyPrevTest.php` (8 tests). |

### Fase 4 — Vigilancia automática para `found: false` (tareas 4.1–4.3)

| Tarea | Implementación |
|---|---|
| 4.1 | Test E2E con proveedor simulado que replica el comportamiento real de QBK (texto informativo, no vacío, con `found: false`): v1 con `found = false`, y luego la re-consulta con `found: true` crea la v2 con `was_empty_prev = true`. |
| 4.2 | Tests que fijan que el job procesa preguntas con `found: false` sin gates condicionales, y que detecta la transición cuando corresponde. |
| 4.3 | Tests de regresión: cambio normal en QBK (ambas versiones `found: true`) y flujo de Kuaforia intactos. |

Tests nuevos: `tests/Feature/VigilanciaFoundFalseTest.php` (5 tests).

### Fase 5 — QA, regresión y cierre (tareas 5.1–5.4)

- Regresión de Preguntar/Aportar/Revisión: 111 tests en verde (suites `CreateQuestion*`, `ContributeAporte*`, `QbkContributionService*`, `ContributionReview*`, `PendingReviewBadge*`, `ContributionDraft*`).
- Rebuild de assets: `npm run build` sin errores; clases nuevas verificadas en el bundle compilado.
- Suite completa y Pint: ver sección 3.

---

## 2. Archivos tocados (commit `8de02db`)

**Modificados (10):**
- `app/Livewire/CreateQuestion.php`
- `app/Livewire/QuestionFeed.php`
- `app/Mail/AnswerChangedMail.php`
- `app/Models/AnswerVersion.php`
- `app/Notifications/AnswerChangedNotification.php`
- `app/Services/QuestionChecker.php`
- `resources/views/components/question-card.blade.php`
- `resources/views/emails/answer-changed.blade.php`
- `resources/views/livewire/question-detail.blade.php`
- `resources/views/livewire/question-feed.blade.php`

**Nuevos (5):**
- `database/migrations/2026_09_03_000001_add_found_was_empty_prev_to_answer_versions_table.php`
- `tests/Feature/QuestionVigenciaCopyTest.php`
- `tests/Feature/QuestionSourceTagTest.php`
- `tests/Feature/AnswerWasEmptyPrevTest.php`
- `tests/Feature/VigilanciaFoundFalseTest.php`

---

## 3. Verificación y evidencia

### 3.1 Tests automatizados

| Suite | Tests | Estado |
|---|---|---|
| `QuestionVigenciaCopyTest` (F1) | 5 | ✅ Verde |
| `QuestionSourceTagTest` (F2) | 3 | ✅ Verde |
| `AnswerWasEmptyPrevTest` (F3) | 8 | ✅ Verde |
| `VigilanciaFoundFalseTest` (F4) | 5 | ✅ Verde |
| Regresión Preguntar/Aportar/Revisión (F5) | 111 | ✅ Verde |
| **Suite completa** | **377 passed / 1058 assertions** | ✅ 1 fallo pre-existente ajeno (`RepositoryMigrationTest`, documentado antes de este plan) |
| Pint | — | ✅ PASS |
| `npm run build` | — | ✅ Compila; clases verificadas en bundle |

### 3.2 Checklist funcional ejecutado contra el servicio real

Se levantaron Kuestion y QuBeKa reales (script `scripts/dev-qbk.sh`) y se ejecutó el ciclo completo de los Puntos 5 y 6 con datos reales:

| Ítem del plan | Resultado | Evidencia |
|---|---|---|
| F3.1 — Pregunta QBK sin respuesta crea v1 con `found = false` | ✅ | Pregunta "Zxqorpv mlatendorff quibrax 77412" consultada contra QBK real: `found: false, sources: 0`; versión 1 persistida con `found = 0`. |
| F3.2 — Aporte de contenido y re-consulta detecta la transición | ✅ | Se creó el nodo N-K validado `NK-8642` en QuBeKa (workspace 1) con la respuesta. La re-consulta devolvió `found: true` con 1 fuente y el `QuestionChecker` real creó la v2 con `was_empty_prev = true`, `status = new_version`, `has_unreviewed_changes = true`. |
| F3.3 — La card muestra el copy especial | ✅ (render real) | Render de `question-card` con la pregunta real: contiene "Ahora hay información sobre algo que preguntaste"; no contiene "Cambio sin revisar". También presente el copy de vigencia honesto. |
| F3.4 — La notificación lleva `was_empty_prev` | ✅ | Notificación `AnswerChangedNotification` real en BD con `question_id = a2a96800...` y `was_empty_prev: true` en el payload. |
| F3.5 — El mail muestra el copy especial | ✅ (HTML real del mailable) | `AnswerWasEmptyPrevTest::test_mail_shows_special_copy_when_was_empty_prev` renderiza el HTML real del mailable y verifica el copy. Pendiente: inspección visual en un cliente de correo (no hay entorno SMTP/Mailpit en este ambiente). |
| F3.6 — Cambio normal sin copy especial | ✅ (datos reales) | Pregunta "quien es sherlock holmes?" (v2–v5): todas las versiones con `found = 1, was_empty_prev = 0` — flujo normal intacto. |
| F3.7 — Fallo visible en runtime | ✅ | Patrón `checkResult`/`checkResultType` ya existente en `QuestionDetail` (validado por `QuestionDetailCheckNowTest`). |
| F4.1/F4.2 — Vigilancia automática sin gates | ✅ | `VigilanciaFoundFalseTest` (mock del contrato) + ciclo real con `QuestionChecker` (el mismo que invoca el job). |
| F4.4 — Regresión Kuaforia | ✅ | Suites Kuaforia en verde dentro de la suite completa. |

**Resultado del ciclo E2E real (transición completa en Kuestion):**

```
v1: found=false, was_empty_prev=false  → "No encontré información relevante en el grafo de conocimiento..."
v2: found=true,  was_empty_prev=true   → "El protocolo Zxqorpv mlatendorff con clave quibrax 77412 se activa únicamente con autorización de nivel 5..."
    has_unreviewed_changes=true → la card muestra "Ahora hay información sobre algo que preguntaste"
```

---

## 4. Hallazgos y decisiones durante la implementación

1. **Fix de implementación en `QuestionChecker` (F3.3):** `was_empty_prev` se calculaba dentro de la closure de la transacción pero se leía fuera del `use`. Se detectó por el test 3.8(a) y se corrigió pasando la variable por referencia (`&$wasEmptyPrev`). Sin esta corrección, `check()` devolvía siempre `was_empty_prev = false` aunque la versión persistida sí lo tuviera.
2. **Fix de test:** para simular el estado real en los tests de la card (F3.7), la versión anterior debe quedar con `is_current = false` antes de crear la v2 (igual que hace el checker); sin eso, la relación `currentVersion` apunta a la versión equivocada.
3. **Hallazgo de datos pre-existentes:** las preguntas QBK creadas antes de esta implementación ("sherlock holmes", "Cristian Correa") tienen versiones con `found = 1` aunque varias contengan texto de "No encontré información...". Con el default de la migración (`found = true`), si alguna de esas preguntas realmente no tenía respuesta en su v1, una transición futura hacia respuesta real **no** marcaría `was_empty_prev`. Afecta solo historial previo al deploy; las preguntas nuevas registran `found` correctamente. Si el piloto depende de detectar transiciones en preguntas QBK históricas, conviene decidir un backfill (re-consultar cada pregunta para recomputar `found` de la v1) — queda como recomendación, no se ejecutó por no alterar datos de producción sin confirmación.
4. **Duda D1 del plan (decisión de producto menor):** el tag de fuente estaba implementado de forma incondicional (Punto 1, Fase 5). El plan propone alinearlo con la especificación §2.3 (mostrar solo con más de un repositorio activo) y así se implementó. **Queda pendiente la confirmación de producto** de si se prefiere la condición (spec) o dejarlo siempre visible (estado anterior).
5. **Supuestos adoptados del plan (sin reabrir):** NB2 (conteo de repos activos), D2 (proxy de vigencia con `created_at` de la pregunta), D3 (solo cambia el cuerpo del mail, no el asunto), D4 (sin versión previa no se marca la transición).

---

## 5. Pendientes declarados (con motivo)

| Pendiente | Motivo |
|---|---|
| Verificación visual en navegador real con DevTools (checklists F1.1–F1.3, F2.1–F2.3, F3.3 visual) | No hay navegador disponible en este ambiente de trabajo. Los datos de prueba quedaron cargados: en el feed de `ccorrea@proteam.cl` se ve la pregunta "Zxqorpv mlatendorff quibrax 77412" con el badge "Ahora hay información sobre algo que preguntaste". El nodo N-K `NK-8642` quedó en QuBeKa como contenido de prueba. |
| Inspección visual del mail (F3.5) | No hay entorno SMTP/Mailpit configurado; el HTML del mailable se verificó por render real, no por cliente de correo. |
| Confirmación de producto de la duda D1 (tag de fuente condicional vs. siempre visible) | Decisión de producto menor dejada explícitamente abierta en el plan. |
| Backfill de `found` en preguntas QBK pre-existentes | Afecta solo historial previo al deploy; se recomienda decidir antes de confiar en transiciones de preguntas QBK antiguas (ver hallazgo 3). |

---

## 6. Estado final del plan

**Fases 1 a 5: implementadas y verificadas** según entregables verificables + tests automatizados + checklist funcional ejecutado contra el servicio real cuando estuvo disponible. El plan no se declara cerrado al 100 % solo por los pendientes declarados en la sección 5 (verificación visual en navegador y confirmación de D1), que requieren ambiente con navegador y decisión de producto, respectivamente.
