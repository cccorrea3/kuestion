<?php

namespace App\Livewire;

use App\Models\Question;
use Livewire\Component;

class QuestionDetail extends Component
{
    public Question $question;
    public ?string $feedback = null;
    public bool $confirmDelete = false;

    public function mount(Question $question): void
    {
        $this->question = $question->load('currentVersion');
    }

    public function toggleStar(): void
    {
        $this->question->update(['is_starred' => !$this->question->is_starred]);
        $this->question->refresh();
    }

    public function setFeedback(string $type): void
    {
        $this->feedback = $this->feedback === $type ? null : $type;
        session(['feedback_' . $this->question->id => $this->feedback]);
    }

    public function archive(): void
    {
        $this->question->delete();
        $this->redirect(route('questions.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.question-detail', [
            'currentVersion' => $this->question->currentVersion,
            'versionCount' => $this->question->versions()->count(),
        ])->layout('layouts::app', ['title' => $this->question->question_text]);
    }
}
