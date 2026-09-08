# Cierre — Ola 2, Punto 2: Reconfirmación periódica ligera

*Equipo de Kuestion · Septiembre 2026*
*Plan ejecutado: `docs/ola2-punto2-plan-implementacion-kuestion.md`*
*Contrato: `docs/CONTRATO_API_OLA2.md` (revalidado por QuBeKa el 2026-09-08)*

---

## 1. Qué se implementó

| Fase | Entregable | Archivos |
|---|---|---|
| **A** | `reconfirmarNodo(int|string $nodeId, ?array $credential)` — `PATCH /api/v1/nodos/{id}/reconfirmar`, un nodo por llamada, 401/403/404/5xx/timeout con mensajes legibles (patrón existente `KuaforiaException` de approve/reject). UBICACIÓN: `QbkContributionService` (no `QbkService`), junto a las demás integraciones Ola 2 — el plan lo permitía ("o al servicio que se defina"). | `app/Services/QbkContributionService.php` |
| **A.3** | Sin cambios de código: `QbkService::consult()` persiste `sources` crudos (cast `array` en `AnswerVersion`), por lo que `fecha_ultima_confirmacion` y `ultimo_confirmador_nombre` fluyen de punta a punta cuando QuBeKa los envía. Cubierto por tests FA.6/FA.7. | — |
| **B** | `vigenciaQbk()` en el modelo `Question`: calcula estado desde `sources` de la versión actual (D2, sin tabla espejo). Estados: `no_aplica` (no QBK) / `sin_dato` (fallback honesto P5/6) / `confirmada` (dentro del umbral) / `vencida` (≥ umbral). Criterio D1: vigencia = fecha más reciente entre fuentes. Umbral: `config/kuestion.php → reconfirmacion.umbral_dias` (default 90, env `KUESTION_RECONFIRM_UMBRAL_DIAS`). | `app/Models/Question.php`, `config/kuestion.php` |
| **B.3** | Copy ramificado en feed (card) y detalle: `confirmada` → "Última confirmación: {fecha}"; `vencida` → "Sin reconfirmar desde hace X días" + botón; `sin_dato` → copy honesto de Ola 1 P5/6 intacto (regresión verde). | `resources/views/components/question-card.blade.php`, `resources/views/livewire/question-detail.blade.php` |
| **C** | Acción **Reconfirmar** en detalle (`QuestionDetail::reconfirmar()`, reutiliza banner `checkResult` existente) y en feed (`QuestionFeed::reconfirmar($id)` con `wire:click.prevent` dentro del `<a>`, patrón existente de `toggleStar`; feedback vía toast Alpine + eventos `reconfirmar-ok/error`). Ambos reconfirman los `node_id` de las fuentes (D1) con actualización optimista local (`Question::aplicarReconfirmacionLocal()`). Errores legibles: 403 → "No tenés permiso…", 404 → "ya no está disponible…", timeout → mensaje de reconexión; nunca queda en "cargando". Doble clic bloqueado con `wire:loading.attr="disabled"` + `wire:target` por pregunta. | `app/Livewire/QuestionDetail.php`, `app/Livewire/QuestionFeed.php`, `resources/views/livewire/question-feed.blade.php` |
| **D** | Pestaña **"Pendientes de reconfirmar"** en la bandeja (`ReviewTray`): tercera pestaña con lista local de preguntas QBK activas vencidas (computed `vencidas`, sin consultar QuBeKa), botón por ítem (`reconfirmarPregunta`), el ítem sale de la lista al reconfirmar (D.2 vía actualización optimista). | `app/Livewire/ReviewTray.php`, `resources/views/livewire/review-tray.blade.php` |

**Fuera de alcance respetado** (§6 del plan): sin reconfirmación para Kuaforia, sin lote, sin recordatorios, sin job automático, sin cambios al `ChangeDetector`, sin umbral por usuario.

---

## 2. Pruebas ejecutadas

### Tests (mock del contrato)

| Suite | Cobertura | Resultado |
|---|---|---|
| `QbkContributionServiceTest` (+7 tests) | FA.1–FA.6: éxito 200 con parseo de respuesta, 401, 403, 404, 500, timeout, sin token; FA.6/FA.7: `/query` con sources viejos (sin campo) no rompe y con campo nuevo lo preserva | ✅ 61 passed |
| `QuestionVigenciaReconfirmacionTest` (nuevo, 22 tests) | B.2: `no_aplica`/`sin_dato`/`confirmada`/`vencida`/fecha más reciente/sources null. B.3: copy ramificado en feed y detalle (3 estados × 2 superficies). C: reconfirmación exitosa llama PATCH por cada nodo, no-op si no vencida, 403/404/timeout legibles, sin fuentes → error, feed dispatch ok/error, aislamiento por usuario | ✅ 22 passed |
| `ReviewTrayTest` (+4 tests) | D.1: pestaña lista vencidos / vacía cuando no hay. D.2: reconfirmar llama PATCH y el ítem sale de la lista; 403 con mensaje legible | ✅ 20 passed (17 preexistentes + 3 nuevos + 1 ajustado) |
| Regresión P5/6 (`QuestionVigenciaCopyTest`) | El copy honesto convive con la ramificación (fallback `sin_dato` intacto) | ✅ 5 passed |
| **Suite completa** | Regresión total del proyecto | ✅ **451 passed (1266 assertions), 0 failed** |

### Pint

`vendor/bin/pint --dirty` — ✅ sin issues pendientes.

### Assets (E.3, obligatorio)

- `npm run build` ejecutado: `public/build/assets/app-ptjOAk-8.css` (71.93 kB) + `app-BEXQM2p_.js`.
- Clases del indicador/botón verificadas contra el CSS **compilado**: `bg-amber-50`, `text-amber-700`, `bg-amber-100`, `hover:bg-amber-100:hover`, `disabled:opacity-50:disabled` — todas presentes en el bundle.

---

## 3. Validación contra QuBeKa real (E.2)

Con `scripts/dev-qbk.sh start` (Kuestion :8001, QuBeKa :8000) y el token del conector real (`…c90661e`, workspace 1 "QBK Demo", scopes `api:read/write/admin`):

| Checklist | Resultado |
|---|---|
| FC.3 — estado real en QuBeKa | ✅ `PATCH /api/v1/nodos/Q-9441/reconfirmar` → `{"success":true,"data":{"node_id":"Q-9441","fecha_ultima_confirmacion":"2026-09-08T19:15:29+00:00","ultimo_confirmador_id":1}}`; verificación posterior en `GET /workspaces/1/nodos`: el campo **quedó persistido** en el nodo (y `version`/`actualizado_en` intactos) |
| Contrato §5.2 — nodos exponen campos | ✅ Los nodos reales del listado ya incluyen `fecha_ultima_confirmacion: null` (aditivo, listo para consumo) |
| FC.1/FC.2/FC.6 — acción desde UI | ⏳ **Pendiente de tu verificación manual** (ver §6): requiere sesión de navegador con una pregunta QBK vencida; la lógica está cubierta por tests, falta el chequeo visual humano |
| `/query` real con campo en `sources[]` | ⏳ **Pendiente — bloqueado por el ambiente de QuBeKa** (ver hallazgo 1) |

---

## 4. Hallazgos

1. **El contrato que teníamos en `docs/` era el borrador rechazado.** QuBeKa había reemitido `CONTRATO_API_OLA2.md` (2026-09-08, validado contra su código real) en `../QuBeKa/qubeka/`. Fue sincronizado a `docs/CONTRATO_API_OLA2.md` y re-alineado `docs/OLA2_ENDPOINTS_API.md` (v1.1). Diferencias clave vs. el borrador local: sobre de error con `data: null`, naming real del listado (`fecha_creacion`/`texto_original_del_aporte` ya vienen mapeados por QuBeKa; `autor_email` aún no existe — llega con B2), y el estado real de implementación (Puntos 1 y 2 **implementados**; 3–5 `[PLANEADO]`).
2. **`/query` real hoy falla con "The MAC is invalid"** (DecryptException dentro de QuBeKa): el `ai_config` del workspace quedó encriptado con un `APP_KEY` distinto al actual — el mismo problema de MAC que ya apareció antes del lado de QuBeKa. No es un bug de esta implementación; impide validar en vivo la extensión de `sources[]` del Punto 2 (el fallback `sin_dato` se activa correctamente mientras tanto, como define el plan). Fix del lado de QuBeKa: re-guardar la configuración de IA del workspace con su `APP_KEY` vigente.
3. **Test de migración preexistente roto, corregido en el paso:** `RepositoryMigrationTest` tenía `--step=3` desactualizado (nuevas migraciones posteriores a las que simula). Actualizado a `--step=6` con comentario que documenta el conteo y por qué 000001 no se incluye (el rollback la borraría junto con la fila del repo del test). Fallaba también con el árbol limpio — no fue introducido por este punto.
4. **Test `test_list_sessions_throws_on_timeout` no era determinista:** el fake sin `*` no matcheaba la URL con query string; con el servidor real levantado, la petición "se escapaba" a QuBeKa real (401 en vez de timeout). Pasaba antes solo porque no había servidor escuchando. Corregido (mismo patrón que el resto de la suite).
5. **Actualización optimista era necesaria en el modelo, no solo en la vista:** el primer test de D.2 falló porque reconfirmar no refrescaba la vigencia local. Se agregó `Question::aplicarReconfirmacionLocal()` (marca `fecha_ultima_confirmacion = now()` en las fuentes de la versión actual), reutilizada por las tres superficies. QuBeKa sigue siendo la fuente de verdad: la próxima re-consulta refresca `sources` completos.
6. **Corrupción menor en plan y contrato (cosmética):** el plan tenía un doble encabezado `## 1. RESUMEN DE ALCANCE` y una tabla §6 que no matcheaba para edición (se dejó como está por no alterar contenido); el contrato borrador tenía typos ("Contratoo", "Kuision") — todo ello reemplazado por la versión limpia de QuBeKa.

---

## 5. Estado por fase

| Fase | Estado |
|---|---|
| A — Contrato + servicio + parseo | ✅ **Cerrada** (tests FA + contrato real sincronizado) |
| B — Umbral, vigencia, degradación | ✅ **Cerrada** (tests B + regresión P5/6 verde) |
| C — Acción en detalle y feed | ✅ **Cerrada en lógica** (tests FC con mock + PATCH real verificado vía curl). Chequeo visual humano pendiente (§6) |
| D — Pestaña en la bandeja | ✅ **Cerrada** (Punto 1 construido — dependencia resuelta; tests D) |
| E — QA y cierre | ✅ Suite completa 451/0, Pint, assets verificados, E2E real parcial (ver §3) |

---

## 6. Cómo verificarlo tú (checklist FC visual, ~10 min)

1. `./scripts/dev-qbk.sh start` (levanta Kuestion :8001, QuBeKa :8000).
2. En Kuestion (`http://localhost:8001`), entra a una pregunta QBK cuya `fecha_ultima_confirmacion` tenga >90 días (o crea una y ajusta el campo en la BD de QuBeKa).
3. **Detalle:** verifica "Sin reconfirmar desde hace X días" + botón ámbar **Reconfirmar** → clic → banner verde "¡Confirmado! Última confirmación: ahora."
4. **Feed:** verifica el mismo estado en la card → clic en **Reconfirmar** sin abrir la pregunta → toast de confirmación + card actualizada.
5. **Bandeja (`/reviews`):** pestaña **"Pendientes de reconfirmar"** → el vencido aparece → **Reconfirmar** → el ítem sale de la lista.
6. En QuBeKa: el nodo reconfirmado debe mostrar `fecha_ultima_confirmacion` de hoy (`GET /api/v1/workspaces/1/nodos` o la UI de QuBeKa).
7. Repite cualquier paso con el servicio de QuBeKa detenido → debe verse un error legible, nunca "cargando…" infinito.

---

## 7. Pendientes declarados (no silenciosos)

| # | Pendiente | Motivo | Cómo se cierra |
|---|---|---|---|
| 1 | Validación visual humana del flujo (FC.1/FC.2/FC.6 en navegador real) | Requiere sesión de navegador del usuario; toda la lógica está cubierta por tests y el PATCH real ya se verificó vía curl | Checklist de §6 (~10 min) |
| 2 | Validación en vivo de `sources[]` con campo en `/query` real | Ambiente de QuBeKa con MAC inválido en `ai_config` del workspace (hallazgo 2) | QuBeKa re-guarda su configuración de IA; re-consultar una pregunta y verificar el campo en `sources` |
| 3 | `X-User-Email` y validación de revisor (B1 etapa posterior) | Contrato: header reservado, ignorado en MVP | Ola 2, etapa posterior acordada |
