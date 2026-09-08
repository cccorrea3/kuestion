# Revisión Independiente — Ola 1, Puntos 5 y 6 (Vigilancia y Feed QBK)

*Revisor independiente · 2026-09-04 · Sin participación en la implementación ni en el plan*
*Plan de referencia: `docs/ola1-puntos5y6-plan-implementacion-kuestion.md`*
*Implementación revisada: commits `8de02db` y `4195a23`*

---

## 1. Veredicto general

**Aprobado con observaciones menores.**

La implementación cumple las Fases 1, 2, 4 y la lógica central de la Fase 3, sin romper
nada existente. Hay **un punto de correspondencia parcial** en la Fase 3 (rendering del copy
especial en la "notificación in-app") y observaciones menores de calidad. **Nada bloqueante.**

---

## 2. Regresión y seguridad (la prioridad)

**Se verificó explícitamente — sin hallazgos de regresión ni de seguridad.**

| Verificación | Resultado |
|---|---|
| Tests nuevos de P5/6 (`QuestionVigenciaCopyTest`, `QuestionSourceTagTest`, `AnswerWasEmptyPrevTest`, `VigilanciaFoundFalseTest`) | **21 passed (63 assertions)** |
| Regresión plan Fase 5.1 (Preguntar/Aportar/Revisión): `CreateQuestionRepositoryTest`, `ContributeAporteTest`, `ContributeAporteIntegrationTest`, `QbkContributionServiceTest`, `ContributionReviewTest` | **87 passed (241 assertions)** |
| Regresión componentes modificados: `QuestionCheckerTest`, `CheckQuestionUpdatesJob*`, `QuestionDetailCheckNow/FollowUp` | **26 passed (110 assertions)** |
| `RepositoryMigrationTest` | **Falla (1 failed, 0 assertions, línea 32)** — fallo **pre-existente y NO relacionado** con P5/6 (área de migración de repositorios). Baseline ya conocido y documentado en la revisión del Punto 4. |
| Assets compilados (F5.5) — `grep` en `app-C32BQ6W8.css` | **OK** — presentes: `bg-teal-100`, `text-primary`, `animate-pulse`, `bg-orange-100`, `text-orange-700` |
| Pint (F5.4) — `vendor/bin/pint` sobre los 10 archivos tocados | **passed** |
| Contratos retrocompatibles | **OK** — `AnswerChangedMail` y `AnswerChangedNotification` agregan parámetros con default (`bool $wasEmptyPrev = false`); el payload `toDatabase()` conserva las claves base y solo integra `was_empty_prev` cuando es `true` |
| Migración | **No destructiva** — agrega `found` (default `true`) y `was_empty_prev` (default `false`) sobre datos existentes; sin NOT NULL sin default; reversible en `down()` |
| Seguridad | **OK** — `getShowSourceProperty` cuenta repos del **usuario actual**; copy especial es texto estático (sin XSS); no se exponen datos sensibles |

---

## 3. Correspondencia con el plan, fase por fase

| Fase/Tarea | Estado | Evidencia |
|---|---|---|
| **F1.1** Copy vigencia card por `connector_type` | ✅ Cumple | `question-card.blade.php:54-59` — `@if ($question->repository?->connector_type === 'qbk')` → "Agregado hace X — sin reconfirmaciones"; else `diffForHumans()`. |
| **F1.2** Copy vigencia detalle | ✅ Cumple | `question-detail.blade.php:76` — sufijo `— sin reconfirmaciones registradas` para `qbk`. |
| **F1.3** Tests copy | ✅ Cumple | `QuestionVigenciaCopyTest` (qbk/kuaforia × feed/detalle + repo invalid). |
| **F2.1** Cómputo `showSource` (>1 repo activo) | ✅ Cumple | `QuestionFeed::getShowSourceProperty()` — `Repository::where(user_id)->where(status,'active')->count() > 1`. |
| **F2.2** Tag condicional + `display_name` | ✅ Cumple | `question-card.blade.php:60-67` — `@if ($showSource && $question->repository)` + `config("kuestion.connectors...display_name")`. |
| **F2.3** Tests fuente (1 / 2 / inactivo) | ✅ Cumple | `QuestionSourceTagTest` (incluye caso NB2: repo inactivo no cuenta). |
| **F3.1** Migración `found` + `was_empty_prev` | ✅ Cumple | `add_found_was_empty_prev_to_answer_versions_table.php` (defaults correctos). |
| **F3.2** Versión 1 con `found` | ✅ Cumple | `CreateQuestion::save():181-190`. |
| **F3.3** `was_empty_prev` en `check()` + return | ✅ Cumple | `QuestionChecker.php:159-160, 171, 202` — detecta `found===true && prevFound===false`. |
| **F3.4** Notificación payload + paso a mail | ✅ Cumple (dato) / **Parcial (UI)** | `AnswerChangedNotification::toDatabase` + `toMail` correctos. Ver hallazgo 4.1. |
| **F3.5** Mail copy especial | ✅ Cumple | `answer-changed.blade.php:34-40`. |
| **F3.6** Eager-load `currentVersion` (sin N+1) | ✅ Cumple | `QuestionFeed::getQuestionsProperty` → `with('repository','currentVersion')`; card usa `currentVersion?->was_empty_prev`. |
| **F3.7** Card copy especial | ✅ Cumple | `question-card.blade.php:25-34` — badge teal cuando `wasEmptyPrev`. |
| **F3.8** Tests (a–e) | ✅ Cumple | `AnswerWasEmptyPrevTest` (sub-casos + payload + mail). |
| **F4.1** Test E2E sin→con | ✅ Cumple | `VigilanciaFoundFalseTest::test_found_false_to_true_full_flow`. |
| **F4.2** Job procesa `found:false` (sin gates) | ✅ Cumple | `test_job_processes_found_false_questions_when_due` + `test_job_detects_transition_to_found_true`. |
| **F4.3** Regresión Kuaforia/QBK | ✅ Cumple | `test_regression_qbk_...` y `test_regression_kuaforia_...`. |
| **F5.1** Suites P1/P3/P4 | ✅ Cumple | Verificado (87 passed). |
| **F5.2** Job con QBK real | ✅ Cumple (lado servidor) / ⏳ visual usuario | `ola1-puntos5y6-verificacion-pruebas.md` §3. |
| **F5.3** Rebuild assets + clases en bundle | ✅ Cumple | `grep` en `app-C32BQ6W8.css` confirma clases. |
| **F5.4** Pint | ✅ Cumple | passed. |

**Conclusión fase a fase:** 22 de 23 tareas cumplen por completo. La tarea **F3.4** es la única
de cumplimiento parcial, y se detalla en el hallazgo 4.1.

---

## 4. Hallazgos de calidad de código

**No hay hallazgos bloqueantes.**

### Debería corregirse

**4.1 — «Notificación in-app» del entregable F3 no tiene superficie de rendering (correspondencia parcial).**

El plan (tarea 3.4 y entregable verificable de Fase 3) exige que el copy especial
"Ahora hay información sobre algo que preguntaste" se muestre en la **notificación in-app**.

El dato **se persiste correctamente** en el payload (`toDatabase`, clave `was_empty_prev`), pero
**no existe ninguna vista que lo renderice en la UI de notificaciones**:

- `notification-badge.blade.php` solo muestra un **contador** y al hacer click navega a
  `questions.show` (no muestra el texto del cambio en ningún momento).
- `question-detail.blade.php` (bloque de review, líneas 81-134) muestra "Cambio detectado — vX → vY"
  con diff/similitud/configfianza, **sin** el copy especial ni referencia a `was_empty_prev`.

Resultado: el copy especial solo es visible en **la card del feed** y **el mail** — no en la
notificación in-app ni en el detalle adonde esta navega.

*Sugerencia:* o renderizar `was_empty_prev` en el bloque de review del detalle, o declarar
explícitamente en el cierre que la "notificación in-app" se limita a navegar (y que el copy
visible es feed + mail). Al estar el dato ya persistido, el fix es mínimo.

**4.2 — `getShowSourceProperty()` hace 1 query por render.**

Aceptable por volumen actual y no es N+1 (es correcto). Como el valor es estable al nivel del
usuario, podría computarse una sola vez por request. Nota de performance menor.

### Sugerencias

- **Backfill de migración:** las filas pre-existentes quedan con `found=true` por default. Para
  preguntas QBK "sin respuesta" creadas antes de P5/6 y nunca re-consultadas, su v1 queda
  `found=true`; el impacto es bajo (solo afecta al primer `check()` posterior), pero conviene
  documentarlo o hacer backfill si se quiere exactitud histórica. **No bloquea.**
- **Color del badge especial:** se usa `bg-teal-100`/`text-primary` (distinto del naranja del
  badge normal). El plan dijo "mismo estilo visual, texto distinto"; el color distinto es una
  mejora de UX coherente con la semántica, no un incumplimiento. Solo anotarlo como intencional.

---

## 5. Cobertura de tests

**Bien cubierto:**
- Transición `false → true` en todas sus capas: unit (`QuestionChecker`), job
  (`CheckQuestionUpdatesJob`), notificación (payload), mail (HTML) y card (feed).
- Condición de fuente: 1 repo / 2 repos activos / repo inactivo (NB2).
- Copy de vigencia: feed / detalle / repo invalid (F1.5).
- Regresión Kuaforia y QBK.

**Quedó sin cubrir / a confirmar:**
- **Verificación visual en navegador real (F3.6 / §3.1.2)** — declarada **pendiente por el
  usuario** en `ola1-puntos5y6-verificacion-pruebas.md` (§6.1): confirmar el badge "Ahora hay
  información...", el copy honesto y el contraste en devtools.
- **F3.5 (fuente con >1 repo)** — no probado en vivo (el usuario `ccorrea` tiene 1 solo repo
  activo); solo cubierto por tests. Declarado honestamente en §6.2.
- No hay test automatizado del copy especial **en el detalle** (`question-detail`) para
  `was_empty_prev` — coincide con el hueco 4.1.

**Sobre la evidencia real:**
- `ola1-puntos5y6-verificacion-pruebas.md` es un **documento de verificación con evidencia real**
  (sesiones 14/17/18, nodos Q-9468/H-030/NK-8643, v2 con `was_empty_prev=1`, notificación creada) —
  consistente con el flujo implementado. La evidencia es confiable y auditable contra la BD.

---

## 6. Preguntas abiertas para el equipo implementador

1. **¿Dónde debe verse el copy especial de la "notificación in-app"?** (Hallazgo 4.1).
   ¿Se agrega al bloque de review del detalle, se deja solo en feed + mail, o se cambia la
   redacción del entregable? Es una decisión de producto pendiente.

2. **Hallazgo H-A (del documento de verificación):** `found:false` solo ocurre cuando **ningún
   token coincide** con el grafo; preguntas con palabras comunes devuelven `found:true` con answer
   tipo "no encontré información...". Esto limita la efectividad de `was_empty_prev` como señal
   universal de "sin respuesta → con respuesta". Es una **decisión de producto no resuelta en este
   plan** (señalada como Q1/Q2, H1/H2), no un defecto de implementación — pero debe cerrarse antes
   de considerar el Punto 6 completamente sólido.

3. **Rebuild/assets:** no se pudo verificar el *momento* en que se corrió `npm run build`
   (bitácora), solo que las clases están en el bundle actual. Si el bundle se regeneró tras el
   código, está bien; si no, es un riesgo para otros despliegues (`/public/build` está en
   `.gitignore`).

---

## 7. Resumen ejecutivo para el equipo

Implementación **sólida, sin regresión, retrocompatible, bien testeada y con evidencia real contra
QuBeKa**.

Único punto a resolver antes de dar por cerrado el Punto 6:
- **Superficie de la "notificación in-app"** para el copy especial (hallazgo 4.1) — el dato se
  persiste, pero hoy el copy solo es visible en feed y mail.

Pendientes de producto (no bloquean la entrega, pero sí la solidez del Punto 6):
- Hallazgo **H-A** y preguntas **Q1/Q2 / H1/H2** del plan (señal de "sin respuesta" basada en
  `found` limitada por el buscador de QuBeKa).
- Verificación visual en navegador real (F3.6) y fuente con >1 repo (F3.5), a cargo del usuario.
