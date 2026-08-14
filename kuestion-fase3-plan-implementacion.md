# Kuestion — Plan de Implementación · Fase 3 (Usabilidad y claridad)

> **Versión:** 1.1 | **Fecha:** 2026-08-14 | **Fuente:** `Plan_Mejora_Kuestion_v2.4.md` (cerrado, v2.4) | **Alcance:** Bloques 11–14 | **Estado:** documento de implementación (el CÓMO)
>
> **v1.1 (2026-08-14):** incorpora las resoluciones de producto/tecnología/ingeniería sobre las preguntas abiertas de la v1.0 (ver §6). Cambios: 11.4 pospuesto (solo feed vacío); 12.4 (tendencias desde `daily_metrics`) incluida; 14.3 flag `relations_graph` apagado por defecto confirmado; agregados del dashboard solo preguntas activas.
>
> **Autocontenido:** este documento incluye el contexto, las restricciones y las decisiones pendientes del documento maestro que aplican a esta fase. No reabre decisiones cerradas (QUÉ/POR QUÉ); define cómo implementarlas. Las tareas se apoyan en herramientas de IA generadora (Opencode u otras): la sección 4 indica dónde reutilizar patrones existentes y cómo acotar el contexto por tarea.

---

## 1. Contexto de la fase

### 1.1 Qué cubre esta fase

| Bloque | Objetivo (del maestro) | Esfuerzo |
|---|---|---|
| **11 — Primera experiencia (onboarding)** | Ejemplo interactivo de pregunta ficticia con diff simulado antes de la primera pregunta real (3.1) | S-M (1.5–2 d) |
| **12 — Panorama de equipo** | Vista agregada de "salud del tenant" para usuarios con `team_dashboard_access = 'readonly'` (3.2) | M (2–3 d) |
| **13 — Señales de estado** | Badge de cambios sin revisar por tag en el índice de tags (3.3) + histórico de feedback por versión en el detalle (3.4) | S-M (1.5–2 d) |
| **14 — Red de relaciones** | Visualización liviana del grafo de relaciones entre preguntas (3.5) | M (2–3 d) |

**Total estimado:** ~7–10 días hábiles. Dependencias ítem por ítem (el maestro no define bloqueo general de la fase); la única dependencia entre fases es **Bloque 12 → 1.5 (métricas)**.

### 1.2 Restricciones del documento maestro aplicables a esta fase

1. **No se modifica la integración REST con Kuaforia en su esencia:** el `POST /api/consult/{tenant_slug}` síncrono, el mecanismo de detección (hash SHA-256 + similitud coseno) y el `CheckQuestionUpdatesJob` permanecen intactos. **Esta fase es 100% UI/frontend** (salvo migraciones inocuas de columnas de usuario): no se toca backend de consulta ni detección.
2. **Se prioriza la deuda técnica como base:** las fases 1 y 2 (deuda + arquitectura) preceden a esta en el roadmap del maestro; los bloques 11–14 son capas de UI que no modifican el comportamiento central.
3. **Nota de privacidad del maestro (Bloque 12):** la vista de salud del tenant asume que el `tenant_slug` es un equipo de confianza y que, por ahora, no hay distinción de subgrupos dentro del mismo tenant. Es una decisión consciente para el piloto inicial; si en el futuro se necesita granularidad, se abordará con un sistema de roles. **El campo `team_dashboard_access` se documenta como solución temporal**, a ser reemplazada por roles.

### 1.3 Decisiones pendientes del maestro aplicables a esta fase

- **Ninguna.** Las decisiones pendientes del maestro (#1 validación de tenant y #2 tools MCP) corresponden a la Fase 1 (Bloque 6) y a la Fase 2 (Bloque 8) respectivamente. Si durante la planificación surge algo que requiere decisión de producto no cubierta por el maestro, se registra en la sección 6.

### 1.4 Estado real del código relevante (verificado en el repo)

| Aspecto | Realidad en el código |
|---|---|
| Onboarding actual | `resources/views/auth/onboarding.blade.php` ("Cuenta creada con éxito" + CTAs). El feed vacío (`question-feed.blade.php`) tiene un empty state con CTA "Escribe tu primera pregunta". No hay ejemplo interactivo. |
| Índice de tags | `TagIndex` calcula tag → count (user-scoped, activas) y renderiza cards que enlazan a `route('questions.index', ['tag' => ...])`. **Gap pre-existente:** `QuestionFeed` **no maneja** el parámetro `tag` (su `queryString` es solo `filter`/`search`); hoy el enlace pasa `?tag=` pero el feed lo ignora. 3.3 requiere cerrar ese gap. |
| Feedback | `answer_versions.feedback` (nullable: `helpful`/`not_helpful`); `FeedbackButtons` lo setea en la versión actual. `version-timeline.blade.php` (componente) muestra confianza/fuentes/estado pero **no** feedback. |
| Relaciones | `question_relations` (source→target, label); `RelationsPanel` (salientes, add/remove), `BacklinksPanel` (entrantes). No hay visualización de grafo. |
| Usuarios | No existe `team_dashboard_access`. |
| Métricas | No existe `daily_metrics` (Fase 1, Bloque 5) — dependencia del Bloque 12. |
| Aislamiento | Todas las queries scoped por `current_user_id()`. **Excepción de diseño:** el Bloque 12 agrega por `tenant_slug` a través de usuarios (ver 3.2 y nota de privacidad). |

### 1.5 Secuencia recomendada y dependencias finas

```
Bloque 11 (3.1 onboarding)  ── independiente (UI), calentamiento
Bloque 13 (3.3 + 3.4)       ── independiente; 3.3 y 3.4 son tareas separadas
Bloque 12 (3.2 dashboard)   ── depende de 1.5 (métricas) para la sección de tendencias
Bloque 14 (3.5 grafo)       ── independiente (UI); rollout con flag (ver 3.5)
```

- **Bloque 13:** 3.3 (badges de tag) y 3.4 (feedback en timeline) son independientes entre sí; pueden ir en cualquier orden.
- **Bloque 12:** la dependencia con 1.5 es solo para la sección de tendencias (`daily_metrics`); los agregados en vivo se pueden construir igual. Para respetar el maestro, el bloque se planifica **después** de Fase 1 Bloque 5.
- **Bloque 14:** se construye completo pero se activa mediante flag de config (ver decisión en 3.5 y pregunta abierta en §6).

---

## 2. Diseño técnico por bloque

### Bloque 11 — Primera experiencia: onboarding con ejemplo interactivo — Esfuerzo S-M (~1.5–2 d)

**Criterios de aceptación (del maestro):**
- El usuario ve un diff visual antes de crear su primera pregunta.
- El ejemplo no persiste en BD (es hardcodeado en la UI).
- El onboarding se puede saltar con un botón "Omitir".

**Desglose de tareas:**

| ID | Tarea | Archivos clave | Dep | Esf |
|----|-------|----------------|-----|-----|
| 11.1 | Crear componente Livewire `OnboardingExample` con datos **hardcodeados** (no toca modelos): pregunta ficticia (ej. "¿Cuál es la política de reembolsos?"), respuesta "actual" (v1) y respuesta "nueva" (v2 simulada). Estados internos: `idle` → `diff` → `resolved` (aceptado/descartado). Botón "Simular cambio" revela el diff (líneas añadidas/eliminadas con el mismo estilo visual del diff real de `question-detail.blade.php`) y botones Aceptar/Descartar que solo cambian estado local. Botón "Omitir". | `app/Livewire/OnboardingExample.php` (nuevo), `resources/views/livewire/onboarding-example.blade.php` (nuevo) | — | M |
| 11.2 | Embed en el empty state del feed (`question-feed.blade.php`), debajo del CTA existente ("Escribe tu primera pregunta" se mantiene). | `resources/views/livewire/question-feed.blade.php` | 11.1 | S |
| 11.3 | Persistir el "omitir": columna `has_seen_example` (boolean, default `false`) en `users` + cast. "Omitir" la setea en `true`; el ejemplo se muestra solo si `!auth()->user()->has_seen_example`. *(Decisión: ver pregunta abierta §6.1.)* | migración nueva, `app/Models/User.php`, componente | 11.1 | S |
| 11.4 | **(Pospuesto — resolución de revisión)** Reutilizar el mismo componente en `onboarding.blade.php` (post-registro): fuera del alcance de esta entrega, el ejemplo va solo en el feed vacío. Duplicarlo en dos pantallas del mismo momento puede sentirse repetitivo; se reconsidera si se detecta que la gente pasa de largo el onboarding. | — | 11.1 | — |

**Decisiones de implementación:**
- **Datos hardcodeados en el componente** (array/constantes), cero consultas a BD; el criterio "no persiste en BD" se verifica con un test que asserts que ninguna tabla cambia tras interactuar.
- El diff simulado reutiliza las mismas clases Tailwind del diff real (verde añadido, rojo eliminado) para que el usuario vea exactamente lo que verá con sus preguntas reales.
- El "Omitir" no borra nada: solo marca el flag y oculta el ejemplo. Al crear la primera pregunta, el empty state desaparece y el ejemplo deja de mostrarse de forma natural.
- **Nota de implementación (2026-08-14, Bloque 11 implementado):**
  - **11.1:** `app/Livewire/OnboardingExample.php` + `resources/views/livewire/onboarding-example.blade.php`. Datos hardcodeados como propiedades públicas (pregunta ficticia "¿Cuál es la política de reembolsos?", respuesta v1 de 2 líneas y v2 de 3 líneas). El diff se genera con **`DiffGenerator`** (el mismo motor del diff real) sobre los strings ficticios — cero consultas a BD y mismo formato visual exacto (`bg-green-50`/`bg-red-50`, `font-mono`, `x-badge variant="warning"`). Estados `idle → diff → accepted|dismissed`; botones "Simular cambio", "Aceptar cambio", "Descartar cambio" y "Omitir". Microcopy de UX que explica el valor ("vigila respuestas y te avisa cuando cambian") y qué pasa en cada estado.
  - **11.2:** embed en el empty state del feed (`question-feed.blade.php`), debajo del CTA "Escribe tu primera pregunta" (que se mantiene), condicionado a `! auth()->user()->has_seen_example`.
  - **11.3:** migración `2026_08_14_000009_add_has_seen_example_to_users_table` (boolean default false, after `email_notifications`) + `fillable`/cast `boolean` en `User`. `skip()` setea el flag y oculta el ejemplo (`hidden`); el flag en BD garantiza que no reaparece entre sesiones (resolución §6.1).
  - **11.4:** pospuesto, como resolución de revisión — el ejemplo va solo en el feed vacío.
  - **QA:** `OnboardingExampleTest` (6 tests, 21 assertions): feed vacío renderiza el ejemplo; usuario con `has_seen_example` no lo ve; simular revela el diff (línea añadida visible); aceptar/descartar solo cambian estado local; **cero persistencia** en `questions`/`answer_versions`/`notifications` tras interactuar; omitir persiste el flag y oculta. Suite completa 83 tests (243 assertions) — 77 previos + 6 nuevos. `php artisan view:cache` OK; `vendor/bin/pint` PASS.

### Bloque 12 — Panorama de equipo: vista agregada de salud del tenant — Esfuerzo M (~2–3 d)

**Criterios de aceptación (del maestro):**
- Se muestran: total de preguntas, % con cambios sin revisar, tags más vigilados.
- El acceso se controla por un campo `team_dashboard_access` (enum: `none`, `readonly`) en `users`.
- Es solo lectura (no permite acciones).
- El campo se documenta como solución temporal, a ser reemplazada por un sistema de roles.

**Desglose de tareas:**

| ID | Tarea | Archivos clave | Dep | Esf |
|----|-------|----------------|-----|-----|
| 12.1 | Migración `users`: `team_dashboard_access` string(20) default `'none'` (convención del proyecto: strings para enums, valores `none`/`readonly`) + cast en `User`. | migración nueva, `app/Models/User.php` | — | S |
| 12.2 | Ruta `/team` (auth) + componente Livewire `TeamDashboard` con gate por `team_dashboard_access === 'readonly'` (si `none` → redirect/403). | `routes/web.php`, `app/Livewire/TeamDashboard.php` (nuevo) + vista | 12.1 | S |
| 12.3 | Agregados **en vivo por `tenant_slug`** (a través de usuarios del mismo tenant, no solo el propio — excepción de diseño documentada): total de preguntas activas, % con `has_unreviewed_changes=true`, top tags (conteo por tag sobre las preguntas del tenant). Solo lectura: sin acciones de escritura. | `app/Livewire/TeamDashboard.php` | 12.2 | M |
| 12.4 | Sección de tendencias desde `daily_metrics` (dependencia 1.5): preguntas creadas/detectados por semana si hay datos; **degradación con gracia** si la tabla está vacía (ocultar sección, no fallar). **Incluida (resolución de revisión):** para cuando se construya el Bloque 12, el Bloque 5 (Fase 1) ya estará hecho; no hay razón para dejarla afuera. | `app/Livewire/TeamDashboard.php` | 12.3, Fase 1 Bloque 5 | S |
| 12.5 | Documentar en el código y en el UI (nota de pie) que el acceso es una solución temporal (reemplazable por roles) y la nota de privacidad del maestro (tenant = equipo de confianza, sin subgrupos). | componente + vista | 12.2 | S |

**Decisiones de implementación:**
- **Scope del agregado:** el maestro define "todas las preguntas del mismo `tenant_slug`". Se consulta por `tenant_slug` (join/whereIn sobre `users` con ese slug), **no** por `current_user_id()`. Es la única vista de la app que cruza usuarios; se aísla en el componente y se protege con el gate.
- Las queries agregadas se hacen en el componente (Livewire), sin tocar `QuestionController` ni la API.
- Los criterios del maestro listan 3 métricas (total, % sin revisar, top tags); las tendencias de `daily_metrics` se **incluyen** (resolución de revisión, ver §6) como sección con degradación con gracia si faltan datos. Los agregados (12.3) incluyen **solo preguntas activas** (resolución de revisión: un panel de "salud del equipo" mira el estado vigente, coherente con cómo el resto de la app trata lo archivado).
- **Nota de implementación (2026-08-14, Bloque 12 implementado):**
  - **12.1:** migración `2026_08_14_000010_add_team_dashboard_access_to_users_table` — string(20) default `'none'` (valores `none`/`readonly`, convención strings para enums) + `fillable`/cast `string` en `User`.
  - **12.2:** ruta `/team` (`team.index`, auth) + `app/Livewire/TeamDashboard` con gate en `mount()`: `abort_unless(team_dashboard_access === 'readonly', 403)`. **Mejora de UX agregada:** link "Equipo" en el header solo para usuarios `readonly` (sin link la vista sería inaccesible desde la UI).
  - **12.3:** agregados **en vivo por `tenant_slug`** (únicos en la app que cruzan usuarios, aislados en el componente y protegidos por el gate): `User::where('tenant_slug', ...)->pluck('uuid')` → preguntas activas del tenant (total, % con `has_unreviewed_changes`, top 5 tags). **Solo activas** (resolución §6.4); `SoftDeletes` del modelo ya excluye archivadas/eliminadas.
  - **12.4:** tendencias semanales desde `daily_metrics` — últimas 8 semanas agrupadas por semana ISO (`isoWeek`), sumando `preguntas_creadas` y `cambios_detectados`; degradación con gracia: sección oculta si la tabla está vacía (sin error). Barras CSS puras (sin librerías), coherentes con la convención del proyecto. Las métricas son globales de la app; con un solo tenant (piloto) equivalen a las del tenant (coherente con la nota de privacidad).
  - **12.5:** nota de pie en la vista (solución temporal por roles + nota de privacidad del maestro) y comentarios en el componente.
  - **QA:** `TeamDashboardTest` (7 tests): gate (none → 403); agregados **incluyen las preguntas del otro usuario del mismo tenant** (scope por tenant); excluye otros tenants; **solo activas** (archivada no cuenta); top tags; tendencias ocultas sin `daily_metrics` (degradación) y visibles con datos. Suite completa 101 tests (278 assertions) — 94 previos + 7 nuevos. `vendor/bin/pint` PASS.

### Bloque 13 — Señales de estado en el flujo existente — Esfuerzo S-M (~1.5–2 d)

#### 3.3 Panel de salud por tags

**Criterios de aceptación (del maestro):**
- El badge se actualiza dinámicamente.
- Al hacer clic en el badge, se filtra el feed por ese tag y estado "con cambios".

**Desglose de tareas:**

| ID | Tarea | Archivos clave | Dep | Esf |
|----|-------|----------------|-----|-----|
| 13.1 | `TagIndex::getTagsProperty`: agregar por tag el conteo de preguntas con `has_unreviewed_changes=true` (segunda query: preguntas con cambios sin revisar del usuario, `pluck('tags')`, contar por tag). | `app/Livewire/TagIndex.php` | — | S |
| 13.2 | Vista `tag-index.blade.php`: badge en cada card de tag con el número de "sin revisar" (oculto si 0), mismo estilo de badge que el feed. | `resources/views/livewire/tag-index.blade.php` | 13.1 | S |
| 13.3 | **Cerrar el gap pre-existente del feed:** `QuestionFeed` agrega `tag` a `queryString` + filtro `whereJsonContains('tags', $this->tag)` + `resetPage()` en `updatedTag`. Sin esto, ningún enlace de tag funciona (hoy `?tag=` se ignora). | `app/Livewire/QuestionFeed.php` | — | S |
| 13.4 | Click en el badge → `route('questions.index', ['filter' => 'changes', 'tag' => $tag])` (el card completo mantiene el enlace por tag simple, comportamiento actual). | `resources/views/livewire/tag-index.blade.php` | 13.2, 13.3 | S |

#### 3.4 Feedback visible y acumulado

**Criterios de aceptación (del maestro):**
- Se ve una línea de tiempo de feedback.
- Se indica si la percepción de utilidad ha mejorado o empeorado.
- El feedback sigue siendo simple (👍/👎).

**Desglose de tareas:**

| ID | Tarea | Archivos clave | Dep | Esf |
|----|-------|----------------|-----|-----|
| 13.5 | Extender `version-timeline.blade.php`: por versión, ícono 👍/👎 según `$version->feedback` (nulo → sin ícono) y, comparando con la versión anterior en el loop, etiqueta "mejoró" (`not_helpful` → `helpful`) o "empeoró" (`helpful` → `not_helpful`). Lógica en el propio componente (sin cambios en `QuestionDetail`). | `resources/views/components/version-timeline.blade.php` | — | S |
| 13.6 | Verificar que `FeedbackButtons` (👍/👎 sobre la versión actual) no cambia — el feedback sigue siendo simple. | — (verificación) | 13.5 | S |

**Decisiones de implementación:**
- El trend se calcula comparando el feedback de la versión con el de la versión inmediatamente anterior (orden `version_number` desc en el timeline). Si una de las dos no tiene feedback, no se muestra etiqueta.
- 3.3 y 3.4 son independientes; se pueden implementar como cambios separados y committeables.
- **Nota de implementación (2026-08-14, Bloque 13 implementado):**
  - **13.1:** `TagIndex::getTagsProperty` — segunda query (preguntas con `has_unreviewed_changes=true` del usuario, `pluck('tags')` → contar por tag) agregada al resultado como clave `unreviewed` por tag. **Sin filtro de status**, igual que el feed con `filter=changes`: el número del badge coincide con lo que muestra el feed al hacer clic (consistencia badge↔feed).
  - **13.2/13.4:** `tag-index.blade.php` — el card pasó de `<a>` envolvente a `div` relativo con el enlace por tag simple adentro (comportamiento del card intacto, HTML válido sin `<a>` anidados) + badge absoluto arriba a la derecha con el estilo del feed (`bg-orange-100 text-orange-700` + punto `bg-orange-500`), oculto si `unreviewed === 0`, que enlaza a `questions.index?filter=changes&tag=X`.
  - **13.3:** `QuestionFeed` — nueva prop `tag` (string, en `queryString`), filtro `whereJsonContains('tags', $tag)` y `resetPage()` en `updatedTag()`. **Cierra el gap pre-existente**: antes `?tag=` se ignoraba y ningún enlace de tag del índice funcionaba. Los filtros `all`/`changes`/`starred` y la búsqueda quedan intactos (test de combinación tag + changes).
  - **13.5:** `version-timeline.blade.php` — por versión: badge 👍 (verde) / 👎 (rojo) según `feedback` (nulo → sin ícono) y trend "mejoró"/"empeoró" calculado en `@php` del loop comparando con la versión **anterior en el tiempo** (la que sigue en el loop desc, `$versions[$loop->index + 1]`), mostrado en la versión más nueva de cada par. Sin cambios en `QuestionDetail`.
  - **13.6:** `FeedbackButtons` intacto (verificado — el archivo no se tocó; sigue 👍/👎 simple).
  - **QA:** `TagIndexTest` (6 tests: badge visible, ausente si 0, desaparece al aceptar, enlace al filtro de cambios, feed filtra por tag, feed combina tag+changes) + `VersionTimelineFeedbackTest` (5 tests: íconos por versión, mejoró, empeoró, sin trend si falta feedback previo, sin ícono sin feedback). Suite completa 94 tests (264 assertions) — 83 previos + 11 nuevos. `vendor/bin/pint` PASS.

### Bloque 14 — Red de relaciones: visualización — Esfuerzo M (~2–3 d)

**Criterios de aceptación (del maestro):**
- El usuario ve de un vistazo cuántas preguntas están conectadas y cómo, sin tener que navegarlas de a una vía backlinks.
- La visualización es liviana (no pretende ser un motor de grafo complejo).
- Se construye cuando exista una masa crítica de relaciones (recomendado: activar cuando el piloto de Ispend tenga al menos 10 preguntas con relaciones).

**Desglose de tareas:**

| ID | Tarea | Archivos clave | Dep | Esf |
|----|-------|----------------|-----|-----|
| 14.1 | Componente `RelationsGraph` (Livewire o partial Blade): **ego-network de 1 salto** — pregunta central + relaciones salientes y entrantes directas (datos de `question_relations` vía `outboundRelations`/`inboundRelations`, mismo patrón que `RelationsPanel`/`BacklinksPanel`), layout radial en SVG generado server-side (sin librerías de grafo). Nodos clicables → navegan al detalle. | `resources/views/components/relations-graph.blade.php` (nuevo) o Livewire | — | M |
| 14.2 | Embed en el detalle (`question-detail.blade.php`), como panel plegable junto a relaciones/backlinks; visible solo si la pregunta tiene ≥1 relación. | `resources/views/livewire/question-detail.blade.php` | 14.1 | S |
| 14.3 | Flag de rollout: `config('kuestion.features.relations_graph')` (default `false`, **confirmado por resolución de revisión**) que controla si el componente se renderiza. Responde al criterio de masa crítica del maestro: activar cuando el piloto acumule ~10 preguntas relacionadas (mostrar un grafo casi vacío en el lanzamiento resta más de lo que suma). | `config/kuestion.php`, componente | 14.2 | S |

**Decisiones de implementación:**
- **Sin dependencias nuevas:** SVG generado en el servidor con las clases Tailwind existentes (nodos = círculos con iniciales, aristas = líneas con el `label` de la relación). El layout radial con 1 salto es suficiente para el criterio "de un vistazo" y evita un motor de grafo.
- La masa crítica es un gate de **rollout** (flag de config), no de código: la feature se construye y se testea completa; queda **apagada por defecto** y se activa cuando el piloto acumule ~10 preguntas relacionadas (resolución de revisión, ver §6).
- Scope: solo relaciones del usuario logueado (mismo aislamiento que el resto de la app; no se cruzan usuarios).
- **Nota de implementación (2026-08-14, Bloque 14 implementado):**
  - **14.1:** `resources/views/components/relations-graph.blade.php` (componente Blade, no Livewire — no necesita estado ni acciones). Ego-network de 1 salto con el mismo patrón de datos que `RelationsPanel`/`BacklinksPanel` (`outboundRelations()->with('target:...')` + `inboundRelations()->with('source:...')`), nodos deduplicados por id (un vecino que es saliente Y entrante aparece una sola vez). Layout radial en SVG server-side (`viewBox 0 0 320 320`, centro teal, vecinos naranja, ángulo uniforme, sin librerías). Nodos clicables (`<a href>` + `wire:navigate`) → navegan al detalle; aristas `<line>` con el label en `<title>` (tooltip); etiqueta del nodo con `mb_strimwidth(..., 22, '…')`. El componente se oculta solo si no hay relaciones (renderiza nada).
  - **14.2:** embed en `question-detail.blade.php` (después de backlinks), controlado por el flag — el componente es autocontenido (panel completo con su `bg-surface`).
  - **14.3:** `config('kuestion.features.relations_graph')` default `false` (`env KUESTION_FEATURE_RELATIONS_GRAPH`), con comentario del rollout (masa crítica ~10 preguntas relacionadas, resolución §6.2).
  - **QA:** `RelationsGraphTest` (4 tests): N+1 nodos (2 relaciones → 3 círculos) y aristas con label + URLs de navegación; dedupe de vecino compartido (2 aristas → 2 nodos); oculto sin relaciones; **flag on/off controla el render en el detalle** (`Livewire::test(QuestionDetail::class)`). Suite completa 105 tests (291 assertions) — 101 previos + 4 nuevos. `package.json`/`package-lock.json` sin cambios (verificación del criterio "visualización liviana"). `vendor/bin/pint` PASS.

---

## 3. Verificación (QA/Review)

### 3.1 Mapa de criterios de aceptación → verificación

| Criterio (bloque) | Verificación automatizada | Verificación manual |
|---|---|---|
| Diff visual antes de la primera pregunta (3.1) | Livewire test: con feed vacío, el empty state renderiza `OnboardingExample`; al hacer clic en "Simular cambio" aparece el diff. | Registro nuevo → feed vacío → ver el ejemplo y simular el cambio. |
| El ejemplo no persiste en BD (3.1) | Test: tras interactuar (simular, aceptar, omitir), `questions`/`answer_versions`/`notifications` siguen con count 0. | — |
| Onboarding se puede saltar (3.1) | Test: clic en "Omitir" oculta el ejemplo y setea `has_seen_example=true` (no reaparece). | Clic en "Omitir" y recargar. |
| Total, % sin revisar, top tags (3.2) | Livewire test: seed de 2 usuarios del mismo tenant con preguntas (una con `has_unreviewed_changes`) → el dashboard muestra agregados **que incluyen las preguntas del otro usuario** (scope por tenant). | Vista `/team` con usuario `readonly`. |
| Acceso controlado por `team_dashboard_access` (3.2) | Test: usuario con `none` → 403/redirect; con `readonly` → 200. | Alternar el campo y recargar. |
| Solo lectura (3.2) | Revisión de código: el componente no expone métodos de escritura; test: no hay endpoints mutantes asociados. | La vista no ofrece acciones. |
| Badge de tag dinámico (3.3) | Test `TagIndex`: con una pregunta con cambios sin revisar, su tag reporta el badge; al aceptar el cambio, desaparece. | Marcar/aceptar un cambio y ver el índice de tags. |
| Click en badge filtra tag + "con cambios" (3.3) | Feature/Livewire test: desde el badge se llega a `questions.index?filter=changes&tag=X` y el feed filtra correctamente (usa el nuevo soporte `tag` de `QuestionFeed`). | Clic en badge → feed filtrado. |
| Línea de tiempo de feedback (3.4) | Test del componente: versiones con `feedback` renderizan 👍/👎 en el timeline. | Detalle de una pregunta con varias versiones con feedback. |
| Mejoró/empeoró indicado (3.4) | Test del componente: secuencia `helpful`→`not_helpful` muestra "empeoró"; `not_helpful`→`helpful` muestra "mejoró". | Idem visual. |
| Feedback sigue simple (3.4) | `FeedbackButtons` sin cambios; tests existentes pasan. | — |
| Grafo visible de un vistazo (3.5) | Test del componente (flag on): con N relaciones, el SVG renderiza N+1 nodos y las aristas con label. | Detalle con relaciones y flag activado. |
| Visualización liviana (3.5) | Sin dependencias nuevas (package.json sin cambios); SVG server-side. | Inspección de la vista. |
| Masa crítica (3.5) | El flag `relations_graph` (default `false`) controla el render (test con flag on/off). | Activar el flag cuando el piloto acumule ~10 preguntas relacionadas (resolución de revisión, ver §6). |

### 3.2 Plan de regresión — alerta roja

**Lo que NO debe romperse en esta fase. Cualquier cambio en estos puntos es una alerta roja y debe detener el merge:**

1. **`POST /api/consult/{tenant_slug}`**, `ChangeDetector` (hash + similitud) y `CheckQuestionUpdatesJob`: **no se tocan en esta fase** (100% UI). El `git diff` de la fase no debe incluir ninguno de estos archivos.
2. **Aislamiento por `current_user_id()`**: se mantiene en todos los bloques salvo el caso explícito y acotado del Bloque 12 (agregado por `tenant_slug` con gate `readonly` + nota de privacidad). El resto de la app no cruza usuarios.
3. **Feed existente**: al agregar el filtro `tag` a `QuestionFeed` (13.3), los filtros `all`/`changes`/`starred` y la búsqueda no deben romperse (tests existentes + tests nuevos de combinación).
4. **Timeline de versiones**: 13.5 solo agrega contenido por versión; los clics `showDiff` y el resaltado de la versión actual se mantienen.
5. **Suite actual**: los 16 tests (27 assertions) pasan después de cada bloque.

### 3.3 Validación aislada por bloque

- Cada bloque se cierra con sus tests + smoke manual mínimo (ver 3.1).
- Orden de cierre sugerido: **11 → 13 → 14 → 12** (12 al final por la dependencia con 1.5 de la Fase 1; si 1.5 aún no está, 12 se cierra con la sección de tendencias degradada y se marca).
- Cierre de fase: suite completa + smoke end-to-end del flujo nuevo (registro → onboarding → crear → tags → feedback → grafo con flag) + `git diff` acotado (verificar que no hay cambios en REST/hash/job).

---

## 4. Eficiencia de código/tokens

**Reutilizar patrones existentes (no generar lógica nueva):**

| Tarea | Reutilizar |
|---|---|
| 11 (onboarding) | Empty state existente del feed (`question-feed.blade.php`) y estilos del diff real (`question-detail.blade.php` — mismas clases verde/rojo). Patrón Livewire `#[Layout('layouts::app')]` + estado local. |
| 12 (dashboard) | Patrón de componentes Livewire + computed properties (como `TagIndex::getTagsProperty`); convención de columnas string para enums. Las queries agregadas por tenant son el único caso nuevo de scope. |
| 13.3 (badges) | El cálculo tag→count ya existe en `TagIndex`; solo se agrega el conteo de "con cambios" (misma técnica). El filtro `tag` del feed replica `whereJsonContains` que ya usa `QuestionController::index`. |
| 13.4 (feedback) | Extender `version-timeline.blade.php` (componente existente) sin tocar `QuestionDetail`; `FeedbackButtons` queda intacto. |
| 14 (grafo) | Datos de `RelationsPanel`/`BacklinksPanel` (`outboundRelations`/`inboundRelations` con `with('target:id,...')`); estilos de cards/nodos con las clases existentes. |

**División en sub-tareas verificables (preferir cambios chicos):**

- **11 en 2 pasos**: componente (11.1–11.2, testeable con datos hardcodeados) → flag persistente (11.3).
- **13 en 2 cambios independientes**: 3.3 (13.1–13.4) y 3.4 (13.5–13.6) — committeables por separado.
- **14 en 2 pasos**: componente + embed (14.1–14.2, testeable con flag on) → flag de rollout (14.3).

**Acotar el contexto para la IA generadora:**

- Trabajar **por bloque** pasando solo los archivos del bloque; este plan por fase es autocontenido (no incluir el maestro ni los docs de referencia en los prompts).
- 11.1 usa **datos hardcodeados** (sin modelos) — pedir explícitamente que el componente no consulte BD.
- 14 **no agrega dependencias JS** — pedir SVG server-side con las clases existentes.
- Evitar refactors cosméticos fuera de alcance (no tocar `QuestionController`, jobs, servicios ni el mecanismo REST).

---

## 5. Salida para revisión (formato de cierre de la fase)

Al cerrar la fase, entregar un **documento nuevo `.md`** (p.ej. `kuestion-fase3-salida-revision.md`) con, **por bloque**:

1. **Resumen ejecutivo**: qué se hizo (tareas completadas con sus IDs), cómo se verificó.
2. **Evidencia por criterio de aceptación**: tabla `criterio → cómo se comprobó (test/commando/captura) → resultado`. No basta "listo"; mostrar la comprobación.
3. **Desviaciones**: qué quedó distinto de lo planeado y la razón (p.ej., si 11.4 se incluyó o no, si el dashboard incluye tendencias de `daily_metrics`, si el flag del grafo quedó activado).
4. **Riesgos no previstos** en el plan maestro, detectados durante la implementación.
5. **Preguntas abiertas nuevas** surgidas en la implementación.

Plantilla por bloque (igual que Fases 1 y 2):

```markdown
### Bloque X — <nombre>
**Resumen ejecutivo:** ...
**Evidencia por criterio de aceptación:**
| Criterio (maestro) | Cómo se verificó | Resultado |
|---|---|---|
| ... | ... | ✅ / ❌ / ⚠️ |
**Desviaciones:** ...
**Riesgos no previstos:** ...
**Preguntas abiertas nuevas:** ...
```

---

## 6. Preguntas abiertas — resoluciones de producto/tecnología/ingeniería (2026-08-14)

Las preguntas abiertas de la v1.0 fueron resueltas en revisión. Resumen e impacto en este plan:

| # | Pregunta (v1.0) | Resolución | Impacto en el plan |
|---|---|---|---|
| 1 | Persistencia del "Omitir" (3.1) | **Por usuario (`has_seen_example`), como propuesto.** Que el ejemplo reaparezca en cada sesión sería ruido, no ayuda — un onboarding que se repite deja de ser onboarding. | Confirmado: 11.3 sin cambios. |
| 2 | Activación del grafo de relaciones (3.5) | **Apagado por defecto** (`relations_graph = false`); activar cuando el piloto de Ispend acumule las ~10 preguntas relacionadas que recomienda el maestro. Un grafo casi vacío en el lanzamiento resta más de lo que suma. | Confirmado: 14.3 sin cambios. |
| 3 | Tendencias del dashboard desde `daily_metrics` (3.2) | **Incluir.** Para cuando se construya el Bloque 12, el Bloque 5 (Fase 1) ya estará hecho; el diseño de degradación con gracia cubre el caso de dato faltante. | 12.4 incluida (sin la cláusula "opcional"). |
| 4 | Alcance de los agregados del dashboard (3.2) | **Solo activas**, como propuesto: un panel de "salud del equipo" mira el estado vigente, no el historial; coherente con cómo el resto de la app trata lo archivado. | Confirmado: 12.3 sin cambios. |
| 5 | Cobertura del ejemplo de onboarding (3.1) | **Solo el feed vacío por ahora**; 11.4 queda pospuesto. Las dos pantallas apuntan al mismo momento del usuario; duplicarlo puede sentirse repetitivo. Se reconsidera si se ve que la gente pasa de largo. | 11.4 pospuesto. |

---

*Documento generado a partir de `Plan_Mejora_Kuestion_v2.4.md` (v2.4, cerrado). Próxima acción: bloques 11 y 13 (independientes), luego 14 y 12; coordinar con Fase 1 Bloque 5 para el Bloque 12.*
