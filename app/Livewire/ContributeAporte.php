<?php

namespace App\Livewire;

use App\Exceptions\KuaforiaException;
use App\Models\ContributionDraft;
use App\Services\QbkContributionService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Componente para el flujo de "Aportar conocimiento" (Ola 1, Punto 3 — Fase 4).
 *
 * Estados de UI:
 * - idle: formulario listo para recibir texto
 * - analyzing: spinner + "Analizando tu aporte..."
 * - saved: confirmación "Gracias, quedó guardado y pendiente de revisión"
 * - error: mensaje de error + botón reintentar si hay draft
 */
#[Layout('layouts::app')]
class ContributeAporte extends Component
{
    public string $texto = '';

    public ?string $preguntaPrevia = null;

    public string $status = 'idle';

    public ?string $error = null;

    public string $resumen = '';

    public ?string $repositoryId = null;

    public ?int $draftId = null;

    public bool $hasDraft = false;

    public function getRepositoriesProperty()
    {
        return auth()->user()->repositories()
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->get();
    }

    public function mount(): void
    {
        $this->repositoryId = $this->repositories->first()?->id;

        // Si viene de una búsqueda sin resultados, capturar la pregunta previa.
        $prev = request()->query('prev');
        if ($prev && is_string($prev)) {
            $this->preguntaPrevia = mb_strimwidth($prev, 0, 2000);
        }

        // Verificar si hay un borrador pendiente para este usuario.
        $pendingDraft = ContributionDraft::pending()
            ->forUser(current_user_id())
            ->latest()
            ->first();

        if ($pendingDraft) {
            $this->hasDraft = true;
            $this->texto = $pendingDraft->texto;
            $this->preguntaPrevia = $pendingDraft->pregunta_previa;
            $this->draftId = $pendingDraft->id;
        }
    }

    protected function rules(): array
    {
        return [
            'texto' => 'required|string|min:10|max:2000',
        ];
    }

    /**
     * Enviar el aporte al servicio de clasificación de QuBeKa.
     * (Ola 1, Punto 3 — Fase 4: con persistencia de drafts ante fallos)
     */
    public function submit(): void
    {
        $this->validate();

        $this->status = 'analyzing';
        $this->error = null;

        $repo = $this->repositories->firstWhere('id', $this->repositoryId)
            ?? $this->repositories->first();

        if (! $repo) {
            $this->error = 'Conectá una fuente de conocimiento en Configuración para aportar.';
            $this->status = 'error';

            return;
        }

        try {
            $service = app(QbkContributionService::class);
            $result = $service->contribute(
                texto: $this->texto,
                preguntaPrevia: $this->preguntaPrevia,
                credential: $repo->credential,
            );

            // Si había un draft, marcarlo como enviado con session_id para revisión.
            if ($this->draftId) {
                ContributionDraft::where('id', $this->draftId)->update([
                    'status' => ContributionDraft::STATUS_SENT,
                    'qbk_session_id' => $result['session_id'] ?? null,
                ]);
            } else {
                // Crear draft nuevo con session_id para que el badge de pendientes funcione.
                ContributionDraft::create([
                    'user_id' => current_user_id(),
                    'repository_id' => $repo->id,
                    'qbk_session_id' => $result['session_id'] ?? null,
                    'texto' => $this->texto,
                    'pregunta_previa' => $this->preguntaPrevia,
                    'status' => ContributionDraft::STATUS_SENT,
                    'attempts' => 1,
                ]);
            }

            $this->resumen = $result['resumen'];
            $this->status = 'saved';
            $this->texto = '';
            $this->draftId = null;
            $this->hasDraft = false;
        } catch (KuaforiaException $e) {
            $this->upsertDraft($repo->id, $e->getMessage());
            $this->error = $e->getMessage();
            $this->status = 'error';
        } catch (\Throwable $e) {
            $this->upsertDraft($repo->id, $e->getMessage());
            $this->error = 'Error inesperado. Intentá de nuevo.';
            $this->status = 'error';
        }
    }

    /**
     * Reintentar un borrador pendiente.
     */
    public function retryFromDraft(): void
    {
        if (! $this->draftId) {
            return;
        }

        $draft = ContributionDraft::pending()
            ->forUser(current_user_id())
            ->find($this->draftId);

        if (! $draft) {
            $this->hasDraft = false;
            $this->draftId = null;

            return;
        }

        $repo = $this->repositories->firstWhere('id', $draft->repository_id)
            ?? $this->repositories->first();

        if (! $repo) {
            $draft->markFailed('Repositorio no disponible');
            $this->error = 'El repositorio ya no está disponible.';
            $this->status = 'error';

            return;
        }

        $this->status = 'analyzing';
        $this->error = null;

        try {
            $service = app(QbkContributionService::class);
            $result = $service->contribute(
                texto: $draft->texto,
                preguntaPrevia: $draft->pregunta_previa,
                credential: $repo->credential,
            );

            $draft->markSent();

            $this->resumen = $result['resumen'];
            $this->status = 'saved';
            $this->texto = '';
            $this->draftId = null;
            $this->hasDraft = false;
        } catch (KuaforiaException $e) {
            $draft->markFailed($e->getMessage());
            $this->error = $e->getMessage();
            $this->status = 'error';
        } catch (\Throwable $e) {
            $draft->markFailed($e->getMessage());
            $this->error = 'Error inesperado. Intentá de nuevo.';
            $this->status = 'error';
        }
    }

    public function resetForm(): void
    {
        $this->status = 'idle';
        $this->error = null;
        $this->resumen = '';
    }

    private function upsertDraft(?string $repositoryId, string $lastError): void
    {
        if ($this->draftId) {
            // Reutilizar draft existente: incrementar intento y actualizar error.
            $draft = ContributionDraft::find($this->draftId);
            if ($draft) {
                $draft->markFailed($lastError);

                return;
            }
        }

        // Crear draft nuevo.
        $draft = ContributionDraft::create([
            'user_id' => current_user_id(),
            'repository_id' => $repositoryId,
            'texto' => $this->texto,
            'pregunta_previa' => $this->preguntaPrevia,
            'status' => ContributionDraft::STATUS_PENDING,
            'attempts' => 1,
            'last_error' => $lastError,
        ]);

        $this->draftId = $draft->id;
        $this->hasDraft = true;
    }

    public function render()
    {
        return view('livewire.contribute-aporte');
    }
}
