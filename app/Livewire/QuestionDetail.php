<?php

namespace App\Livewire;

use App\Models\Question;
use Livewire\Component;

class QuestionDetail extends Component
{
    public Question $question;
    public ?string $feedback = null;
    public bool $confirmDelete = false;
    public bool $showVersions = false;
    public ?int $diffFrom = null;
    public ?int $diffTo = null;

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

    public function toggleVersions(): void
    {
        $this->showVersions = !$this->showVersions;
        $this->diffFrom = null;
        $this->diffTo = null;
    }

    public function showDiff(int $from, int $to): void
    {
        $this->diffFrom = $from;
        $this->diffTo = $to;
    }

    public function clearDiff(): void
    {
        $this->diffFrom = null;
        $this->diffTo = null;
    }

    public function render()
    {
        $versions = $this->showVersions
            ? $this->question->versions()->orderBy('version_number', 'desc')->get()
            : collect();

        $diffResult = null;
        if ($this->diffFrom && $this->diffTo) {
            $from = $this->question->versions()->where('version_number', $this->diffFrom)->first();
            $to = $this->question->versions()->where('version_number', $this->diffTo)->first();
            if ($from && $to) {
                $diffResult = (new \App\Services\DiffGenerator)->diff($from->answer_text, $to->answer_text);
            }
        }

        return view('livewire.question-detail', [
            'currentVersion' => $this->question->currentVersion,
            'versions' => $versions,
            'diffResult' => $diffResult,
            'versionCount' => $this->question->versions()->count(),
        ])->layout('layouts.app', ['title' => $this->question->question_text]);
    }
}
