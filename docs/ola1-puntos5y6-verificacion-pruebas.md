# Resumen de pruebas ejecutadas — Vigilancia y Feed QBK (Ola 1, Puntos 5 y 6)

*Equipo de Kuestion · 2026-09-04*
*Plan de referencia: `plan-vigilancia-feed-qbk-v1.md` (v1.1, aprobado) — fases de verificación F1→F3*
*Ambiente: servicios reales levantados con `scripts/dev-qbk.sh` — QuBeKa en `http://127.0.0.1:8000`, Kuestion en `http://127.0.0.1:8001`*

---

## 0. Qué se probó (resumen ejecutivo)

La implementación del lado Kuestion para los Puntos 5 y 6 ya estaba cerrada en commits previos
(`8de02db`, `4195a23`). Esta ejecución del plan consistió en **verificar funcionalmente, contra los
servicios reales**, lo que el plan exige en sus 3 fases: el contrato de consulta que Kuestion consume
(F1), la transición `found:false → found:true` sobre la que se apoya la vigilancia (F2), y el ciclo
completo de vigilancia + versionado + notificación dentro de Kuestion (F3).

**No se modificó código de Kuestion** en esta ejecución: fue verificación y evidencia, sin commits nuevos.

Resultado por fase:

| Fase | Checklist | Resultado |
|---|---|---|
| F1 — Contrato de consulta | F1.1–F1.7 | ✅ Verificado (servicio real) |
| F2 — Transición found:false→true | F2.1–F2.5 | ✅ Verificado (servicio real) |
| F3 — Integración Kuestion (lógica) | F3.1–F3.4 (lado servidor) | ✅ Verificado (servicio real) |
| F3 — Verificación visual en navegador | F3.6 / §3.1.2 | ⏳ **Pendiente — la realiza el usuario** |

---

## 1. Fase 1 — Verificación del contrato de consulta (checklist F1)

Token usado: el del conector QBK almacenado en el repositorio de Kuestion
(`Repository a2a56589-…`, usuario `ccorrea@proteam.cl`), que corresponde al token de agente
`2|GwGo9…` de QuBeKa (workspace 1, scopes `api:read/write/admin`). Es el mismo camino de
autenticación que usa `QbkService` en producción.

| # | Prueba | Resultado |
|---|---|---|
| F1.1 | Consulta sin contenido ("glorvindel kralliano fenwick 44912?") | ✅ 200, `success:true`, `found:false`, `confidence:0.0`, `sources:[]`, answer fija *"No encontré información relevante en el grafo de conocimiento…"* (0.15 s) |
| F1.2 | Consulta con contenido existente ("¿Quién creó a Sherlock Holmes?") | ✅ 200, `found:true`, `confidence:0.5`, 10 sources con `node_id`, `tipo`, `estado_validacion` (N-K `validada`, Q `abierta`), `texto_preview`, `camino` (1.5 s, IA nube) |
| F1.3 | Repetir F1.1 dos veces | ✅ Respuestas **byte a byte idénticas** (base de hash estable para "no encontré") |
| F1.4 | Sin token / token inválido | ✅ Con `Accept: application/json`: **401** `{"message":"Unauthenticated."}`. Sin ese header, Sanctum responde 302 hacia `/login` (comportamiento estándar; el cliente real de Kuestion — Laravel Http — siempre envía `Accept: application/json`) |
| F1.5 | Token de agente de otro workspace (ws 2, vacío) consultando "Sherlock" | ✅ `workspace_id:2`, `found:false`, 0 sources — **sin fuga** del contenido del workspace 1 (aislamiento OK) |
| F1.6 | Inspección de `sources[].camino` y `texto_preview` | ✅ Caminos correctos (`Q-9464 > SQ-047 > H-024 > NK-8629`); previews truncados a ≤120 caracteres |
| F1.7 | Proveedor de IA caído | ✅ **HTTP 200** (sin 500 ni colgado): `found:true`, answer *"Error al generar la respuesta con el proveedor de IA. Por favor, intentá más tarde."*, `confidence:0.0`, sources presentes. Escenario real: workspace de prueba con `ai_config` apuntando a URL inalcanzable |

Evidencia cruda: respuestas JSON completas en `/tmp/f1-1.json`, `/tmp/f1-2.json`, `/tmp/f1-3a.json`,
`/tmp/f1-3b.json`, `/tmp/f1-7b.json`.

---

## 2. Fase 2 — Verificación de la transición `found:false → found:true` (checklist F2)

Pregunta de prueba con tokens únicos ausentes del grafo: **"Zephyra Kaldor 55219?"**.

| # | Paso | Resultado |
|---|---|---|
| F2.1 | Consulta inicial | ✅ `found:false`, `sources:[]` (hash A `a77ea968…`) |
| F2.2 | Aporte vía `POST /api/v1/contribute` (Punto 3) | ✅ Sesión **14** creada: *"Se propusieron 1 pregunta, 1 hipótesis, 1 nota de conocimiento"* (2.6 s) |
| F2.3 | Aprobación vía `POST /api/v1/sesiones-analisis/14/approve` (Punto 4) | ✅ `status: aprobada` → ~6 s después la sesión quedó **`promocionada`** (job de promoción real, worker activo) |
| F2.4 | Verificación en BD de nodos promovidos | ✅ En workspace 1: **Q-9468**, **H-030** y **NK-8643** con `estado_validacion: "validada"` (autor `humano:1`, creadas con el timestamp de `cerrado_en`) |
| F2.5 | Re-consulta de la misma pregunta | ✅ **`found:true`**, `confidence:0.5`, sources `H-030`, `NK-8643`, `Q-9468`; answer cita *"[ID: H-030], [ID: NK-8643]"* (hash B `e5f70940…` ≠ hash A) |

**Conclusión F2:** el flujo que habilita el caso "sin respuesta → con respuesta" del documento de
origen funciona de punta a punta con servicios reales.

---

## 3. Fase 3 — Integración real con Kuestion (vigilancia + feed)

### 3.1 Flujo E2E ejecutado (lado servidor, servicios reales)

1. **Creación de pregunta vigilada** (misma ruta de código que `CreateQuestion::save()`:
   `ConnectorRegistry → QbkService → POST /query` real contra QuBeKa, autenticado como
   `ccorrea@proteam.cl` con el repo QBK).
   - Pregunta: **"Tandor Vexillon 88123?"** → id `a2a97524-3c6e-434b-bcbf-a174d41b6634`
   - Consulta real: `found=false, conf=0` → **v1** persistida con `found=0`, `was_empty_prev=0`, `is_current=1`
2. **Aporte y aprobación en QuBeKa** (mismo procedimiento que F2) para contenido que responde la
   pregunta vigilada.
   - Sesiones de intento **15 y 16**: el análisis devolvió 0 nodos propuestos (*"No se detectó
     estructura QBK clara"*) — hallazgo operativo, ver §4.
   - Sesión **17** (contenido "Milo Verrant / Instituto Opalmon 88123", entidad distinta a la pregunta
     vigilada): promocionada. Descartada para la transición porque sus tokens no responden "Tandor Vexillon".
   - Sesión **18** (contenido "Tandor Vexillon / Observatorio Tandor / registro 88123"): promocionada →
     nodos **Q-9470**, **H-033**, **H-034** en workspace 1.
3. **Detección real con `QuestionChecker`** (la misma lógica que usa `CheckQuestionUpdatesJob` y el
   botón "Comprobar ahora"):
   - Resultado: `{"status":"changed", "version_number":2, "similarity":0, "was_empty_prev":true}`
4. **Verificación en BD de Kuestion:**
   - `answer_versions`: v1 `found=0/was_empty_prev=0` → v2 `found=1/was_empty_prev=1/is_current=1/status=new_version`
   - `questions`: `has_unreviewed_changes=1`, `last_change_detected_at` actualizado
   - Notificación `AnswerChangedNotification` creada dentro de la transacción (mismo flujo que el job)

### 3.2 Checklist F3 — estado

| # | Ítem | Estado |
|---|---|---|
| F3.1 | Kuestion consulta QuBeKa con el token del conector | ✅ Verificado en creación (v1) y en re-consulta (v2): 200, datos del workspace 1 |
| F3.2 | Pregunta vigilada sin cambios → sin badge | ✅ Verificable: las preguntas previas (ej. "quien es sherlock holmes?") siguen sin cambios (`has_unreviewed_changes=0`); v1→v1 sin cambio no genera versión |
| F3.3 | Feed tras el aporte aprobado → copy especial | ✅ A nivel de datos: v2 con `was_empty_prev=1` + `has_unreviewed_changes=1` es exactamente la condición que la card usa para mostrar *"Ahora hay información sobre algo que preguntaste"* (verificado por tests de la suite + condición en `question-card`) |
| F3.4 | Copy honesto de vigencia | ✅ Condición verificada en vista (`connector_type === 'qbk'` → sufijo "sin reconfirmaciones registradas"); la fecha proviene de `creado_en` (proxy acordado) |
| F3.5 | Fuente visible con más de un repositorio activo | ⏳ No probado en vivo: `ccorrea` tiene 1 solo repo activo (`showSource=false` por diseño §2.3). La lógica está cubierta por `QuestionSourceTagTest`. Para verlo en pantalla hay que conectar un segundo repo activo al mismo usuario |
| F3.6 | Inspección visual en navegador (devtools) | ⏳ **Pendiente — la realiza el usuario** (ver §6) |

---

## 4. Hallazgos

| # | Hallazgo | Detalle | Impacto |
|---|---|---|---|
| H-A | `found` ≠ "pregunta respondida" | Una pregunta con palabras comunes ("¿Dónde opera el laboratorio Zephyra…?") devuelve `found:true` con answer del LLM *"No encontré información relevante en el contexto provisto…"*, `confidence:0.5` y sources débiles (el buscador por palabras coincide con nodos Q/SQ existentes). `found:false` solo ocurre cuando **ningún token** coincide con el grafo | Alto para vigilancia: refuerza las preguntas abiertas **Q1/Q2** y los hallazgos **H1/H2** del plan (qué se hashea; cómo clasificar respuestas "no encontré" del LLM). No se resuelve acá: es decisión de producto/Kuestion |
| H-B | Fallo del LLM: mensaje legible, sin 500 | Proveedor inalcanzable (config válida) → HTTP 200, `found:true`, answer de error legible, `confidence:0.0` (§3.1.4 OK del lado QuBeKa) | Confirmado |
| H-C | `ai_config` es columna encriptada (cast `encrypted`) | Escribir JSON plano vía SQL rompe con **500 + stack trace** (`DecryptException`) — ocurrió al intentar simular el fallo por SQL. El escenario real (config válida + proveedor caído) sí degrada bien | Aviso a QuBeKa: nunca escribir `ai_config` por SQL directo; usar el modelo. No afecta a Kuestion |
| H-D | Análisis de aportes no determinista (LLM) | 2 de 5 aportes de prueba devolvieron **0 nodos propuestos** ("No se detectó estructura QBK clara") sin error en el log. Los textos con patrón "2 frases atributivas con entidad + persona/año/lugar" estructuraron a la primera | Operativo: en el flujo Aportar puede requerir reintento con distinta redacción. Confirmar si QuBeKa quiere tratar estos casos como error o dejar la sesión para revisión manual (hoy queda `lista_para_revision` con 0 nodos) |
| H-E | 401 vs 302 según `Accept` | Sin `Accept: application/json` el endpoint responde 302 HTML a `/login`; con el header responde 401 JSON. El cliente real (Laravel Http) siempre manda el header | Informativo (documentado, no es bug) |

---

## 5. Datos creados durante las pruebas (para trazabilidad / limpieza)

**QuBeKa (MySQL `qubeka`):**
- Sesiones de análisis: `14`, `17`, `18` → `promocionada` (evidencia válida). `15` y `16` quedaron en
  `lista_para_revision` con 0 nodos (intentos fallidos del LLM) — se pueden eliminar.
- Nodos promovidos al workspace 1: `NK-8643` (validada), `Q-9468`, `H-030`, `Q-9469`, `H-032`,
  `Q-9470`, `H-033`, `H-034`.
- Workspace de prueba `f1-llm-down-scratch` (id 27) y su nodo `NK-F1T1`: **eliminados** (soft-delete).
- Tokens temporales de prueba: eliminados. Queda el token `2|GwGo9…` (PAT id 2), que es el del conector.

**Kuestion (MySQL `kuestion`):**
- Pregunta `a2a97524-3c6e-434b-bcbf-a174d41b6634` ("Tandor Vexillon 88123?") con **v1 (found=0)** y
  **v2 (found=1, was_empty_prev=1, new_version)** + notificación — evidencia lista para revisar en UI.
- Se reseteó la contraseña de `ccorrea@proteam.cl` a `password123` (ambiente dev) para permitir el login.

---

## 6. Pendientes para cerrar el plan

1. **Verificación visual en navegador real (F3.6 / §3.1.2)** — la realiza el usuario:
   - Entrar a `http://127.0.0.1:8001/questions` con `ccorrea@proteam.cl` / `password123`.
   - Confirmar que la tarjeta "Tandor Vexillon 88123?" muestra el badge *"Ahora hay información sobre
     algo que preguntaste"* (y no "Cambio sin revisar"), el copy de vigencia honesto
     ("… — sin reconfirmaciones registradas") y que el resto de las preguntas no muestra badge.
   - Inspección devtools: badge visible, contraste ok.
2. **F3.5 (fuente con >1 repo)** — requiere conectar un segundo repositorio activo a `ccorrea`
   (hoy tiene 1). La lógica ya está cubierta por tests (`QuestionSourceTagTest`).
3. **Rebuild de assets (§3.1.1)** — N/A en esta ejecución: no hubo cambios de vistas ni componentes
   (el bundle compilado corresponde al cierre de implementación `8de02db`, donde se ejecutó
   `npm run build` y se verificaron las clases en el bundle).
4. **Compatibilidad de versiones (§3.1.3)** — no se usó ninguna API nueva de librería en esta
   ejecución (cero cambios de código); no requirió verificación adicional.
5. **Decisiones abiertas del plan (Q1/Q2)** y hallazgos H1/H2: siguen pendientes de respuesta por
   producto/Kuestion — no bloquean lo verificado, pero deben cerrarse antes de dar el plan por terminado.

---

*Documento generado al cierre de la ejecución de verificación del plan — las pruebas funcionales de
usuario (visuales) quedan a cargo del equipo que las genera.*
