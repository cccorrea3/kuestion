<?php

namespace App\Livewire;

use App\Models\Question;
use Livewire\Component;

class FeedbackButtons extends Component
{
    public Question $question;
    public ?string $feedback = null;

    public function mount(): void
    {
        $current = $this->question->currentVersion;
        $this->feedback = $current?->feedback;
    }

    public function setFeedback(string $type): void
    {
        if (!in_array($type, ['helpful', 'not_helpful'])) return;
        $current = $this->question->versions()->where('is_current', true)->first();
        if (!$current) return;

        if ($this->feedback === $type) {
            $current->update(['feedback' => null]);
            $this->feedback = null;
        } else {
            $current->update(['feedback' => $type]);
            $this->feedback = $type;
        }
    }

    public function render()
    {
        return view('livewire.feedback-buttons');
    }
}
