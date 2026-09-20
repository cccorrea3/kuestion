# Resumen de implementación — Ola 3, Punto 3: Preguntas sugeridas

*Equipo de Kuestion · Septiembre 2026*
*Plan: `docs/ola3-punto3-plan-implementacion-kuestion.md` · Cierre técnico: `docs/ola3-punto3-cierre-implementacion.md`*

---

## 1. Qué se implementó

El lado consumidor del mecanismo de preguntas sugeridas, completo: la pantalla de entrada
(`/questions/create`) muestra la sección **"Quizás te interese preguntar"** con 3–5 preguntas
que vienen del grafo QBK del usuario; un clic **precarga** la pregunta en el campo (nunca
ejecuta); el catálogo genérico local cubre al usuario nuevo (sin grafo); y si QuBeKa falla o
no responde en 5 s, la pantalla simplemente se muestra sin sección — el flujo del usuario
nunca se rompe.

| Pieza | Dónde |
|---|---|
| Cliente `/suggestions` (timeout 5 s, sobre real, degradación silenciosa) | `app/Services/QbkSuggestionService.php` |
| Catálogo genérico (D1, aprobado por producto — gate C.6) | `config/kuestion.php` → `sugerencias_genericas` |
| Cache 10 min por usuario+sesión, invalidación al enviar, rotación por día (D3) | `app/Livewire/CreateQuestion.php` |
| Sección UI (copy exacto del plan, sin estados técnicos) | `resources/views/livewire/create-question.blade.php` |

## 2. Verificación (ítems obligatorios de la sección 3)

- ✅ **Rebuild de assets + CSS compilado**: build OK; clase nueva verificada en el bundle (`public/build`).
- ✅ **Verificación visual en navegador real**: Chromium headless, **12/12 + 6/6** verificaciones con estilos computados y screenshots (`scripts/verify-p3-visual.mjs`, `scripts/verify-p3-fd4.mjs`, `/tmp/p3/*.png`).
- ✅ **Compatibilidad**: `wire:init` verificado en el bundle de Livewire v4.3.3 instalado y ejercitado en runtime real; `Http::recorded()` verificado en el vendor (Laravel 11.54).
- ✅ **Prueba contra el servicio real**: sugerencias reales del grafo QBK renderizadas y consumidas de punta a punta (clic → precarga → guardado); FD-4 con QuBeKa caído (degradación silenciosa 6/6).
- ✅ **Fallo en runtime**: el spec pide degradación silenciosa — verificado en navegador: sin sección, sin error, sin spinner colgado, flujo operativo.

## 3. Números

- **Suite completa: 602 passed / 1730 assertions / 0 fallos** (21 tests nuevos). Pint limpio.
- 2 verificaciones visuales E2E en Chromium con anclas explícitas (lección del Punto 1.1: nada de clics por posición).

## 4. Hallazgos y pendientes

- **Hallazgo propio resuelto**: distinguir `200 vacío → genéricas` de `fallo → sin sección` (el servicio devuelve `{ok, sugerencias}`; el fallo no se cachea).
- **Campo `id` extra en la respuesta real de QuBeKa** (no estaba en el spec): el mapeo tolerante lo ignora; anotado para la ronda de contrato.
- **Pendiente FD-3b**: no existe workspace QBK vacío con token para probar `200 vacío → genéricas` contra el servicio real (cubierto por mock con la forma real).
- **Mapa de flujos**: no existe como archivo en el repo; la actualización `entrada.sugerencias 🔧 → ✅` queda pendiente de que exista el documento.
- **Limitación D3 declarada** (ranking por recencia de QuBeKa): mitigada con rotación determinista del orden; badge "nueva" fuera de alcance v1.

## 5. Texto para la ronda de contrato (A.1) — listo para enviar a QuBeKa

> **Asunto: Ola 3 Punto 3 — formalización de contrato para /suggestions**
>
> Hola equipo: implementamos el lado consumidor de `/suggestions` y lo validamos contra el
> servicio real. Para congelar el contrato, formalicen estos puntos ya decididos (nada abre
> decisiones nuevas):
>
> 1. **Sobre de respuesta**: `{"success": true, "data": {"suggestions": [...]}}` (patrón real de la API, confirmado en práctica).
> 2. **Campos por sugerencia**: `texto` (string, requerido), `fuente` (string; el conjunto de valores queda **abierto a extensión futura** — nuestro cliente no filtra por valores desconocidos), `nodo_origen_id` (string|null — **opcional/nullable**: una sugerencia con null se muestra igual, nunca se filtra).
> 3. **Campo `id`**: la respuesta real incluye un `id` por sugerencia (`sug_SQ-058`) que no estaba en la propuesta del spec — documentarlo como parte del contrato (hoy lo ignoramos, pero conviene fijar su semántica).
> 4. **Query param**: `limit` (enviamos `limit=5`; confirmar default y máximo).
> 5. **Errores**: cualquier 4xx/5xx o sobre inesperado se degrada del lado Kuestion a "sin sugerencias" (sin error al usuario), así que no necesitamos semántica de error específica — solo confirmar el 401 con token revocado.
>
> Suite: 602 passed / 1730 assertions. Cierre técnico con evidencia: `docs/ola3-punto3-cierre-implementacion.md`.

---

*Resumen de implementación — Ola 3, Punto 3.*
