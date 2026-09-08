<?php

namespace App\Livewire;

use App\Exceptions\KuaforiaException;
use App\Models\Question;
use App\Models\Repository;
use App\Services\QbkContributionService;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts::app')]
class QuestionFeed extends Component
{
    use WithPagination;

    public string $filter = 'all';

    public string $search = '';

    // 13.3 — Filtro por tag (gap pre-existente: el feed ignoraba ?tag=).
    public string $tag = '';

    protected $queryString = ['filter', 'search', 'tag'];

    public function toggleStar(string $id): void
    {
        $question = Question::where('user_id', current_user_id())->findOrFail($id);
        $question->update(['is_starred' => ! $question->is_starred]);
    }

    public function archive(string $id): void
    {
        $question = Question::where('user_id', current_user_id())->findOrFail($id);
        $question->delete();
    }

    /**
     * Ola 2, Punto 2 — Fase C (C.2): reconfirmar desde la card del feed.
     *
     * Misma lógica que QuestionDetail::reconfirmar() (D1: reconfirma los node_id
     * de las fuentes de la versión actual). Mensajes via dispatch de browser
     * porque la card vive dentro de un <a> — sin estado persistente del feed.
     */
    public function reconfirmar(string $id): void
    {
        $question = Question::with('repository', 'currentVersion')
            ->where('user_id', current_user_id())
            ->findOrFail($id);

        if ($question->vigenciaQbk()['estado'] !== 'vencida') {
            return;
        }

        try {
            $nodeIds = collect($question->currentVersion?->sources ?? [])
                ->filter(fn ($s) => is_array($s) && ! empty($s['node_id']))
                ->map(fn ($s) => $s['node_id'])
                ->unique()
                ->values();

            if ($nodeIds->isEmpty()) {
                $this->dispatch('reconfirmar-error', message: 'Esta pregunta no tiene fuentes reconfirmables en QuBeKa.');

                return;
            }

            $service = app(QbkContributionService::class);

            foreach ($nodeIds as $nodeId) {
                // Idempotente en QuBeKa (contrato §5.1): reintentos no duplican.
                $service->reconfirmarNodo($nodeId, $question->repository->credential);
            }

            // C.1 — actualización optimista: la card muestra "Última confirmación: ahora".
            $question->aplicarReconfirmacionLocal();

            $this->dispatch('reconfirmar-ok', questionId: $question->id);
        } catch (KuaforiaException $e) {
            $this->dispatch('reconfirmar-error', message: $e->getMessage());
        } catch (\Throwable $e) {
            $this->dispatch('reconfirmar-error', message: 'No se pudo reconfirmar. Intenta de nuevo.');
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTag(): void
    {
        $this->resetPage();
    }

    // Ola 1 P5/6 — F2: mostrar el tag de fuente solo cuando hay más de un repo activo (§2.3).
    public function getShowSourceProperty(): bool
    {
        return Repository::where('user_id', current_user_id())
            ->where('status', 'active')
            ->count() > 1;
    }

    public function getQuestionsProperty(): LengthAwarePaginator
    {
        // F1 — eager load del repositorio: la card muestra su estado sin N+1.
        // Ola 1 P5/6 — F3 (3.6): currentVersion para leer was_empty_prev sin N+1.
        $query = Question::with('repository', 'currentVersion')->where('user_id', current_user_id());

        if ($this->filter === 'changes') {
            $query->where('has_unreviewed_changes', true);
        } elseif ($this->filter === 'starred') {
            $query->where('is_starred', true);
        }

        if ($this->search) {
            $query->search($this->search);
        }

        if ($this->tag) {
            $query->whereJsonContains('tags', $this->tag);
        }

        return $query->orderBy('created_at', 'desc')->paginate(10);
    }

    public function title(): string
    {
        return 'Preguntas';
    }

    public function render()
    {
        return view('livewire.question-feed', [
            'questions' => $this->questions,
            'hasQuestions' => $this->questions->total() > 0,
            'showSource' => $this->showSource,
        ]);
    }
}
