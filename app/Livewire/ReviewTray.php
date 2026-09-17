<?php

namespace App\Livewire;

use App\Exceptions\KuaforiaException;
use App\Models\ContributionDraft;
use App\Models\Question;
use App\Services\Explicacion\ExplicacionNormalizer;
use App\Services\QbkContributionService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Bandeja de revisión rápida (Ola 2, Punto 1 — Fases B/C/D).
 *
 * Lista los aportes pendientes de revisión del workspace (fuente de verdad:
 * listado de QuBeKa vía QbkContributionService::listSessions), con acciones de
 * aprobar / rechazar / ajustar por ítem y pestaña de historial.
 *
 * Fuente de verdad: GET /sesiones-analisis (contrato docs/CONTRATO_API_OLA2.md
 * §4.1). No usa la tabla local contribution_drafts como fuente de la bandeja.
 */
#[Layout('layouts::app')]
class ReviewTray extends Component
{
    #[Locked]
    public int $page = 1;

    #[Locked]
    public int $perPage = 20;

    public string $estado = 'pendientes';

    /** @var array<int, array<string, mixed>> */
    public array $items = [];

    public int $total = 0;

    public string $status = 'loading';

    public ?string $error = null;

    /** C.1 — sesión en proceso de aprobación/rechazo/guardado (bloquea doble envío). */
    public ?int $processingSessionId = null;

    /** C.3 — editor inline de texto (solo sesiones simples). */
    public bool $editing = false;

    public int $editingSessionId = 0;

    /** @var array<int, array{id: string, tipo: string, texto: string, editedText: string}> */
    public array $editingNodes = [];

    /** Ola 2 Punto 4 — D.2: cache de explicaciones cargadas bajo demanda, por session_id. */
    public array $explicaciones = [];

    /** Ola 2 Punto 4 — D.2: sesión cuyo detalle se está consultando. */
    public ?int $detalleCargandoId = null;

    /** Ola 2 Punto 4 — D.2: errores de consulta por session_id (fallo visible + reintento). */
    public array $detalleErrores = [];

    /** Ola 3, Punto 1 — C.3/C.4: documento expandido con selección por nodo. */
    public bool $docExpandido = false;

    public int $docSessionId = 0;

    /** @var array<int, array<string, mixed>> nodos del documento con su selección */
    public array $docNodos = [];

    /** Ola 3, Punto 1 — C.5: contradicciones de la sesión de documento expandida. */
    public ?array $docContradicciones = null;

    /** Ola 3, Punto 1 — C.5: error al cargar contradicciones/detalle (fallo visible). */
    public ?string $docError = null;

    /** Ola 3, Punto 1 — C.1: sesión cuyo documento se está expandiendo (carga bajo demanda). */
    public ?int $docCargandoId = null;

    /** Ola 3, Punto 1.1 — C.1: advertencia de consecuencias pendiente de confirmación (P3). */
    public bool $advertenciaPendiente = false;

    /** Ola 3, Punto 1.1 — C.1: consecuencias devueltas por la evaluación (v1.8 §2.6). */
    public array $advertenciaConsecuencias = [];

    /** Ola 3, Punto 1.1 — C.1: subconjunto que se evaluó (se aprueba exactamente ese). */
    public array $advertenciaAprobados = [];

    /** @var array<int, string> Ola 3, Punto 1.1 — C.1: descartados del subconjunto evaluado. */
    public array $advertenciaRechazados = [];

    /** Ola 3, Punto 1.1 — C.4 (P2, confirmada por producto 2026-09-16): la evaluación falló. */
    public bool $evaluacionFallida = false;

    public function mount(): void
    {
        $this->loadPage();
    }

    public function loadPage(): void
    {
        // D.1 — la pestaña de reconfirmación no consulta QuBeKa (lista local).
        if ($this->estado === 'reconfirmar') {
            $this->status = 'loaded';
            $this->error = null;
            $this->items = [];
            $this->total = 0;

            return;
        }

        $repo = $this->activeRepository();

        if (! $repo) {
            $this->status = 'error';
            $this->error = 'No hay un repositorio conectado para acceder a la bandeja.';
            $this->items = [];
            $this->total = 0;

            return;
        }

        $this->status = 'loading';
        $this->error = null;

        try {
            $service = app(QbkContributionService::class);
            $result = $service->listSessions(
                page: $this->page,
                perPage: $this->perPage,
                estado: $this->estado,
                credential: $repo->credential,
            );

            $this->status = 'loaded';
            $this->items = $result['data']['items'] ?? [];
            $this->total = (int) ($result['data']['total'] ?? count($this->items));
        } catch (KuaforiaException $e) {
            if ($e->getCode() === 401) {
                $repo->update([
                    'status' => 'invalid',
                    'last_validated_at' => now(),
                    'last_used_at' => now(),
                ]);
            }

            $this->status = 'error';
            $this->error = $e->getMessage();
            $this->items = [];
            $this->total = 0;
        } catch (\Throwable $e) {
            $this->status = 'error';
            $this->error = 'No se pudo cargar la bandeja. Intentá de nuevo.';
            $this->items = [];
            $this->total = 0;
        }
    }

    /**
     * Re-consulta silenciosa (wire:poll de B.1 y re-consulta tras acción de C.4).
     * No vuelve a "loading" si ya hay lista cargada.
     */
    public function refresh(): void
    {
        // D.1 — la pestaña de reconfirmación lista preguntas locales: no consulta QuBeKa.
        if ($this->estado === 'reconfirmar') {
            return;
        }

        if ($this->status === 'error') {
            $this->loadPage();

            return;
        }

        $repo = $this->activeRepository();

        if (! $repo) {
            return;
        }

        try {
            $service = app(QbkContributionService::class);
            $result = $service->listSessions(
                page: $this->page,
                perPage: $this->perPage,
                estado: $this->estado,
                credential: $repo->credential,
            );

            $this->items = $result['data']['items'] ?? [];
            $this->total = (int) ($result['data']['total'] ?? count($this->items));
        } catch (\Throwable) {
            // Mantener la lista actual si una re-consulta falla.
        }
    }

    /** @param array<string, mixed> $item */
    public function aprobar(int $sessionId): void
    {
        $repo = $this->activeRepository();

        if (! $repo) {
            $this->error = 'No hay un repositorio conectado.';

            return;
        }

        if ($sessionId <= 0 || $this->processingSessionId !== null) {
            return;
        }

        $this->processingSessionId = $sessionId;
        $this->error = null;

        try {
            $service = app(QbkContributionService::class);
            $result = $service->approve($sessionId, null, $repo->credential, $this->revisorActual());

            // Tratar aprobada/promocionada como éxito (estado transitorio de QuBeKa).
            if (! in_array($result['status'], ['aprobada', 'promocionada'], true)) {
                $this->error = 'No se pudo confirmar la aprobación. Intentá de nuevo.';
            } else {
                $this->draftReviewed($sessionId);
                $this->removeAndRefresh($sessionId);
            }
        } catch (KuaforiaException $e) {
            $this->error = $e->getMessage();
        } catch (\Throwable $e) {
            $this->error = 'Error inesperado al aprobar. Intentá de nuevo.';
        } finally {
            $this->processingSessionId = null;
        }
    }

    public function rechazar(int $sessionId): void
    {
        $repo = $this->activeRepository();

        if (! $repo) {
            $this->error = 'No hay un repositorio conectado.';

            return;
        }

        if ($sessionId <= 0 || $this->processingSessionId !== null) {
            return;
        }

        $this->processingSessionId = $sessionId;
        $this->error = null;

        try {
            $service = app(QbkContributionService::class);
            $service->reject($sessionId, $repo->credential, $this->revisorActual());

            $this->draftReviewed($sessionId);
            $this->removeAndRefresh($sessionId);
        } catch (KuaforiaException $e) {
            $this->error = $e->getMessage();
        } catch (\Throwable $e) {
            $this->error = 'Error inesperado al descartar. Intentá de nuevo.';
        } finally {
            $this->processingSessionId = null;
        }
    }

    /**
     * C.2 — sincronizar el borrador local con STATUS_REVIEWED (mismo patrón de
     * ContributionReview) para no romper el badge/indicadores existentes.
     */
    private function draftReviewed(int $sessionId): void
    {
        ContributionDraft::where('qbk_session_id', $sessionId)
            ->where('user_id', current_user_id())
            ->update(['status' => ContributionDraft::STATUS_REVIEWED]);
    }

    /** C.4 — quitar el ítem de la lista local y re-consultar (optimista + refresco). */
    private function removeAndRefresh(int $sessionId): void
    {
        $this->items = array_values(array_filter(
            $this->items,
            fn (array $item): bool => (int) ($item['session_id'] ?? 0) !== $sessionId,
        ));
        $this->total = max(0, $this->total - 1);

        $this->refresh();
    }

    /**
     * C.3 — Ajustar: si la sesión es simple, abre el editor inline con los nodos
     * del detalle; si es compleja, redirige a la Revisión Humana de QuBeKa.
     */
    public function edit(int $sessionId): void
    {
        $repo = $this->activeRepository();

        if (! $repo) {
            $this->error = 'No hay un repositorio conectado.';

            return;
        }

        $item = collect($this->items)->firstWhere('session_id', $sessionId);

        if (! $item) {
            return;
        }

        if (! (bool) ($item['is_simple'] ?? false)) {
            $url = rtrim((string) config('services.qubeka.base_url', 'http://localhost:8000'), '/')
                .'/analisis/'.$sessionId.'/revision';

            $this->redirect($url, false);

            return;
        }

        $this->error = null;

        try {
            $service = app(QbkContributionService::class);
            $data = $service->getSession($sessionId, $repo->credential);

            $nodes = [];
            foreach ($data['nodes'] as $node) {
                $texto = $node['texto'] ?? '';
                $nodes[] = [
                    'id' => $node['id'] ?? uniqid('node_'),
                    'tipo' => $node['tipo'] ?? '?',
                    'texto' => $texto,
                    'editedText' => $texto,
                ];
            }

            $this->editing = true;
            $this->editingSessionId = $sessionId;
            $this->editingNodes = $nodes;
        } catch (KuaforiaException $e) {
            $this->error = $e->getMessage();
        } catch (\Throwable $e) {
            $this->error = 'No se pudo cargar el detalle para ajustar. Intentá de nuevo.';
        }
    }

    public function updateNodeText(int $index, string $value): void
    {
        if (isset($this->editingNodes[$index])) {
            $this->editingNodes[$index]['editedText'] = $value;
        }
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->editingSessionId = 0;
        $this->editingNodes = [];
        $this->error = null;
    }

    /** C.3 — aprobar con los textos ajustados (misma lógica de ContributionReview). */
    public function guardarAjustes(): void
    {
        $repo = $this->activeRepository();

        if (! $repo || $this->editingSessionId <= 0 || $this->processingSessionId !== null) {
            return;
        }

        $ajustes = null;
        foreach ($this->editingNodes as $node) {
            if (($node['editedText'] ?? '') !== ($node['texto'] ?? '')) {
                $ajustes[$node['id']] = $node['editedText'];
            }
        }
        if ($ajustes === []) {
            $ajustes = null;
        }

        $sessionId = $this->editingSessionId;
        $this->processingSessionId = $sessionId;
        $this->error = null;

        try {
            $service = app(QbkContributionService::class);
            $result = $service->approve($sessionId, $ajustes, $repo->credential, $this->revisorActual());

            if (! in_array($result['status'], ['aprobada', 'promocionada'], true)) {
                $this->error = 'No se pudo confirmar la aprobación. Intentá de nuevo.';

                return;
            }

            $this->draftReviewed($sessionId);
            $this->cancelEdit();
            $this->removeAndRefresh($sessionId);
        } catch (KuaforiaException $e) {
            $this->error = $e->getMessage();
        } catch (\Throwable $e) {
            $this->error = 'Error inesperado al guardar los ajustes. Intentá de nuevo.';
        } finally {
            $this->processingSessionId = null;
        }
    }

    /** Navega a la vista de revisión individual (Ola 1) reutilizada como destino. */
    public function toggleReview(int $sessionId): void
    {
        if ($sessionId <= 0) {
            return;
        }

        $this->redirectRoute('contributions.review', ['sessionId' => $sessionId], true);
    }

    /**
     * Ola 3, Punto 1 — C.1/C.2/C.5: expandir un documento en la bandeja.
     * Carga el detalle (nodos agrupables + contradicciones si vienen, §3.3).
     * C.6 del checklist: fallo visible con reintento — nunca colgado.
     */
    public function expandirDocumento(int $sessionId): void
    {
        if ($sessionId <= 0 || $this->docCargandoId !== null) {
            return;
        }

        $repo = $this->activeRepository();

        if (! $repo) {
            $this->docError = 'No hay un repositorio conectado para revisar el documento.';

            return;
        }

        $this->docCargandoId = $sessionId;
        $this->docError = null;
        // H7 (post-review): la advertencia/fallo de evaluación es del documento
        // anterior — limpiarla al expandir otro evita aprobar con ids congelados.
        $this->limpiarAdvertencia();

        try {
            $service = app(QbkContributionService::class);
            $detalle = $service->getSession($sessionId, $repo->credential);

            $this->docNodos = array_map(fn (array $node): array => [
                'id' => $node['id'] ?? uniqid('nodo_'),
                'tipo' => $node['tipo'] ?? '?',
                'texto' => $node['texto'] ?? '',
                'explicacion' => $node['explicacion'] ?? null,
                'seleccionado' => true, // §1.7: todos preseleccionados por defecto
            ], $detalle['nodes'] ?? []);

            $this->docContradicciones = $detalle['contradicciones'];
            $this->docExpandido = true;
            $this->docSessionId = $sessionId;
        } catch (KuaforiaException $e) {
            if ($e->getCode() === 401) {
                $repo->update(['status' => 'invalid', 'last_used_at' => now()]);
            }

            $this->docError = $e->getMessage();
        } catch (\Throwable $e) {
            $this->docError = 'No se pudo cargar el detalle del documento. Intentá de nuevo.';
        } finally {
            $this->docCargandoId = null;
        }
    }

    /** C.3 — cerrar el documento expandido. */
    public function colapsarDocumento(): void
    {
        $this->docExpandido = false;
        $this->docSessionId = 0;
        $this->docNodos = [];
        $this->docContradicciones = null;
        $this->docError = null;
        $this->limpiarAdvertencia();
    }

    /** Ola 3, Punto 1.1 — C.3: limpia el estado de advertencia sin tocar la selección. */
    public function limpiarAdvertencia(): void
    {
        $this->advertenciaPendiente = false;
        $this->advertenciaConsecuencias = [];
        $this->advertenciaAprobados = [];
        $this->advertenciaRechazados = [];
        $this->evaluacionFallida = false;
    }

    /** C.3 — alternar la selección de un nodo. */
    public function alternarNodo(int $index): void
    {
        if (isset($this->docNodos[$index])) {
            $this->docNodos[$index]['seleccionado'] = ! $this->docNodos[$index]['seleccionado'];
        }
    }

    /** C.3 — seleccionar/deseleccionar todos. */
    public function seleccionarTodos(bool $seleccionado = true): void
    {
        foreach ($this->docNodos as $i => $nodo) {
            $this->docNodos[$i]['seleccionado'] = $seleccionado;
        }
    }

    /** C.3 — cantidad de nodos seleccionados (para el botón y la vista). */
    public function getCantidadSeleccionadosProperty(): int
    {
        return count(array_filter($this->docNodos, fn ($n) => $n['seleccionado']));
    }

    /**
     * Ola 3, Punto 1.1 — C.1: "Aprobar seleccionados" evalúa el subconjunto antes
     * de promover (contrato v1.8 §2.6, publicado por QuBeKa el 2026-09-17).
     * Sin consecuencias: promoción directa, igual que antes (regresión del criterio
     * de cierre #4). Con consecuencias: advertencia informativa, sin ejecutar nada.
     */
    public function aprobarSeleccionados(): void
    {
        $repo = $this->activeRepository();

        if (! $repo || $this->docSessionId <= 0 || $this->docNodos === [] || $this->processingSessionId !== null) {
            return;
        }

        $sessionId = $this->docSessionId;
        $aprobados = array_values(array_map(
            fn (array $n) => (string) $n['id'],
            array_filter($this->docNodos, fn (array $n) => $n['seleccionado']),
        ));
        $rechazados = array_values(array_map(
            fn (array $n) => (string) $n['id'],
            array_filter($this->docNodos, fn (array $n) => ! $n['seleccionado']),
        ));

        if ($aprobados === []) {
            $this->docError = 'No hay nodos seleccionados para aprobar. Seleccioná al menos uno.';

            return;
        }

        $this->processingSessionId = $sessionId;
        $this->docError = null;

        try {
            $service = app(QbkContributionService::class);

            // C.1: una sola llamada de evaluación con la MISMA lista que irá a approve.
            try {
                $evaluacion = $service->evaluarSubconjunto($sessionId, $aprobados, $repo->credential);
            } catch (KuaforiaException $e) {
                if ($e->getCode() === 401) {
                    // Patrón de repo invalid ya usado en expandirDocumento/loadPage.
                    $repo->update(['status' => 'invalid', 'last_used_at' => now()]);
                    $this->docError = $e->getMessage();
                } elseif ($e->getCode() === 422) {
                    // B.2: validación (ids ajenos / vacío) — mensaje legible de QuBeKa.
                    $this->docError = $e->getMessage();
                } else {
                    // C.4 (P2): fallo de transporte de la evaluación → no bloquear;
                    // aviso intermedio con aprobar igual / reintentar.
                    $this->evaluacionFallida = true;
                    $this->advertenciaAprobados = $aprobados;
                    $this->advertenciaRechazados = $rechazados;
                }

                return;
            } catch (\Throwable $e) {
                // C.4 (P2): fallo inesperado de la evaluación — mismo trato.
                $this->evaluacionFallida = true;
                $this->advertenciaAprobados = $aprobados;
                $this->advertenciaRechazados = $rechazados;

                return;
            }

            if ((int) $evaluacion['total'] > 0) {
                // C.1: advertencia (informar, no bloquear) — la promoción no se ejecuta.
                $this->advertenciaPendiente = true;
                $this->advertenciaConsecuencias = $evaluacion['consecuencias'];
                $this->advertenciaAprobados = $aprobados;
                $this->advertenciaRechazados = $rechazados;

                return;
            }

            // Sin consecuencias: flujo directo (sin paso intermedio). Los errores del
            // approve son visibles con su mensaje (FC-6), no pasan por el aviso P2.
            try {
                $this->ejecutarAprobacionSubconjunto($sessionId, $aprobados, $rechazados, $service, $repo);
            } catch (KuaforiaException $e) {
                $this->docError = $e->getMessage();
            } catch (\Throwable $e) {
                $this->docError = 'Error inesperado al aprobar los nodos seleccionados. Intentá de nuevo.';
            }
        } finally {
            $this->processingSessionId = null;
        }
    }

    /**
     * Ola 3, Punto 1.1 — C.2: "Confirmar aprobación" (y "aprobar igual" de P2):
     * ejecuta el approve existente con EXACTAMENTE la lista que se evaluó —
     * cero mutaciones entre evaluar y aprobar.
     */
    public function confirmarAprobacionConAdvertencia(): void
    {
        $repo = $this->activeRepository();

        if (! $repo || $this->docSessionId <= 0 || $this->advertenciaAprobados === [] || $this->processingSessionId !== null) {
            return;
        }

        $sessionId = $this->docSessionId;
        $aprobados = $this->advertenciaAprobados;
        $rechazados = $this->advertenciaRechazados;
        $this->processingSessionId = $sessionId;
        $this->docError = null;

        try {
            $service = app(QbkContributionService::class);
            $this->ejecutarAprobacionSubconjunto($sessionId, $aprobados, $rechazados, $service, $repo);
        } catch (KuaforiaException $e) {
            $this->docError = $e->getMessage();
        } catch (\Throwable $e) {
            $this->docError = 'Error inesperado al aprobar los nodos seleccionados. Intentá de nuevo.';
        } finally {
            $this->processingSessionId = null;
        }
    }

    /**
     * Ola 3, Punto 1.1 — C.3: "Volver a la selección" — limpia solo el estado de
     * advertencia; checkboxes y selección quedan intactos (criterio de cierre #5).
     */
    public function volverASeleccion(): void
    {
        $this->limpiarAdvertencia();
    }

    /** Ola 3, Punto 1.1 — C.4/D.3: reintenta la evaluación tras un fallo (P2). */
    public function reintentarEvaluacion(): void
    {
        $this->limpiarAdvertencia();
        $this->aprobarSeleccionados();
    }

    /**
     * Ola 3, Punto 1.1 — C.1/C.2: camino de aprobación del subconjunto compartido
     * por la promoción directa y la confirmación con advertencia.
     */
    private function ejecutarAprobacionSubconjunto(int $sessionId, array $aprobados, array $rechazados, QbkContributionService $service, object $repo): void
    {
        $result = $service->approve($sessionId, null, $repo->credential, $this->revisorActual(), $aprobados, $rechazados);

        if (! in_array($result['status'], ['aprobada', 'promocionada'], true)) {
            $this->docError = 'No se pudo confirmar la aprobación. Intentá de nuevo.';

            return;
        }

        $this->draftReviewed($sessionId);
        $this->colapsarDocumento();
        $this->removeAndRefresh($sessionId);
    }

    /** C.4/FB-FC-5 — "Rechazar todo": descarta la sesión del documento completa. */
    public function rechazarDocumento(): void
    {
        if ($this->docSessionId <= 0 || $this->processingSessionId !== null) {
            return;
        }

        $sessionId = $this->docSessionId;
        $this->processingSessionId = $sessionId;
        $this->docError = null;

        try {
            $service = app(QbkContributionService::class);
            $service->reject($sessionId, $this->activeRepository()?->credential, $this->revisorActual());

            $this->draftReviewed($sessionId);
            $this->colapsarDocumento();
            $this->removeAndRefresh($sessionId);
        } catch (KuaforiaException $e) {
            $this->docError = $e->getMessage();
        } catch (\Throwable $e) {
            $this->docError = 'Error inesperado al descartar el documento. Intentá de nuevo.';
        } finally {
            $this->processingSessionId = null;
        }
    }

    /**
     * Ola 2 Punto 4 — D.2/FD.3: "¿Por qué?" del ítem. Consulta el detalle de la
     * sesión al expandir (los metadatos viven en nodos[] del detalle) y cachea el
     * resultado. C.3/FD: fallo visible con reintento — nunca "cargando…" infinito.
     */
    public function cargarExplicacion(int $sessionId): void
    {
        if ($sessionId <= 0 || $this->detalleCargandoId !== null) {
            return;
        }

        $repo = $this->activeRepository();

        if (! $repo) {
            $this->detalleErrores[$sessionId] = 'No hay un repositorio conectado para consultar la clasificación.';

            return;
        }

        $this->detalleCargandoId = $sessionId;
        $this->detalleErrores[$sessionId] = null;

        try {
            $service = app(QbkContributionService::class);
            $detalle = $service->getSession($sessionId, $repo->credential);

            $this->explicaciones[$sessionId] = $detalle['nodes'][0]['explicacion']
                ?? ExplicacionNormalizer::sinDetalle();
        } catch (KuaforiaException $e) {
            $this->detalleErrores[$sessionId] = $e->getMessage();
        } catch (\Throwable $e) {
            $this->detalleErrores[$sessionId] = 'No se pudo cargar el detalle de la clasificación. Intentá de nuevo.';
        } finally {
            $this->detalleCargandoId = null;
        }
    }

    public function goToPage(int $page): void
    {
        if ($page < 1 || $page > max(1, (int) ceil($this->total / $this->perPage))) {
            return;
        }

        $this->page = $page;
        $this->loadPage();
    }

    public function switchEstado(string $estado): void
    {
        if (! in_array($estado, ['pendientes', 'historial', 'reconfirmar'], true)) {
            return;
        }

        $this->estado = $estado;
        $this->page = 1;
        $this->cancelEdit();
        $this->detalleCargandoId = null;
        $this->colapsarDocumento();

        if ($estado === 'reconfirmar') {
            // D.1 — lista local de vencidos (computed), no consulta QuBeKa.
            $this->status = 'loaded';
            $this->error = null;
            $this->items = [];
            $this->total = 0;

            return;
        }

        $this->loadPage();
    }

    /**
     * Ola 2, Punto 2 — Fase D (D.1): preguntas QBK activas con vigencia vencida
     * (>90 días sin reconfirmar). Ola 2, Punto 3 (D.2/FB.4): incluye también
     * 'sin_dato' (sin reconfirmaciones registradas) y expone el estado y el
     * confirmador variable (contrato §5.3, D-Confirmador) para el indicador.
     *
     * @return array<int, array{id: string, texto: string, dias: int|null, estado: string, confirmador: string|null}>
     */
    public function getVencidasProperty(): array
    {
        $questions = Question::with('repository', 'currentVersion')
            ->where('user_id', current_user_id())
            ->whereHas('repository', fn ($q) => $q->where('connector_type', 'qbk')->where('status', 'active'))
            ->get();

        return $questions
            ->map(fn (Question $q) => ['q' => $q, 'vigencia' => $q->vigenciaQbk()])
            ->filter(fn ($item) => in_array($item['vigencia']['estado'], ['vencida', 'sin_dato'], true))
            ->map(fn ($item) => [
                'id' => $item['q']->id,
                'texto' => $item['q']->question_text,
                'dias' => $item['vigencia']['dias'],
                'estado' => $item['vigencia']['estado'],
                'ultima_confirmacion' => $item['vigencia']['ultima_confirmacion'],
                'confirmador' => $item['q']->confirmadorQbk(),
            ])
            ->values()
            ->all();
    }

    /**
     * Ola 2, Punto 2 — Fase D (D.2): reconfirmar desde la pestaña.
     * El ítem sale de la lista al recomputarse el computed en el re-render.
     */
    public function reconfirmarPregunta(string $questionId): void
    {
        $question = Question::with('repository', 'currentVersion')
            ->where('user_id', current_user_id())
            ->find($questionId);

        // Ola 2 Punto 3 — FB.4: la acción también desde 'sin_dato'.
        if (! $question || ! in_array($question->vigenciaQbk()['estado'], ['vencida', 'sin_dato'], true)) {
            return;
        }

        try {
            $nodeIds = collect($question->currentVersion?->sources ?? [])
                ->filter(fn ($s) => is_array($s) && ! empty($s['node_id']))
                ->map(fn ($s) => $s['node_id'])
                ->unique()
                ->values();

            if ($nodeIds->isEmpty()) {
                $this->error = 'Esta pregunta no tiene fuentes reconfirmables en QuBeKa.';

                return;
            }

            $service = app(QbkContributionService::class);

            foreach ($nodeIds as $nodeId) {
                // Idempotente en QuBeKa (contrato §5.1): reintentos no duplican.
                $service->reconfirmarNodo($nodeId, $question->repository->credential);
            }

            // D.2 — actualización optimista: el ítem sale de la lista al recomputarse.
            $question->aplicarReconfirmacionLocal();
            $this->error = null;
        } catch (KuaforiaException $e) {
            // FA.4 — token revocado: mismo patrón de loadPage/QuestionChecker.
            if ($e->getCode() === 401) {
                $question->repository?->update([
                    'status' => 'invalid',
                    'last_used_at' => now(),
                ]);
            }

            $this->error = $e->getMessage();
        } catch (\Throwable) {
            $this->error = 'No se pudo reconfirmar. Intentá de nuevo.';
        }
    }

    /** Estado del ítem en lenguaje natural (B.2: sin etiquetas técnicas QBK). */
    public static function estadoLegible(string $status): string
    {
        return match ($status) {
            'creada', 'procesando', 'lista_para_revision', 'pendiente_revision' => 'Pendiente',
            'aprobada', 'promocionada' => 'Aprobado',
            'rechazada' => 'Rechazado',
            'error' => 'Error',
            default => 'Pendiente',
        };
    }

    /** Ola 3, Punto 1.1 — D.2: encabezado legible por tipo de consecuencia (v1.8 §2.6). */
    public static function encabezadoConsecuencia(string $tipo): string
    {
        return match ($tipo) {
            'nodo_huerfano' => 'Se promovería como raíz del grafo',
            'enlace_perdido' => 'El enlace no se recreará',
            'sugerencia_no_resuelta' => 'Quedarían como nodos separados',
            default => 'Consecuencia para el grafo',
        };
    }

    private function activeRepository(): ?object
    {
        return auth()->user()?->repositories()
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->first();
    }

    // C.4 — identidad del revisor autenticado para el payload de approve/reject.
    private function revisorActual(): ?array
    {
        $user = auth()->user();

        return $user ? ['email' => $user->email, 'nombre' => $user->name] : null;
    }

    public function render()
    {
        return view('livewire.review-tray');
    }
}
