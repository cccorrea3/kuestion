# Cierre — Ola 3, Punto 3: Preguntas sugeridas

*Equipo de Kuestion · Septiembre 2026*
*Plan ejecutado: `docs/ola3-punto3-plan-implementacion-kuestion.md`*
*Spec de entrada: `OLA_3_Punto_3.md`*

---

## 1. Estado del plan

**Todas las fases (A–D) cerradas**, incluidos los ítems obligatorios de la sección 3
(rebuild + CSS compilado, verificación visual en Chromium real, prueba contra QuBeKa
real, suite completa). Pendientes menores declarados en §5.

| Fase | Entregable | Evidencia |
|---|---|---|
| **A** | `QbkSuggestionService` (A.2), catálogo genérico en `config/kuestion.php` (A.3, D1), tests (A.4) | 11/11 en verde |
| **B** | Cache 10 min usuario+sesión (B.1), `cargarSugerencias()`/`usarSugerencia()` (B.2/B.3), `wire:init` (B.4), tests (B.5) | 10/10 + 14 de regresión del componente |
| **C** | Sección "Quizás te interese preguntar" (C.1–C.3), render tests (C.4), rotación determinista por día (C.5, D3), **gate C.6 aprobado por producto (2026-09-19)** | 10/10 |
| **D** | D.1 build/CSS, D.2 Chromium 12/12, D.3 real (FD-3a OK, FD-4 6/6), D.4 suite 602/1730 + Pint, D.5 este cierre | Ver §3–§4 |

Archivos tocados: `app/Services/QbkSuggestionService.php` (nuevo), `app/Livewire/CreateQuestion.php`, `resources/views/livewire/create-question.blade.php`, `config/kuestion.php`, `tests/Feature/QbkSuggestionServiceTest.php` (nuevo), `tests/Feature/PreguntasSugeridasTest.php` (nuevo), `scripts/verify-p3-visual.mjs` y `scripts/verify-p3-fd4.mjs` (reproducibles). Nada fuera del punto.

## 2. Decisiones implementadas

- **P1 sobre real**: se lee `data.suggestions` de `{"success": true, "data": {...}}` — confirmado contra el servicio real (§4).
- **P2 naming tolerante**: `texto`/`fuente`/`nodo_origen_id`; `fuente` desconocida se conserva sin clasificar; `nodo_origen_id: null` **no filtra** (D2). Tests específicos.
- **P4 repo QBK**: preferido/default primero, sin selector en v1; sin repo QBK → catálogo genérico.
- **P5 cache 10 min** por usuario y sesión, invalidado en `save()` exitoso; el fallo de transporte **no se cachea** (la próxima carga reintenta).
- **C.5 (D3)**: rotación determinista del orden por día (`format('z') % n`) al mostrar; el cache guarda el orden canónico de QuBeKa.
- **Degradación silenciosa (§4)**: fallo de QBK → sin sección, pantalla operativa, sin spinner eterno (FD-4).

## 3. Pruebas

- **Unitarias/feature**: 21 tests nuevos (`QbkSuggestionServiceTest` 11, `PreguntasSugeridasTest` 10). **Suite completa: 602 passed / 1730 assertions / 0 fallos.** Pint limpio.
- **Checklist funcional**: FA-1..3 (mock), FB-1..5 (Livewire::test sobre la vista real), FD-1..4 (navegador real + servicio real).

## 4. Verificación real (navegador + QuBeKa)

- **D.1 — assets**: `npm run build` OK; la única clase CSS nueva (`hover:border-primary/40`) verificada en el bundle compilado (`public/build/assets/app-*.css`, patrón escapado `\:`). Los íconos lucide son SVG inline (no CSS).
- **D.2 — Chromium real (12/12)**: login → `/questions/create` → sección con **sugerencias reales del grafo QBK** ("…competencias clave…"), estilos computados (fondo/contraste/clases en DOM), sin estados técnicos en pantalla, clic → precarga en el textarea (nunca ejecuta), edición → guardado completo → "Pregunta guardada" → nueva pantalla recarga sugerencias (§1.4). Screenshots: `/tmp/p3/01..04*.png`; script: `scripts/verify-p3-visual.mjs` (ancla por texto, nunca por posición).
- **D.3 — QuBeKa real**:
  - **FD-3a ✅**: `GET /suggestions` real (HTTP 200, sobre P1, `fuente: "pregunta_abierta"`) renderizado y consumido de punta a punta.
  - **FD-3b (workspace sin grafo) — pendiente declarado**: los 4 tokens activos de QuBeKa apuntan todos al workspace con 219 nodos; no existe workspace vacío con token para ejercitar el `200 vacío → genéricas` contra el servicio real. Cubierto por mock con la forma real (tests A.4/B.5). Queda para validar cuando QuBeKa disponga de un workspace vacío con token.
  - **FD-4 ✅ (6/6)**: QuBeKa detenido → pantalla carga normal, sin sección, sin error visible, sin spinner (`scripts/verify-p3-fd4.mjs`).

## 5. Hallazgos

1. **Hallazgo propio (Fase B)**: el plan B.2/B.5 exige distinguir `200 vacío → genéricas` de `fallo de transporte → sin sección`; el primer cliente colapsaba ambos. Corregido: el servicio devuelve `{ok, sugerencias}` — 200 vacío es éxito de transporte (genéricas), el fallo deja la sección ausente sin cachear.
2. **Campo extra en la respuesta real**: QuBeKa incluye `id` (`sug_SQ-058`) por sugerencia, no previsto en el naming del spec. El mapeo tolerante lo ignora sin romper; anotado para la ronda de contrato A.1.
3. **Proceso de verificación**: el login visual no usó el usuario real (no se muta su contraseña); se creó un usuario QA dedicado con repo QBK a la misma credencial, **eliminado al cerrar** (pregunta, repo y usuario). El guardado del flujo visual solo consulta QBK (no muta el grafo).
4. **Mapa de flujos**: la actualización `entrada.sugerencias 🔧 → ✅` pedida en D.5 no pudo aplicarse — el mapa no existe como archivo en este repo (solo se menciona en el plan). Pendiente de actualizar cuando el mapa exista como documento.
5. **Limitación conocida (D3)**: el ranking de sugerencias de QuBeKa puede congelarse si el usuario deja de aportar contenido (limitación declarada por QuBeKa). Mitigación aplicada: rotación determinista del orden entre cargas (C.5). El badge "nueva" quedó fuera de alcance v1.

## 6. Pendientes

| Ítem | Motivo | Cuándo se cierra |
|---|---|---|
| FD-3b real (workspace vacío → genéricas) | No hay workspace QBK vacío con token en el ambiente | Cuando QuBeKa lo disponga; el camino ya está cubierto por tests |
| Ronda de contrato A.1 | Formalización documental; todo lo decidido ya está implementado y verificado | Texto listo en el resumen; enviar a QuBeKa |
| Contraste real accesible (devtools humana) | La verificación usó estilos computados + heurística de contraste; screenshots disponibles | Revisión visual de producto si se desea (`/tmp/p3/*.png`) |
