# Plan de Implementación — Ola 3, Punto 3: Preguntas sugeridas

*Equipo de Kuestion · Septiembre 2026*
*Documento de entrada: `OLA_3_Punto_3.md` (especificación cerrada de producto). Este plan referencia sus secciones (§), no las reescribe.*
*Review de producto incorporado (2026-09-19): P1–P5 respondidas + tres decisiones adicionales (D1–D3) — ver §1.*

---

## 1. RESUMEN DE ALCANCE

Kuestion construye **solo el lado consumidor** del mecanismo de preguntas sugeridas (§5 "A Kuestion"):

1. **Cliente del endpoint** `GET {QUBKA_API_URL}/suggestions` (§3.1) con timeout de 5 s y manejo de error **silencioso**: si QBK falla o tarda, la pantalla se muestra sin sugerencias — la ausencia no es un fallo para el usuario (§4).
2. **Cache de 10 minutos** por usuario y sesión de navegador, invalidado cuando el usuario envía una pregunta y descartado al cerrar sesión (§1.4, §4).
3. **UI** en la pantalla de entrada (`CreateQuestion`, `/questions/create`): sección **"Quizás te interese preguntar"** debajo del campo de texto, lista de 3–5 preguntas clickeables que **precargan** la pregunta en el textarea (nunca la ejecutan) (§1.1, §1.6).
4. **Catálogo local de sugerencias genéricas** para cuando QBK devuelve lista vacía (usuario sin grafo suficiente, §1.7, §3.3) — definidas en Kuestion, no en QBK, y tratadas como el primer copy del producto (A.3 expandido, decisión D1).
5. **Refresco**: al cargar la pantalla, y tras enviar una pregunta. No hay tiempo real ni push (§1.1, §1.4).

**Lo que NO construimos**: la generación y el mecanismo de diversidad de fuentes viven en QuBeKa (§5 "A QuBeKa" — Kuestion "solo consume y muestra"). Tampoco detección de huecos (§1.5), perfilado, cierre de preguntas ni descarte de sugerencias (§2).

### Decisiones de producto confirmadas (review del plan, 2026-09-19)

Las cinco preguntas abiertas quedaron respondidas — ya no son preguntas:

| # | Decisión |
|---|---|
| **P1** | **Sobre real confirmado**: leer `data.suggestions` de `{"success": true, "data": {...}}`. Es el patrón existente de la API — no requiere confirmación de QuBeKa (se cierra en la ronda, no bloquea nada). |
| **P2** | **Naming adoptado**: `texto` / `fuente` / `nodo_origen_id` tal cual; valor `pregunta_abierta` (reemplaza a `pregunta_sin_cerrar` del spec original). El mapeo de `fuente` debe ser **tolerante**: un valor nuevo o desconocido no rompe el cliente — la sugerencia se muestra sin clasificar. |
| **P3** | **Fallback genérico confirmado** (resolución de Q5 ya cerrada con QuBeKa). El catálogo se trata como ejercicio de copy/producto, no como config — ver A.3 expandido. |
| **P4** | **Repo QBK activo** del usuario. Con varios repos QBK: el marcado **preferido** por el usuario; si no hay, el **primero creado**. **Sin selector de repo para sugerencias en v1**. Solo Kuaforia conectado → catálogo genérico local. |
| **P5** | **Cache 10 min confirmado** por usuario y sesión de navegador; se invalida al enviar pregunta; la clave deja de usarse al cerrar sesión. |

### Decisiones adicionales del review (2026-09-19)

| # | Decisión |
|---|---|
| **D1** | **Catálogo genérico = primer copy del producto** (subtratado en la v1 del plan): subfase dedicada en A.3 con tres decisiones — cuántas se muestran, ¿rotan o son fijas?, universalidad (válidas en primera sesión y con workspace activo). Propuesta de ingeniería en A.3; el copy se valida con producto antes de cerrar la Fase C (gate C.6). |
| **D2** | **`nodo_origen_id: null`** → la sugerencia **se muestra igual**, sin nodo de origen; **no se filtra** (descartarla perdería sugerencias válidas solo por falta de trazabilidad). Se documenta en el contrato como campo **opcional/nullable** — cerrar antes de la ronda A.1. |
| **D3** | **Limitación de rotación** (ranking por recencia congela sugerencias si el usuario deja de aportar): es de QuBeKa, no nuestra. Mitigación visual mínima incluida (esfuerzo razonable según la recomendación del review): **rotación determinista del orden entre cargas** (C.5) — no cambia qué sugerencias aparecen. El badge "nueva" queda **fuera de alcance v1** (requeriría estado de sugerencias vistas por usuario). La limitación se declara en el cierre. |

---

## 2. FASES Y TAREAS

### Fase A — Contrato y cliente QBK

**Objetivo:** consumir `/suggestions` con la robustez exigida (§4), aislado de la UI.

**Tareas:**
- **A.1 — Ronda de contrato con QuBeKa (alcance reducido)**: formalizar en el contrato lo ya decidido — sobre real (P1), naming adoptado con valor `pregunta_abierta` (P2), `nodo_origen_id` opcional/nullable con semántica "se muestra igual, no se filtra" (D2), valores de `fuente` abiertos a extensión futura (el cliente no filtra por desconocidos). Nada de esto bloquea A.2–A.3 (mock con la forma del §3.2 + sobre real).
- **A.2 — `app/Services/QbkSuggestionService.php`** (nuevo): `sugerencias(?array $credential): array`.
  - `GET {QUBKA_API_URL}/suggestions?limit=5` con `Http::timeout(5)->withToken($credential['api_token'])` — mismo patrón que `QbkService::consult()` (Bearer por credencial del repo, no por config).
  - Leer `data.suggestions` (sobre real, P1); mapear a array plano `['texto' => …, 'fuente' => …, 'nodo_origen_id' => …]` (P2). **Tolerancia (P2/D2)**: `nodo_origen_id` nullable → la sugerencia se mantiene (nunca se filtra por null); `fuente` desconocida → se conserva tal cual (se muestra sin clasificar).
  - **Cualquier fallo → `[]` + `Log::warning`**: HTTP fallido, timeout, 401, 5xx, JSON malformado, sobre inesperado. Nunca propaga excepción a la UI (§4: el error se degrada a "sin sugerencias").
  - Sin token de agente en la credencial → `[]` (mismo criterio que `QbkService`).
- **A.3 — Catálogo genérico local (subfase de copy/producto, D1)**: es la única pieza que ve un usuario nuevo (sin grafo, sin documentos, sin preguntas) — se trata como el primer copy del producto, no como config:
  - **Universalidad**: preguntas válidas para cualquier usuario en cualquier momento de su uso — nada que asuma workspace activo ("¿Qué decisiones tomamos este mes?") ni cargas previas ("¿Qué documentos tengo cargados?").
  - **Cantidad y rotación (propuesta de ingeniería a validar con producto en C.6)**: catálogo de **5 preguntas universales, mostradas completas y fijas en v1** (cabe en el rango 3–5 del spec; sin rotación interna porque no hay entradas de más — si el catálogo crece por encima de los slots, la rotación se evalúa entonces).
  - Implementación técnica igual a lo planeado: array en `config/kuestion.php` (clave `sugerencias_genericas`) + `genericas(): array` en el servicio. Las 3 del §1.7 son semilla; se reescriben con criterio de universalidad.
- **A.4 — Tests** (`tests/Feature/QbkSuggestionServiceTest.php`, patrón `QbkServiceTest`): 200 con sugerencias (sobre `{success, data}`), 200 vacío, 401, 500, timeout, JSON inválido → todos devuelven `[]` sin throw; token y query param correctos; sugerencia con `nodo_origen_id: null` y con `fuente` desconocida se mantiene en el mapeo (P2/D2).

**Dependencias:** ninguna externa para A.2–A.4 (mock); A.1 depende de QuBeKa.

**Entregable verificable:** servicio con su suite en verde + ronda de contrato cerrada con QuBeKa (P1–P4 respondidos).

**Validación:** tests A.4; el cliente se ejercita end-to-end en la Fase D contra el servicio real.

---

### Fase B — Cache por sesión y componente

**Objetivo:** el componente `CreateQuestion` carga, cachea, invalida y precarga.

**Tareas:**
- **B.1 — Cache 10 min por usuario y sesión**: clave `sugerencias:{uuid}:{session_id}` en `Cache::` (§4: "por usuario y por sesión de navegador"; logout descarta porque la clave deja de usarse y expira). Invalidación explícita en `save()` exitoso (§1.4).
- **B.2 — `CreateQuestion::cargarSugerencias()`** (público, wire-init):
  - Guard: solo con `status === 'idle'` y solo para el **repo QBK del usuario** (P4 — connector_type `qbk`; con varios: el preferido, si no el primero creado; **sin selector en v1**). Sin repo QBK (solo Kuaforia) → genéricas (A.3).
  - Llama al servicio vía cache; si resulta vacío → genéricas (§3.3).
- **⚠️ Hallazgo del código real:** `CreateQuestion` ya tiene una propiedad pública **`$suggestions`** (relaciones sugeridas de `RelationSuggester`, visible en la misma vista). La nueva propiedad se llama **`$preguntasSugeridas`** — no reutilizar ni colisionar. La sección nueva convive con "Relaciones sugeridas" pero es otra cosa; no tocar `RelationSuggester`.
- **B.3 — `usarSugerencia(string $texto)`**: `questionText = $texto` y nada más — **no ejecuta `save()`** (§1.1: precarga, nunca auto-ejecuta; §2: no auto-ejecución). El `wire:model.live` del textarea ya refleja el valor.
- **B.4 — Vista**: `wire:init="cargarSugerencias"` en el form (`wire:init` verificado en el bundle de Livewire v4.3.3 instalado — no había uso previo en el proyecto: verificación runtime obligatoria en Fase D).
- **B.5 — Tests del componente** (patrón `CreateQuestionTest` existente): carga puebla la propiedad; clic precarga sin crear `Question` en BD; `save()` invalida el cache; sin repo QBK → genéricas; fallo QBK → sin sección y sin error visible; sugerencias con `nodo_origen_id: null` se muestran igual (D2).

**Dependencias:** Fase A completa.

**Entregable verificable:** flujo completo demostrable en local: cargar pantalla → sugerencias visibles → clic → texto en el campo → enviar → pregunta creada y cache invalidado.

**Validación:** tests B.5 + Livewire::test ejercitando **la vista real** (lección del review del Punto 1: no vale llamar métodos directamente salteando la vista).

---

### Fase C — UI y copy

**Objetivo:** la sección exacta del §1.1/§1.6, fiel al plan, sin estados inventados.

**Tareas:**
- **C.1 — Bloque** debajo del textarea y antes de "Relaciones sugeridas": título **"Quizás te interese preguntar"** (copy del §1.1), lista vertical de botones-clickeable (no enlaces: no navegan), tokens de estilo existentes (`bg-page`, `border-border`, `text-text`, hover con los ya usados en el form). Sin íconos nuevos ni colores fuera de paleta (diseño visual exacto "a confirmar por Kuestion" — decidimos consistencia con la vista actual).
- **C.2 — Cero sugerencias / cargando**: la sección **no se renderiza** (ni placeholder vacío ni spinner). El textarea siempre está disponible primero (§4). Prohibido el spinner eterno: si QBK no responde, `cargarSugerencias()` terminó con `[]` y no hay rastro de la sección.
- **C.3 — Nada de estados técnicos en la UI** (lección del feedback del Punto 1): jamás mostrar `fuente`, `nodo_origen_id` ni ids — solo la pregunta en texto claro.
- **C.4 — Tests de render**: `assertSee` del título y de las preguntas; sin sugerencias → sección ausente (`assertDontSee`).
- **C.5 — Mitigación de rotación (D3)**: rotación **determinista** del orden entre cargas (p. ej. por día) — no cambia qué sugerencias aparecen, solo evita que la sección se vuelva invisible tras días con el mismo orden. Esfuerzo trivial, sin estado extra ni llamadas nuevas. El badge "nueva" queda fuera de alcance v1.
- **C.6 — Gate de copy (D1)**: el catálogo genérico redactado en A.3 se valida con producto antes de cerrar esta fase (es la primera impresión del usuario nuevo).

**Dependencias:** Fase B completa.

**Entregable verificable:** sección visible y usable en la vista real, con y sin sugerencias, capturada en screenshots de la verificación D.

**Validación:** tests C.4 + verificación visual D.2 + gate C.6.

---

### Fase D — Verificación obligatoria (§3) y cierre

**Objetivo:** cerrar la fase según los ítems obligatorios, sin declarar nada no probado.

**Tareas:**
- **D.1 — Rebuild y assets**: `npm run build`; verificar que toda clase nueva de la sección aparece en el CSS **compilado** (`public/build/assets/app-*.css`), no solo en el fuente.
- **D.2 — Verificación visual en navegador real** (Chromium headless vía Playwright, como en el Punto 1.1, script reproducible en `scripts/`): login → `/questions/create` → sección visible con texto legible y contraste correcto (devtools/estilos computados) → clic en una sugerencia → el texto queda en el textarea (visible) → editar → enviar → flujo de guardado normal intacto (regresión de la pantalla). Con QBK caído: la pantalla carga normal sin sección y **sin congelarse**.
- **D.3 — E2E contra QuBeKa real** (`:8000`, token real del repo QBK — mismo procedimiento de A.2 del Punto 1.1):
  - Workspace con grafo → sugerencias reales del propio grafo; clic → precarga → guardar → `Question` creada.
  - Workspace sin grafo → `{"suggestions": []}` (sobre real) → catálogo genérico local visible.
  - **Si QuBeKa aún no desplegó el endpoint al llegar acá: se declara pendiente explícitamente** (qué se probó con mock, qué queda por validar real) y NO se cierra la fase — misma práctica del Punto 1.1 (esperar señal, no simular).
- **D.4 — Suite completa + Pint**: `vendor/bin/pint --dirty` + suite completa en verde (sin tocar nada fuera del punto).
- **D.5 — Documentos de cierre y resumen** (`docs/ola3-punto3-cierre-implementacion.md`, `docs/ola3-punto3-resumen-implementacion.md`) + commit. Incluye: declarar la **limitación de rotación del ranking (D3)** como conocida (con la mitigación aplicada), y actualizar el **mapa de flujos** (`entrada.sugerencias` 🔧 → ✅) para que refleje la realidad. Aviso de encabezados duplicados: verificarlos programáticamente antes del commit (falla recurrente documentada en Puntos 2–4 de Ola 2).

**Dependencias:** Fases A–C completas; QuBeKa desplegado para D.3.

**Entregable verificable:** checklist D.1–D.4 completo con evidencia (screenshots, extractos reales, suite en verde); documentos publicados.

---

## 3. PRUEBAS FUNCIONALES Y DE INTEGRACIÓN

### Checklist funcional (por fase)

**Fase A (servicio):**
- FA-1: `sugerencias()` con fake 200 y sobre `{success, data:{suggestions:[...]}}` → mapea texto/fuente/id.
- FA-2: 200 vacío → `[]` (sin caer a error).
- FA-3: 401 / 500 / timeout / JSON roto → `[]` + warning en log, **cero excepciones**.

**Fase B (flujo real del componente, sobre la vista):**
- FB-1: cargar pantalla → `cargarSugerencias()` corre por `wire:init` → propiedad poblada ( Livewire::test tocando la vista tal cual queda).
- FB-2: clic en sugerencia → `questionText` toma el texto, **no se crea `Question`** (precarga, no ejecución).
- FB-3: `save()` exitoso → cache invalidado; próxima carga recalcula (§1.4).
- FB-4: segunda carga dentro de los 10 min → no hay segunda llamada HTTP (cache hit).
- FB-5: QBK caído → componente queda en estado idle normal, sección ausente, botón "Consultar y guardar" operativo.

**Fase D (navegador real + servicio real):**
- FD-1: login real → `/questions/create` → sección con 3–5 preguntas legibles, contraste y hover correctos (devtools/estilos computados).
- FD-2: clic → el textarea muestra la pregunta → editar funciona → enviar → pantalla "Pregunta guardada" normal (regresión de la pantalla completa).
- FD-3: contra QuBeKa real: sugerencias provenientes del grafo del workspace (FD-3a) y catálogo genérico en workspace vacío (FD-3b).
- FD-4: QuBeKa detenido → pantalla sin sección, sin spinner colgado, sin error (degradación silenciosa del §4).

### Prueba contra el servicio real — dónde y cuándo

| Fase | ¿Real o mock? |
|---|---|
| A (cliente) | **Mock** (forma del §3.2 + sobre real). El real entra en D.3. |
| B (cache/flujo) | **Mock** + Livewire::test sobre la vista real. |
| C (UI) | Render tests + **visual real** en D.2 (no requiere QBK para renderizarse, sí para datos reales). |
| D | **Servicio real obligatorio** para cerrar (D.3). Si QuBeKa no está disponible: pendiente declarado con motivo; la fase no se cierra. |

### Ítems obligatorios §3 — cómo se cumplen

- **Rebuild de assets + CSS compilado**: D.1 (verificación contra `public/build`, lección del grep con escapes del Punto 1.1).
- **Verificación visual en navegador real**: D.2 con Chromium + devtools, script reproducible con ancla explícita (lección del incidente del Punto 1.1: nada de clics por posición).
- **Compatibilidad con versiones instaladas**: `wire:init` verificado en los bundles de Livewire **v4.3.3 instalado** (no hay uso previo en el proyecto → verificación runtime en D.2 es obligatoria). `Http::timeout`, `Cache::`, `session()->getId()` — APIs estándar ya usadas en el proyecto (Laravel 11.54). Sin métodos nuevos de Livewire (lección `redirectExternal`).
- **Fallo visible y claro en runtime — decisión específica de este punto**: el spec **pide fallo silencioso** ("la ausencia de sugerencias no es un fallo, es un estado válido", §4). Esta regla general cede ante la decisión explícita del spec: sin error visible. Lo que sí es inaceptable y se verifica: pantalla congelada, spinner eterno o estado de carga que no termina — eso se reporta como bug, no como workaround.
- **Prueba contra el servicio real**: D.3 (tabla arriba).

---

## 4. DUDAS Y BLOQUEOS

**Bloqueantes:**
- Ninguno para arrancar. El único bloqueo condicional: **D.3 (E2E real) requiere que QuBeKa despliegue `GET /suggestions`** — si al llegar no existe, la fase D queda abierta con pendiente declarado (no se cierra con mock).

**No bloqueantes (pendientes de confirmación antes de cerrar la fase correspondiente):**
- **Copy del catálogo genérico (D1)**: la propuesta de ingeniería (5 universales, fijas en v1) se valida con producto antes de cerrar la Fase C — gate C.6.
- **Formalización en contrato (A.1)**: todos los puntos ya están decididos (P1–P5, D2); la ronda con QuBeKa los congela, no los abre.

**Resueltos por producto (2026-09-19):** P1–P5 y D1–D3 — ver sección 1.

**Hallazgo de nuestro propio sistema (declarado, no resuelto en silencio):** la colisión de nombres con `$suggestions` (relaciones sugeridas) en `CreateQuestion` — resuelto con propiedad aparte `$preguntasSugeridas` y secciones separadas; documentado en B.2 para que no se "simplifique" después.

---

## 5. ESFUERZO ESTIMADO

| Fase | Esfuerzo | Nota |
|---|---|---|
| A — Contrato y cliente | ~0,5 día | Mock inmediato; ronda de contrato con QuBeKa en paralelo (alcance reducido: todo ya decidido). |
| B — Cache y componente | ~1 día | La parte más delicada: coexistencia con `RelationSuggester` y cache por sesión. |
| C — UI, copy del catálogo y mitigación | ~1 día | Sección simple, pero incluye el ejercicio de copy del catálogo (D1) y la rotación (D3). |
| D — Verificación y cierre | ~1–1,5 días | Margen ampliado según review (Chromium + E2E real + lecciones de los puntos anteriores). Depende de que QuBeKa tenga el endpoint desplegado. |

**Mayor incertidumbre: D.3 (integración real)** — no por riesgo técnico nuestro (el cliente es trivial), sino por dependencia del calendario de QuBeKa; el sobre y el naming ya están decididos (P1/P2), así que la incertidumbre contractual se redujo con el review. Secundaria: el comportamiento real de `wire:init` en este Livewire 4 dentro de un form existente, al no haber uso previo — por eso su verificación runtime es obligatoria y temprana.

---

## 6. FUERA DE ALCANCE

Aunque el spec los menciona o insinúa, **NO construimos**:

1. **Generación/mechanismo de diversidad de fuentes** — vive en QuBeKa (§5; "No construir la lógica de generación" es explícito para Kuestion).
2. **Detección de huecos de conocimiento** (§1.5 — decidido fuera de alcance, requiere capacidad nueva de QBK).
3. **Perfilado / datos cross-usuario** (§1.5, §2).
4. **Cierre automático o manual de preguntas** (§1.2, decisión cerrada; #6 de decisiones abiertas → ola posterior).
5. **"Descartar sugerencia" / historial de descartadas** (§1.6, §2).
6. **Refresco en tiempo real, WebSockets o push** (§1.4, §2).
7. **Sugerencias en el feed u otras pantallas** (decisión abierta #5 del spec: propuesta "solo pantalla de entrada"; construimos solo ahí).
8. **Persistencia de sugerencias** (§4: se calculan al vuelo, cache temporal, sin historial).
9. **Sugerencias desde contenido de Kuaforia u otros repos no-QBK** (§2; P4 define el supuesto: solo repo QBK, genéricas si no hay).
10. **Sugerencias basadas en documentos no ingeridos o temas trending de la plataforma** (§2).

---

*Plan de implementación — Ola 3, Punto 3. El contrato queda condicionado a la ronda A.1 con QuBeKa; ninguna fase se declara cerrada sin los ítems obligatorios de la sección 3.*
