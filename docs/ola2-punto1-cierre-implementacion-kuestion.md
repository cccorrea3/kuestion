# OLA 2, Punto 1 — Bandeja de Revisión (Kuestion) — Cierre

Estado: **implementado con mocks** · Validación real pendiente (ver §4).

## 1. Qué se construyó

La sección "Revisar" en Kuestion: contador en la navegación, bandeja de aportes
pendientes, historial, y acciones Aprobar / Rechazar / Ajustar por ítem. Fuente
de verdad: listado de QuBeKa (`GET /sesiones-analisis`, contrato §4.1), no la
tabla local `contribution_drafts`.

### Fase A — Servicio de listado (`QbkContributionService::listSessions`)
- Parseo de la shape **real** de QuBeKa: `data` = arreglo plano + `meta.total`
  (helper `ApiResponse::paginated`), y de la shape de fakes/tests (`data.items`).
- Normalización a formato interno (contrato §4.1 con fallback §7.3/§13):
  `fecha_creacion`, `texto_original_del_aporte`, `resumen_clasificacion`,
  `is_simple`, `pregunta_previa`, `autor_email`, `autor_nombre`, `fecha_decision`.
- Paginación (página + per_page, tope 100) y errores 401/403/5xx/timeout legibles.
- Tests: shape real plana, shape `data.items`, legacy naming, vacíos, errores
  (FA.1–FA.5).

### Fase B — Navegación, contador y lista
- Ruta `GET /reviews` → componente Livewire full-page (antes devolvía
  `Livewire::test()` desde un controller, lo que rompía la página y 7 tests).
- Componente `ReviewTrayLink` + contador de pendientes en el header, con
  `wire:poll` (120s) y `wire:navigate` (se quitó el hack Alpine que hacía
  `prevent()` sobre el clic e impedía navegar al tocar el badge).
- Lista con `wire:poll` (60s), estados en **lenguaje natural** (`estadoLegible`:
  Pendiente / Aprobado / Rechazado / Error — sin etiquetas técnicas `qbk`),
  texto original, resumen, autor (cuando viene) y fecha.
- Estados de carga, error legible y vacío claro (sin "cargando..." infinito).
- 401 del listado → repositorio marcado `invalid` (patrón `QuestionChecker`).

### Fase C — Aprobar / Rechazar / Ajustar
- Acciones con firma `int $sessionId` (antes recibían el item completo; se evita
  manipular el payload desde la vista) y con guard `processingSessionId` que
  bloquea el doble envío (C.1).
- Sincronización del borrador local a `ContributionDraft::STATUS_REVIEWED` por
  `qbk_session_id` + `user_id` (C.2, mismo patrón de `ContributionReview`).
- Botón "Ajustar": sesión simple → editor inline (carga nodos vía `getSession`,
  guarda `textos_ajustados`); sesión compleja → redirección a la Revisión Humana
  de QuBeKa (`{QUBKA_BASE_URL}/analisis/{id}/revision`) con `$this->redirect($url, false)`
  (Livewire 4 no tiene `redirectExternal()`).
- Actualización optimista: quita el ítem de la lista local + re-consulta
  silenciosa `refresh()` (C.4).

### Fase D — Historial
- Pestaña "Historial" siempre visible (antes solo aparecía si `itemsHistory()`
  tenía datos, por lo que nunca se veía desde pendientes, y el botón "Pendientes"
  cambiaba su label según el estado). Se elimina la computada duplicada y se usa
  el mismo listado con `estado=historial`.

### Fase E — Regresión
- Suites reutilizadas verdes: `ContributionReviewTest`, `PendingReviewBadge`,
  `ContributionDraft`, `QbkContributionServiceTest`.
- Rebuild de assets OK (`npm run build`), clases verificadas en el bundle.

## 2. Series de pruebas

| Suite | Resultado |
|---|---|
| Full suite (`php artisan test`) | 413 passed · **1 failed** (pre-existente, §4) |
| ReviewTray (16) + QbkContributionService (66) | 81 passed · 253 assertions |

## 3. Archivos

- `app/Services/QbkContributionService.php` — `listSessions` (parseo dual + normalización).
- `app/Livewire/ReviewTray.php` — componente full-page de la bandeja.
- `app/Livewire/ReviewTrayLink.php` — entrada + contador en el header (nuevo).
- `resources/views/livewire/review-tray.blade.php` — lista/pestañas/editor/estados.
- `resources/views/livewire/review-tray-link.blade.php` — enlace con poll.
- `routes/web.php` — `GET /reviews` → `ReviewTray` (sin controller ni POST).
- `resources/views/layouts/app.blade.php` — `<livewire:review-tray-link />`.
- `tests/Feature/ReviewTrayTest.php`, `tests/Feature/QbkContributionServiceTest.php`.
- Eliminado: `app/Http/Controllers/ReviewTrayController.php`.

## 4. Pendientes y hallazgos

- **Validación real (E2E)**: el endpoint de listado de QuBeKa aún no está
  desplegado (Ola 2, Punto 1 de QuBeKa en ejecución). Se probó contra Http fake
  del contrato acordado. Tarea E.2 queda abierta hasta tener el servicio real.
- **Fallo pre-existente no relacionado**: `RepositoryMigrationTest` (backfill de
  preguntas huérfanas) falla por `repository_id` sin valor por defecto en la
  migración de Ola 1, Punto 5/6. No fue introducido por este punto.
- **Hallazgo de contrato §7.3 vs §4.1**: QuBeKa usa naming §4.1 (`contenido_entrada`,
  `creado_en`, `resumen`) y el contrato §7.3 documenta otros nombres (`contenido`,
  `created_at`, `resumen_clasificacion`). El servicio normaliza ambos; la shape
  real observada es §4.1. Documentado, no resuelto unilateralmente.
- **`fecha_decision` y `autor_email`**: el listado real no los incluye (quedan
  `null`); el contrato no los promete en el listado. Reportado.
- **Hallazgo de estado del listado**: en `estado=pendientes`, QuBeKa devuelve
  sesiones en `pendiente_revision` y `lista_para_revision`. Mapeado a "Pendiente".
- **Decisiones abiertas del plan (sección 4)**: D4 (coexistencia del badge
  Ola 1 vedado al cierre), D5 (fuente del historial), D6 (alcance del contador).
  Ninguna fue resuelta unilateralmente: se mantiene la coexistencia actual
  (`review-tray-link` + `pending-review-badge`) a la espera de confirmación.
- **Verificación visual en navegador real (FB, FC, FD)**: cubierta por tests con
  mock; inspección DevTools pendiente del entorno con datos/QuBeKa disponibles.

## 5. Regla de cierre

Fases A, B y C cerradas con mock. Fase D y E no se declaran cerradas hasta que
no se confirme D5 y exista el endpoint real para E2E (§3 regla 5 del plan).