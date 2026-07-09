<?php

namespace App\Livewire;

use App\Exceptions\KuaforiaException;
use App\Models\Question;
use App\Services\KuaforiaService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts::app')]
class CreateQuestion extends Component
{
    public string $questionText = '';
    public string $tagInput = '';
    public array $tags = [];
    public string $reviewFrequency = 'weekly';
    public string $status = 'idle';
    public ?string $error = null;
    public string $answerText = '';

    protected function rules(): array
    {
        return [
            'questionText' => 'required|string|max:2000',
            'tags' => 'array|max:10',
            'reviewFrequency' => 'in:weekly,monthly,quarterly',
        ];
    }

    public function addTag(): void
    {
        $tag = strtolower(trim($this->tagInput));
        if (!$tag) return;
        if (!preg_match('/^[a-z0-9áéíóúüñ\-]+$/u', $tag)) return;
        if (in_array($tag, $this->tags)) return;
        if (count($this->tags) >= 10) return;
        $this->tags[] = $tag;
        $this->tagInput = '';
    }

    public function removeTag(int $index): void
    {
        unset($this->tags[$index]);
        $this->tags = array_values($this->tags);
    }

    public function save(): void
    {
        $this->validate();
        $this->status = 'consulting';
        $this->error = null;

        try {
            $kuaforia = app(KuaforiaService::class);
            $response = $kuaforia->consult($this->questionText);
        } catch (KuaforiaException $e) {
            $this->error = $e->getMessage();
            $this->status = 'error';
            return;
        } catch (\Throwable $e) {
            $this->error = 'Error de conexión. Intenta de nuevo.';
            $this->status = 'error';
            return;
        }

        DB::transaction(function () use ($response) {
            $question = Question::create([
                'user_id' => config('app.user_id'),
                'question_text' => $this->questionText,
                'answer_text' => $response->answerText,
                'tags' => $this->tags,
                'review_frequency' => $this->reviewFrequency,
                'last_consulted_at' => now(),
            ]);

            $question->versions()->create([
                'version_number' => 1,
                'answer_text' => $response->answerText,
                'confidence' => $response->confidence,
                'sources' => $response->sources,
                'response_hash' => hash('sha256', $response->answerText),
                'is_current' => true,
            ]);
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
