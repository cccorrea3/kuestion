# Cierre — Ola 2, Punto 3: Indicador de vigencia visible en la respuesta

*Equipo de Kuestion · Septiembre 2026*
*Plan ejecutado: `docs/ola2-punto3-plan-implementacion-kuestion.md`*
*Contrato: `docs/CONTRATO_API_OLA2.md` (revalidado por QuBeKa, Decisión D-Confirmador 2026-09-08)*

---

## 1. Qué se implementó

Hallazgo estructural de partida: el Punto 2 ya había construido parte del terreno del Punto 3
(cálculo `vigenciaQbk()`, botón Reconfirmar, pestaña en la bandeja). El plan del Punto 3 se
ejecutó sobre esa base, **extendiendo en vez de reescribiendo**:

| Fase | Entregable | Archivos |
|---|---|---|
| **A** | `Question::confirmadorQbk()` — quién hizo la última reconfirmación, **valor variable** (D-Confirmador): nombre real del usuario de QuBeKa o `"Kuestion (conector)"`; usa la confirmación más reciente (mismo criterio D1). Regla Kuaforia documentada (A.2): sin señal por respuesta (bloqueante B2 abierto) → `no_aplica`, no renderiza nada. | `app/Models/Question.php` |
| **B** | Componente `x-vigencia-indicator` (B.1): 4 estados visuales con colores semánticos existentes (verde `Vigente` / ámbar `Pendiente de reconfirmación` / rojo `Posiblemente obsoleto` / mapeado para Kuaforia), mini-modo compacto (D.1). Integrado en el detalle (B.2) justo después de la respuesta y antes de las fuentes (§2.2). `sin_dato` absorbe el copy honesto P5/6. | `resources/views/components/vigencia-indicator.blade.php` (nuevo), `question-detail.blade.php` |
| **B.3** | Tooltip de trazabilidad: fecha exacta + confirmador **renderizado tal cual viene del contrato** (FB.5: probado con nombre real y con el literal del conector). Sin lógica condicionada al string. | componente |
| **B.4/C.1** | Botón Reconfirmar visible en `vencida` **y** `sin_dato` (FB.4); oculto en `confirmada` (D3). `reconfirmar()` en detalle/feed/bandeja acepta ambos estados. | `QuestionDetail.php`, `QuestionFeed.php`, `ReviewTray.php` |
| **D** | Mini-indicador compacto (estado + fecha, sin botón) en la card del feed (D.1). El botón del feed se movió **fuera del `<a>`** — un botón dentro de un enlace rompe la navegación. Indicador completo con botón en la pestaña de la bandeja (D.2), que ahora lista también ítems `sin_dato` y expone confirmador + fecha. | `question-card.blade.php`, `review-tray.blade.php`, `ReviewTray.php` |
| **E** | Regresión completa (471 tests), rebuild de assets con clases verificadas en el bundle, E2E real contra QuBeKa. | — |

## 2. Pruebas ejecutadas

| Checklist | Resultado |
|---|---|
| **FA** (servicio) | `VigenciaIndicatorTest`: confirmador variable (nombre real / conector / más reciente / null / Kuaforia) ✅ 5 tests |
| **FB** (detalle) | Verde con confirmador en tooltip, amarillo con botón, `sin_dato` con copy honesto + acción, Kuaforia sin indicador ✅ 4 tests |
| **FD** (feed + bandeja) | Mini-badge coherente sin botón en el enlace, badge verde sin acción (D3), pestaña con `sin_dato` y confirmador variable ✅ 4 tests |
| **FC** (mock) | Reconfirmación desde `sin_dato` vía bandeja — el ítem sale de la lista ✅ (además de la suite FC completa del Punto 2, intacta) |
| **FC** (real) | `PATCH /nodos/H-033/reconfirmar` contra QuBeKa real → `{success: true, confirmacion_via: "conector"}`, verificado en la BD de QuBeKa (`fecha_ultima_confirmacion` persistida). Caso 404 real con nodo eliminado (Q-9472) → `nodo_no_disponible`, exactamente el mensaje legible que Kuestion mapea ✅ |
| **E.3** | `npm run build` + grep de las 10 clases del indicador en el CSS compilado ✅ |
| **E.1/E.4** | Suite completa **471 passed / 1318 assertions / 0 failed** + Pint limpio ✅ |

## 3. Hallazgos

1. **El plan quedó parcialmente superado por el Punto 2.** Fase C (acción) y la pestaña de la
   bandeja ya existían; el valor real del Punto 3 fue el componente visual unificado, el tooltip
   con confirmador variable, y la extensión de la acción a `sin_dato`. Se ejecutó como
   extensión, no como reescritura (regla 2 del prompt).
2. **Fix estructural en el feed (D.1):** el botón Reconfirmar del Punto 2 vivía dentro del `<a>`
   de la card usando `wire:click.prevent` — patrón frágil que mezcla acción y navegación. Ahora
   el badge (informativo) vive dentro del enlace y el botón (acción) fuera. Cubierto por test.
3. **El tooltip exigía dato completo:** la pestaña de la bandeja pasaba el confirmador sin la
   fecha y el tooltip lo descartaba. El test `bandeja_pestaña_muestra_confirmador_variable` lo
   detectó antes del cierre; corregido pasando `ultima_confirmacion` desde `getVencidasProperty()`.
4. **Cambio de contrato asumido por el plan (B.4):** `sin_dato` ahora muestra el botón
   Reconfirmar. Dos tests del Punto 2 que negaban el botón en ese estado se actualizaron — es el
   cambio que el plan del Punto 3 ordena, no una regresión.
5. **`/query` real no devuelve `sources` para las preguntas de prueba** (el motor de QuBeKa no
   recupera nodos para esos textos). No es un bug de este punto: la extensión aditiva de
   `sources[]` está implementada y validada del lado de QuBeKa, y la degradación `sin_dato` del
   indicador funciona exactamente como está diseñada en ese caso (se observa en vivo).

## 4. Pendiente declarado

- **Verificación visual en navegador real (FB.1–FB.6, FD.1):** los tests Livewire renderizan el
  DOM (textos, botones, tooltip presentes), pero la inspección con DevTools (contraste, hover
  real del tooltip, posición) corresponde a quien levanta los servicios. Servicios arriba:
  `bash scripts/dev-qbk.sh start` → http://localhost:8001 (detalle y feed de una pregunta QBK
  vencida) y http://localhost:8001/revisar → pestaña "Pendientes de reconfirmar".
- **Caso rojo Kuaforia (FB.3):** bloqueado por el hallazgo 2 del plan (B2 — no existe señal de
  vigencia por respuesta de Kuaforia). El estado `posiblemente_obsoleto` está mapeado en el
  componente con su enlace "Revisar en Kuaforia" para cuando cierre B2.

## 5. Conclusión

El Punto 3 queda cerrado en código y pruebas: indicador unificado en las tres superficies
(detalle completo con tooltip y acción, feed compacto, bandeja completa), confirmador tratado
como valor variable del contrato (D-Confirmador), acción extendida a `sin_dato`, E2E real de
reconfirmación verificado contra QuBeKa y suite completa en verde. Pendientes explícitos:
checklist visual en navegador y el caso Kuaforia (dependiente de producto/QuBeKa, fuera del
control de este plan).
