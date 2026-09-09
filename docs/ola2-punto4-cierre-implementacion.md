# Cierre — Ola 2, Punto 4: Explicabilidad de cada propuesta automática

*Equipo de Kuestion · Septiembre 2026*
*Plan ejecutado: `docs/ola2-punto4-plan-implementacion-kuestion.md`*
*Contrato: `docs/CONTRATO_API_OLA2.md` §5.3 · Respuestas Q4.1–Q4.5 de QuBeKa (2026-09-09)*

---

## 1. Qué se implementó

| Fase | Entregable | Archivos |
|---|---|---|
| **A** | Normalización del objeto `explicacion` (contrato §5.3) en `getSession()` — por nodo, con flag `sin_detalle` para sesiones pre-despliegue (A.4). Mapeo de `explicacion_nodo_principal` en `contribute()` (análisis síncrono, Q4.2). Precedencia Q4.5: nunca fallback a `confianza`/`justificacion_ia`. | `app/Services/QbkContributionService.php` (`normalizeNode`), `app/Services/Explicacion/ExplicacionNormalizer.php` |
| **B** | `ExplicacionPresenter`: plantillas fijas por tipo (Q/SQ/H/N-K/N-A), semáforo §2.4 (verde >80%, amarillo 50–80%, rojo <50%), alternativas con motivo, señales legibles con el vocabulario de `reglas-clasificacion-explicabilidad.md` de QuBeKa (B.3), advertencia por confianza roja. | `app/Services/Explicacion/ExplicacionPresenter.php` |
| **C** | Componente `x-classification-explanation` (colapso CSS puro con `peer` — compatible Livewire 4: sin APIs nuevas) + integración en `ContributeAporte` estado `saved`: "Ver detalles de la clasificación" bajo el resumen; carga del detalle bajo demanda (duda D2) con fallo visible y reintentar (C.3). | `resources/views/components/classification-explanation.blade.php`, `app/Livewire/ContributeAporte.php`, `resources/views/livewire/contribute-aporte.blade.php` |
| **D** | D.1: "¿Por qué?" por nodo en `ContributionReview` (colapsado por defecto). D.2: mismo componente en `ReviewTray` (pendientes e historial) — la bandeja **sí existe** (hallazgo: el plan la suponía pendiente; el Punto 1 ya aterrizó), por lo que D.2/FD.3 se ejecutaron completos, no condicionados. | `app/Livewire/ContributionReview.php`, `resources/views/livewire/contribution-review.blade.php`, `app/Livewire/ReviewTray.php`, `resources/views/livewire/review-tray.blade.php` |
| **E** | Suite completa + Pint + rebuild de assets + validación E2E real contra QuBeKa (sección 3). | `tests/Feature/ExplicabilidadTest.php`, `tests/Feature/ExplicabilidadIntegrationTest.php` |

## 2. Pruebas ejecutadas

### Unitarias/integración (mock del contrato)

| Suite | Resultado | Cubre |
|---|---|---|
| `ExplicabilidadTest` | 15 passed (46 assertions) | FA.1–FA.5 (parsing: completa, ausente, parcial, precedencia Q4.5, multi-nodo) + contribute inline + FB.1–FB.5 (plantillas por tipo, razones, semáforo por rango, sin_detalle, vocabulario de patrones) |
| `ExplicabilidadIntegrationTest` | 12 passed (39 assertions) | FC.1–FC.4 (enlace visible, render inline sin segunda llamada, carga bajo demanda, sin_metadata honesto, 500 y timeout con fallo legible + reintento que recupera) + FD.1–FD.3 (toggle por nodo, metadata normalizada vía mock, aprobar intacto, bandeja por demanda con error visible) |
| **Suite completa** | **494 passed / 1400 assertions / 0 fallos** | Regresión total (E.1), incluida la de P5/6 (copy de vigencia) y el punto 2 |

### Checklists funcionales contra servicio real (E.2)

Entorno: `dev-qbk.sh start` · QuBeKa real (8000) con la extensión de explicabilidad desplegada (`AnalisisService::conExplicacion`, `SesionAnalisisController` expone `explicacion` en `nodos[]`).

| Caso | Resultado |
|---|---|
| **Sesión pre-despliegue real** (sesión 12, 3 nodos, `explicacion: null`) | Parsing de Kuestion → `sin_detalle: true` por nodo; el copy honesto reemplaza cualquier explicación inventada ✅ |
| **Aporte real con IA** (`POST /contribute` → sesión 43, respuesta síncrona en 3.4 s) | La IA de QuBeKa produjo metadatos reales: Q (confidence 0.9, patrón `pregunta`) e H (confidence 0.95, patrones `causa_declarada` + `afirmacion_sin_fuente`, alternativa N-K con motivo) ✅ |
| **Presentador contra metadata real** | Frases naturales correctas, semáforo verde (90%/95%), alternativa traducida sin duplicar verbo ✅ |
| **Fallos HTTP reales mapeados** | 401/403/404/5xx/timeout ya cubiertos por el servicio (patrones existentes); verificados también en FC.4 con fake a nivel HTTP ✅ |

### Ítems obligatorios de la sección 3

- **Rebuild + clases en bundle**: `npm run build` OK; verificación con grep del CSS servido (`public/build/assets/app-*.css`): semáforo (`bg-emerald-100/500`, `bg-amber-100/500`, `bg-red-100/500` y textos) y `.peer-checked\:block` presentes. ✅
- **Compatibilidad de versiones**: Livewire `^4.0` real (composer.json). El colapso usa checkbox + CSS (`peer-checked`), cero APIs de Livewire — sin riesgo del tipo `redirectExternal()`. ✅
- **Fallo visible en runtime**: 500/timeout al expandir → mensaje legible + botón "Reintentar" en las dos superficies (C.3, FD.3), nunca "cargando…" infinito. ✅
- **Verificación visual en navegador**: el DOM y las clases del bundle están cubiertos por tests + grep; la inspección DevTools de contraste/hover queda como única verificación pendiente de tu lado (servicios arriba en :8001). ⚠️ Declarado, no simulado.

## 3. Hallazgos

1. **La bandeja del Punto 1 ya existe** — el plan (escrito antes de ejecutar el Punto 1) marcaba D.2 como condicional. Se ejecutó completa: mismo componente, sin duplicación.
2. **La extensión de explicabilidad de QuBeKa ya está desplegada** — la F1 de viabilidad y su validación empírica (`docs/viabilidad-explicabilidad.md`) ya corrieron; E.2 real fue posible en esta misma sesión.
3. **Gap de QuBeKa detectado (menor)**: su `ContributeController` no incluye `explicacion_nodo_principal` en la respuesta (contrato §5.3 dice que el listado, detalle y contribute la exponen). Kuestion no depende de ello: la carga bajo demanda de D2 consulta el detalle, que sí la expone. Sugerido a QuBeKa como fix aditivo de ~línea 85 en su controller.
4. **Dos formas reales de `alternatives_considered[].reason`** (motivo puro según sus reglas vs. oración completa "Se descartó porque…" observada en producción). El presentador detecta la forma y no duplica el verbo — test con ambas.
5. **La BD de tokens de QuBeKa fue reiniciada** (0 `personal_access_tokens` / `agente_tokens`): el token viejo de Kuestion quedó inválido; se creó uno nuevo de prueba (`qk:1:e2e-kuestion`, expira 2026-10-09). **Acción pendiente tuya**: reconectar el repo QBK en `/settings` de Kuestion con un token vigente para que la bandeja/aportar funcionen en tu sesión.

## 4. Fuera de alcance confirmado (no construido)

Generación/almacenamiento de metadatos (QuBeKa) · resaltado de keywords · enlace a documentación QBK · explicación en el feed (D6) · i18n · LLM en runtime · `vinculacion_reason` (Q4.4: la decisión no existe hoy en el pipeline de promoción de QuBeKa).

## 5. Pendientes declarados

| Ítem | Motivo |
|---|---|
| Inspección visual DevTools (contraste del semáforo, hover) | Requiere navegador humano; DOM/clases ya verificados por test + bundle |
| Historial con nodos | Sesiones cerradas pierden nodos en QuBeKa (por diseño del sandbox); el historial muestra el bloque solo si hay `explicacion_nodo_principal` — hoy no lo hay. Comportamiento honesto, no bug de Kuestion |
| Fix de `explicacion_nodo_principal` en contribute (QuBeKa) | Para que el bloque aparezca inline sin segunda llamada (Kuestion ya degrada bien sin él) |
