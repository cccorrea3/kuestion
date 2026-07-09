<?php

namespace App\Livewire;

use App\Models\Question;
use App\Models\QuestionRelation;
use Livewire\Component;

class BacklinksPanel extends Component
{
    public Question $question;
    public bool $expanded = false;

    public function getBacklinksProperty()
    {
        return QuestionRelation::where('target_question_id', $this->question->id)
            ->with('source:id,question_text,tags')
            ->get();
    }

    public function render()
    {
        return view('livewire.backlinks-panel');
    }
}
