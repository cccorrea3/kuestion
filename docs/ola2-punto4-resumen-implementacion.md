# Resumen de Implementación — Ola 2, Punto 4: Explicabilidad de cada propuesta automática

*Equipo de Kuestion · Septiembre 2026*
*Plan de origen: `ola2-punto4-plan-implementacion-kuestion.md`*
*Documento de entrada: `OLA_2_Punto_4.md`*
*Contrato: `docs/CONTRATO_API_OLA2.md` §5.3 (respuestas Q4.1–Q4.5 de QuBeKa, 2026-09-09)*
*Commit de referencia: `8c73754` — "feat(ola2-p4): explicabilidad de clasificación — parsing, presentador y bloque ¿Por qué?"*
*Documento técnico de cierre: `ola2-punto4-cierre-implementacion.md`*

---

## 1. Qué se implementó

El plan se ejecutó completo, fase por fase. La premisa central se confirmó contra el código y contra el servicio real: **Kuestion no genera explicaciones — las recibe como metadatos estructurados de QuBeKa y las traduce con plantillas fijas** (sin LLM en runtime, §5 del documento de entrada).

### Fase A — Parsing del contrato de metadatos

| Tarea | Implementación |
|---|---|
| A.1 | Contrato confirmado antes de ejecutar: `explicacion` viaja **por nodo** en detalle + listado + `POST /contribute` (Q4.2), snake_case. Bloqueantes B2/B3 resueltas con las respuestas Q4.5/Q4.4 (ver `RESPUESTAS_OLA2_KUESTION.md`). |
| A.2/A.3 | `app/Services/QbkContributionService.php`: `getSession()` normaliza `explicacion` por nodo vía `normalizeNode()`; `contribute()` mapea `explicacion_nodo_principal` (análisis síncrono). Nuevo `app/Services/Explicacion/ExplicacionNormalizer.php`: estructura tipada (`sin_detalle/decision_type/confidence/reasons/alternatives_considered/detected_patterns`), defaults seguros para metadata parcial, alternativas solo como objetos `{type, reason}`. |
| A.4 | Sesiones creadas antes del despliegue: `ExplicacionNormalizer::sinDetalle()` — la UI siempre recibe la estructura (nunca `null`). **Regla de precedencia Q4.5 testeada**: si `explicacion` no viene, jamás se cae a `confianza`/`justificacion_ia` (FA.4). |

### Fase B — Traducción a lenguaje natural

| Tarea | Implementación |
|---|---|
| B.1 | `app/Services/Explicacion/ExplicacionPresenter.php`: único lugar donde vive el copy. Frase principal por tipo (Q/SQ/H/N-K/N-A) + primera razón como oración aparte (preservando capitalización, porque QuBeKa cita evidencia del texto). |
| B.2 | Semáforo §2.4 con constantes en un solo lugar: `UMBRAL_VERDE = 0.8`, `UMBRAL_AMARILLO = 0.5` (verde >80%, amarillo 50–80%, rojo <50%). `porcentaje()` y `advertencia()` (solo con confianza roja). |
| B.3 | Vocabulario de patrones alineado con `reglas-clasificacion-explicabilidad.md` de QuBeKa (`causa_declarada` → "palabras de causa", etc.): Kuestion solo traduce lo que QuBeKa envía, no re-clasifica. |
| B.4 | Alternativas: plantilla detecta **las dos formas reales** de `reason` (motivo puro según las reglas vs. oración completa "Se descartó porque…" observada en producción) y no duplica el verbo. |

### Fase C — Bloque visual + confirmación inmediata del "Aportar"

| Tarea | Implementación |
|---|---|
| C.1 | `resources/views/components/classification-explanation.blade.php`: "¿Por qué?" colapsado por defecto, semáforo con clases ya existentes en la app, alternativas, señales y advertencia. Colapso **CSS puro** (checkbox + `peer-checked`) — cero APIs de Livewire, eliminando el riesgo de compatibilidad con la v4 instalada. |
| C.2 | `app/Livewire/ContributeAporte.php`: estado `saved` muestra "Ver detalles de la clasificación" bajo el resumen. Duda D2 resuelta según supuesto del plan: si `contribute` no trae la explicación inline, se consulta `getSession()` al expandir (`cargarDetalleClasificacion()`), usando la `session_id` ya guardada. |
| C.3 | Fallo visible al expandir (401/403/404/5xx/timeout): mensaje legible + botón "Reintentar" — nunca "cargando…" infinito. Testeado incluida la recuperación tras reintento exitoso. |

### Fase D — Revisión Ola 1 y bandeja

| Tarea | Implementación |
|---|---|
| D.1 | `ContributionReview`: "¿Por qué?" por nodo propuesto, colapsado por defecto. La metadata llega normalizada del servicio (A.2) y se re-normaliza en el componente para que la estructura tipada sea garantía propia, no del caller. |
| D.2 | **Ejecutada completa** — hallazgo: el plan la marcaba condicional ("la bandeja no existe hoy") pero fue escrito antes de que el Punto 1 aterrizara; `ReviewTray` existe. `cargarExplicacion()` consulta el detalle bajo demanda, cachea por `session_id` (`explicaciones`), y `detalleErrores` por ítem con reintento. Mismo componente `x-classification-explanation` en pendientes e historial — sin duplicación. |

### Fase E — QA, regresión y cierre

- Regresión de suites tocadas: 198 tests en verde (`QbkContributionService*`, `ContributeAporte*`, `ContributionReview*`, `ContributionDraft*`, `ReviewTray*`, `Punto4Functional*`).
- Rebuild de assets con verificación de clases en el CSS **servido** (no solo fuente).
- Validación E2E real contra QuBeKa (sección 3.2) y documento de cierre técnico.

---

## 2. Archivos tocados (commit `8c73754`)

**Modificados (7):**
- `app/Livewire/ContributeAporte.php`
- `app/Livewire/ContributionReview.php`
- `app/Livewire/ReviewTray.php`
- `app/Services/QbkContributionService.php`
- `resources/views/livewire/contribute-aporte.blade.php`
- `resources/views/livewire/contribution-review.blade.php`
- `resources/views/livewire/review-tray.blade.php`

**Nuevos (6):**
- `app/Services/Explicacion/ExplicacionNormalizer.php`
- `app/Services/Explicacion/ExplicacionPresenter.php`
- `resources/views/components/classification-explanation.blade.php`
- `tests/Feature/ExplicabilidadTest.php`
- `tests/Feature/ExplicabilidadIntegrationTest.php`
- `docs/ola2-punto4-cierre-implementacion.md`

---

## 3. Verificación y evidencia

### 3.1 Tests automatizados

| Suite | Tests | Estado |
|---|---|---|
| `ExplicabilidadTest` (FA + FB) | 15 (46 assertions) | ✅ Verde |
| `ExplicabilidadIntegrationTest` (FC + FD) | 12 (39 assertions) | ✅ Verde |
| Regresión de suites tocadas (E.1) | 198 | ✅ Verde |
| **Suite completa** | **494 passed / 1400 assertions** | ✅ 0 fallos |
| Pint | — | ✅ PASS |
| `npm run build` | — | ✅ Compila; semáforo (`bg-emerald-*`, `bg-amber-*`, `bg-red-*` + textos) y `.peer-checked\:block` verificados por grep en `public/build/assets/app-*.css` |

Cobertura destacable: FA.4 fija la precedencia Q4.5 (metadata legacy `confianza: 0.9` + `justificacion_ia` presente y **ignorada**); FC.4 y FD.3 prueban fallo legible **y recuperación** por reintento en ambas superficies; FD.2 prueba que aprobar/descartar sigue intacto con el bloque presente.

### 3.2 Checklist funcional contra el servicio real (E.2)

Entorno: `dev-qbk.sh start` — QuBeKa real (8000) con su extensión de explicabilidad **ya desplegada** (`AnalisisService::conExplicacion` persiste en `datos_especificos.explicacion`; `SesionAnalisisController::show()` la expone en `nodos[]`).

| Caso | Resultado | Evidencia |
|---|---|---|
| Sesión pre-despliegue real (sesión 12: 3 nodos, `explicacion: null`) | ✅ | Detalle real parseado por el servicio de Kuestion → `sin_detalle: true` por nodo. El copy honesto ("Este aporte no tiene detalle de clasificación disponible") reemplaza cualquier explicación inventada. |
| Aporte real con IA (`POST /contribute` → sesión 43, respuesta síncrona en 3.4 s) | ✅ | La IA de QuBeKa produjo metadatos reales: **Q** (confidence 0.9, patrón `pregunta`) e **H** (confidence 0.95, patrones `causa_declarada` + `afirmacion_sin_fuente`, alternativa N-K con motivo). |
| Presentador contra metadata real | ✅ | Frases naturales correctas con la razón citando el texto; semáforo verde (90%/95%); alternativa traducida sin duplicar verbo; señales legibles. |
| Fallos HTTP reales mapeados | ✅ | 401/403/404/5xx/timeout heredan los mensajes legibles del servicio (patrones existentes); verificados también a nivel HTTP en FC.4. |

---

## 4. Hallazgos y decisiones durante la implementación

1. **El plan quedó parcialmente superado por la realidad (D.2):** asumía que la bandeja del Punto 1 "no existe hoy", pero fue escrito antes de ejecutar ese punto. La bandeja ya está en código, por lo que la integración se ejecutó completa (pendientes + historial), no condicional. Lección registrada: los planes de este proyecto deben re-verificarse contra el estado actual del repo al arrancar cada fase.
2. **La extensión de QuBeKa ya estaba desplegada:** su F1 de viabilidad y validación empírica (`docs/viabilidad-explicabilidad.md` de su repo) ya habían corrido. Esto hizo posible el E.2 real en la misma sesión — el plan lo contemplaba como escenario futuro.
3. **Gap menor detectado en QuBeKa:** su `ContributeController` no incluye `explicacion_nodo_principal` en la respuesta (contrato §5.3 dice que detalle, listado y contribute la exponen). Kuestion no depende de ello (la carga bajo demanda usa el detalle, que sí la expone). Sugerido a QuBeKa como fix aditivo (~línea 85 de su controller). Efecto actual: el bloque en la confirmación del aporte requiere el segundo llamado en lugar de aparecer inline.
4. **Dos formas reales de `alternatives_considered[].reason`:** las reglas de QuBeKa documentan motivo puro ("no cita fuente verificable"), pero la IA en producción a veces devuelve oración completa ("Se descartó porque no menciona un documento…"). La primera plantilla duplicaba el verbo — detectado al probar contra metadata real, corregido con detección de forma (test con ambos casos).
5. **Bugs propios detectados y corregidos por los tests antes del cierre** (a estos llegaron los primeros 6 fallos): un `return` original dejado antes de la normalización en `getSession()` (código muerto — la normalización nunca ejecutaba), decisión de estructura (`null` vs. `sinDetalle()`) resuelta a favor de que la UI siempre reciba estructura tipada, y en los tests: doble `Http::fake()` (el segundo reemplaza al primero y la request "se escapaba" al servidor real levantado), patrones sin wildcard para URLs con query string, FK incorrecta en el draft (`user_id` referencia `users.uuid`, no `id`) y una aserción sin valor semántico eliminada.
6. **La BD de tokens de QuBeKa fue reiniciada** (0 `personal_access_tokens` / `agente_tokens`): el token que Kuestion tenía guardado quedó inválido (401). Se creó uno de prueba (`qk:1:e2e-kuestion`, scopes `api:read`+`api:write`, expira 2026-10-09) para ejecutar el E.2. **Acción requerida del usuario**: reconectar el repo QBK en `/settings` de Kuestion con un token vigente.

---

## 5. Pendientes declarados (con motivo)

| Pendiente | Motivo |
|---|---|
| Verificación visual en navegador real con DevTools (contraste del semáforo, hover del tooltip, enlace distinguible) | Requiere navegador humano. El DOM y las clases del bundle ya están verificados por tests + grep del CSS servido. Los servicios quedaron arriba en :8001; el flujo a inspeccionar es: Aportar → "Ver detalles de la clasificación" y Revisar → "¿Por qué?" por ítem. |
| Reconexión del repo QBK en Kuestion | El token almacenado da 401 tras el reinicio de la BD de tokens de QuBeKa (hallazgo 6). |
| Fix aditivo en QuBeKa (`explicacion_nodo_principal` en `POST /contribute`) | Para que el bloque aparezca inline sin segunda llamada. Kuestion ya degrada correctamente sin él (D2). |
| Historial con bloques por nodo | Por diseño del sandbox de QuBeKa, las sesiones cerradas pierden los nodos; el historial mostrará el bloque solo cuando QuBeKa exponga `explicacion_nodo_principal` en el listado de historial. Comportamiento honesto, no bug de Kuestion. |

---

## 6. Estado final del plan

**Fases A a E: implementadas y verificadas** según entregable verificable + tests automatizados + checklists funcionales (mock y contra el servicio real). Los ítems obligatorios de la sección 3 del plan están cumplidos o declarados explícitamente (única excepción: la inspección visual DevTools, que corresponde al usuario). Fuera de alcance respetado sin excepciones (generación de metadatos, resaltado de keywords, i18n, LLM en runtime, `vinculacion_reason` según Q4.4).

Con este punto, quedan por ejecutar de la Ola 2: **Punto 5** (notificaciones por correo — Fase de integración pendiente) y la validación conjunta de extremo a extremo de los Puntos 1–4 con ambos servicios desplegados y el token reconectado.
