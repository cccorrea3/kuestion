<?php

namespace App\Livewire;

use App\Contracts\RagProviderInterface;
use App\Exceptions\KuaforiaException;
use App\Models\Question;
use App\Services\RelationSuggester;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts::app')]
class CreateQuestion extends Component
{
    public string $questionText = '';

    public string $tagInput = '';

    // D2 — repositorio activo seleccionado (default: el is_default).
    public ?string $repositoryId = null;

    public array $tags = [];

    public string $reviewFrequency = 'weekly';

    public string $status = 'idle';

    public ?string $error = null;

    public string $answerText = '';

    public array $suggestions = [];

    public array $confirmedRelations = [];

    /**
     * Repositorios activos del usuario (P11: solo active en el selector).
     */
    public function getRepositoriesProperty(): Collection
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
    }

    protected function rules(): array
    {
        return [
            'questionText' => 'required|string|max:2000',
            'tags' => 'array|max:10',
            'reviewFrequency' => 'in:weekly,monthly,quarterly',
        ];
    }

    public function updatedQuestionText(): void
    {
        $this->refreshSuggestions();
    }

    public function updatedTags(): void
    {
        $this->refreshSuggestions();
    }

    public function addTag(): void
    {
        $tag = strtolower(trim($this->tagInput));
        if (! $tag) {
            return;
        }
        if (! preg_match('/^[a-z0-9áéíóúüñ\-]+$/u', $tag)) {
            return;
        }
        if (in_array($tag, $this->tags)) {
            return;
        }
        if (count($this->tags) >= 10) {
            return;
        }
        $this->tags[] = $tag;
        $this->tagInput = '';
    }

    public function removeTag(int $index): void
    {
        unset($this->tags[$index]);
        $this->tags = array_values($this->tags);
    }

    public function toggleRelation(string $questionId): void
    {
        $idx = array_search($questionId, $this->confirmedRelations);
        if ($idx !== false) {
            unset($this->confirmedRelations[$idx]);
            $this->confirmedRelations = array_values($this->confirmedRelations);
        } else {
            $this->confirmedRelations[] = $questionId;
        }
    }

    private function refreshSuggestions(): void
    {
        if (strlen(trim($this->questionText)) < 5 && count($this->tags) === 0) {
            $this->suggestions = [];

            return;
        }

        // ponytail: call RelationSuggester directly, no HTTP round-trip to self
        // ponytail: excludeId not needed for create; pass question ID on edit
        $suggester = app(RelationSuggester::class);
        $this->suggestions = $suggester->suggest(
            $this->questionText,
            $this->tags,
            current_user_id(),
        );

        $this->suggestions = array_values(array_filter($this->suggestions, fn ($s) => ! in_array($s['id'], $this->confirmedRelations)));
    }

    public function save(): void
    {
        $this->validate();
        $this->status = 'consulting';
        $this->error = null;

        // D2 — el tenant sale del repositorio activo seleccionado. Con 0 repos activos
        // el flujo queda bloqueado (§6.5/6.12); la vista muestra el aviso correspondiente.
        $repo = $this->repositories->firstWhere('id', $this->repositoryId) ?? $this->repositories->first();

        if (! $repo) {
            $this->error = 'Conectá tu fuente de conocimiento en Configuración para crear preguntas.';
            $this->status = 'error';

            return;
        }

        try {
            $kuaforia = app(RagProviderInterface::class);
            $response = $kuaforia->consult($this->questionText, null, $repo->resolved_tenant_slug);
        } catch (KuaforiaException $e) {
            $this->error = $e->getMessage();
            $this->status = 'error';

            return;
        } catch (\Throwable $e) {
            $this->error = 'Error de conexión. Intenta de nuevo.';
            $this->status = 'error';

            return;
        }

        DB::transaction(function () use ($response, $repo) {
            $question = Question::create([
                'user_id' => current_user_id(),
                'repository_id' => $repo->id,
                'question_text' => $this->questionText,
                'answer_text' => $response->answerText,
                'tags' => $this->tags,
                'review_frequency' => $this->reviewFrequency,
                'last_consulted_at' => now(),
                'conversation_id' => $response->conversationId,
            ]);

            // P9 — last_used_at se actualiza en la creación de pregunta (no en follow-ups).
            $repo->update(['last_used_at' => now()]);

            $question->versions()->create([
                'version_number' => 1,
                'answer_text' => $response->answerText,
                'confidence' => $response->confidence,
                'sources' => $response->sources,
                'response_hash' => hash('sha256', $response->answerText),
                'is_current' => true,
            ]);

            // ponytail: no existence check needed — IDs come from RelationSuggester query
            foreach ($this->confirmedRelations as $targetId) {
                $question->outboundRelations()->create([
                    'source_question_id' => $question->id,
                    'target_question_id' => $targetId,
                    'label' => 'relacionado con',
                    'relation_type' => 'tag_suggested',
                ]);
            }
        });

        $this->status = 'saved';
        $this->answerText = $response->answerText;
    }

    public function title(): string
    {
        return 'Nueva pregunta';
    }

    public function render()
    {
        return view('livewire.create-question');
    }
}
