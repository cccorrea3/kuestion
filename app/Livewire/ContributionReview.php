<?php

namespace App\Livewire;

use App\Exceptions\KuaforiaException;
use App\Models\ContributionDraft;
use App\Services\QbkContributionService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Componente de revisión humana para aportes de conocimiento (Ola 1, Punto 4 — Fase 2).
 *
 * Cuando un usuario hace un aporte y la sesión es "simple" (≤2 nodos,
 * confianza ≥0.5, sin conflictos), este componente muestra el detalle
 * y permite aprobar / ajustar / descartar sin salir de Kuestion.
 *
 * Si la sesión es "compleja", redirige a QuBeKa.
 */
#[Layout('layouts::app')]
class ContributionReview extends Component
{
    public int $sessionId;

    public string $status = 'loading';

    public ?string $error = null;

    public ?string $resumen = null;

    public string $preguntaPrevia = '';

    public bool $isSimple = true;

    public ?string $workspaceNombre = null;

    public ?string $createdAt = null;

    /** @var array<int, array{id: string, tipo: string, texto: string, justificacion: string|null, editedText: string}> */
    public array $nodes = [];

    public bool $editing = false;

    /** @var array<string, string> Mapa de nodo_id => texto ajustado */
    public array $textosAjustados = [];

    public ?string $repositoryId = null;

    public function getRepositoriesProperty()
    {
        return auth()->user()->repositories()
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->get();
    }

    public function mount(int $sessionId): void
    {
        $this->sessionId = $sessionId;
        $this->repositoryId = $this->repositories->first()?->id;

        $this->loadSession();
    }

    private function loadSession(): void
    {
        $repo = $this->repositories->firstWhere('id', $this->repositoryId)
            ?? $this->repositories->first();

        if (! $repo) {
            $this->status = 'error';
            $this->error = 'No hay un repositorio conectado para acceder a esta sesión.';

            return;
        }

        try {
            $service = app(QbkContributionService::class);
            $data = $service->getSession($this->sessionId, $repo->credential);

            $this->isSimple = $data['is_simple'];
            $this->status = 'loaded';
            $this->resumen = $data['resumen'] ?? null;
            $this->preguntaPrevia = $data['pregunta_previa'] ?? '';
            $this->workspaceNombre = $data['workspace_nombre'] ?? null;
            $this->createdAt = $data['created_at'] ?? null;

            // Mapear nodos a formato interno con campo editedText.
            foreach ($data['nodes'] as $node) {
                $this->nodes[] = [
                    'id' => $node['id'] ?? uniqid('node_'),
                    'tipo' => $node['tipo'] ?? '?',
                    'texto' => $node['texto'] ?? '',
                    'justificacion' => $node['relaciones'] ? null : ($node['justificacion'] ?? null),
                    'editedText' => $node['texto'] ?? '',
                ];
            }

            // Si es compleja, redirigir a QuBeKa.
            if (! $this->isSimple) {
                $qubekaUrl = config('services.qubeka.base_url', 'http://localhost:8000');
                $redirectUrl = rtrim($qubekaUrl, '/').'/analisis/'.$this->sessionId.'/revision';
                $this->redirectExternal($redirectUrl);
            }
        } catch (KuaforiaException $e) {
            $this->status = 'error';
            $this->error = $e->getMessage();
        } catch (\Throwable $e) {
            $this->status = 'error';
            $this->error = 'Error al cargar la sesión de análisis.';
        }
    }

    public function approve(): void
    {
        if ($this->status !== 'loaded' && $this->status !== 'editing') {
            return;
        }

        $repo = $this->repositories->firstWhere('id', $this->repositoryId)
            ?? $this->repositories->first();

        if (! $repo) {
            $this->error = 'No hay un repositorio conectado.';
            $this->status = 'error';

            return;
        }

        $this->status = 'processing';
        $this->error = null;

        // Recopilar textos ajustados si estamos en modo edición.
        $ajustes = null;
        if ($this->editing && $this->textosAjustados !== []) {
            foreach ($this->nodes as $node) {
                if ($node['editedText'] !== $node['texto']) {
                    $ajustes[$node['id']] = $node['editedText'];
                }
            }
            if ($ajustes === []) {
                $ajustes = null;
            }
        }

        try {
            $service = app(QbkContributionService::class);
            $result = $service->approve($this->sessionId, $ajustes, $repo->credential);

            // Actualizar draft pendiente a reviewed.
            ContributionDraft::where('qbk_session_id', $this->sessionId)
                ->where('user_id', current_user_id())
                ->update(['status' => ContributionDraft::STATUS_REVIEWED]);

            $this->status = 'approved';
            $this->resumen = $result['status'] === 'promocionada'
                ? "Tu aporte fue guardado en tu base de conocimiento ({$result['nodos_creados']} nodo(s) creado(s))."
                : 'Tu aporte fue procesado.';
        } catch (KuaforiaException $e) {
            $this->error = $e->getMessage();
            $this->status = 'loaded';
        } catch (\Throwable $e) {
            $this->error = 'Error inesperado al aprobar. Intentá de nuevo.';
            $this->status = 'loaded';
        }
    }

    public function reject(): void
    {
        if ($this->status !== 'loaded' && $this->status !== 'editing') {
            return;
        }

        $repo = $this->repositories->firstWhere('id', $this->repositoryId)
            ?? $this->repositories->first();

        if (! $repo) {
            $this->error = 'No hay un repositorio conectado.';
            $this->status = 'error';

            return;
        }

        $this->status = 'processing';
        $this->error = null;

        try {
            $service = app(QbkContributionService::class);
            $service->reject($this->sessionId, $repo->credential);

            // Actualizar draft pendiente a reviewed.
            ContributionDraft::where('qbk_session_id', $this->sessionId)
                ->where('user_id', current_user_id())
                ->update(['status' => ContributionDraft::STATUS_REVIEWED]);

            $this->status = 'rejected';
        } catch (KuaforiaException $e) {
            $this->error = $e->getMessage();
            $this->status = 'loaded';
        } catch (\Throwable $e) {
            $this->error = 'Error inesperado al descartar. Intentá de nuevo.';
            $this->status = 'loaded';
        }
    }

    public function toggleEdit(): void
    {
        $this->editing = ! $this->editing;
        $this->error = null;

        // Resetear textos editados al entrar en modo edición.
        if ($this->editing) {
            $this->textosAjustados = [];
            foreach ($this->nodes as $index => $node) {
                $this->nodes[$index]['editedText'] = $node['texto'];
            }
        }
    }

    public function updateNodeText(int $index, string $value): void
    {
        if (isset($this->nodes[$index])) {
            $this->nodes[$index]['editedText'] = $value;
        }
    }

    public function tipoLabel(string $tipo): string
    {
        return match ($tipo) {
            'Q' => 'Pregunta',
            'SQ' => 'Sub-pregunta',
            'H' => 'Hipótesis',
            'N-K' => 'Nota de conocimiento',
            default => $tipo,
        };
    }

    public function tipoColor(string $tipo): string
    {
        return match ($tipo) {
            'Q' => 'bg-blue-100 text-blue-800',
            'SQ' => 'bg-indigo-100 text-indigo-800',
            'H' => 'bg-amber-100 text-amber-800',
            'N-K' => 'bg-emerald-100 text-emerald-800',
            default => 'bg-gray-100 text-gray-800',
        };
    }

    public function render()
    {
        return view('livewire.contribution-review');
    }
}
