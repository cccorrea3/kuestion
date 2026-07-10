<?php

namespace App\Livewire;

use App\Models\Question;
use App\Models\QuestionRelation;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class RelationsPanel extends Component
{
    public Question $question;
    public string $search = '';
    public string $label = 'relacionado con';
    public array $searchResults = [];

    protected function rules(): array
    {
        return [
            'label' => 'required|string|max:100',
        ];
    }

    public function getRelationsProperty()
    {
        return $this->question->outboundRelations()
            ->with('target:id,question_text,tags')
            ->get();
    }

    public function updatedSearch(): void
    {
        if (strlen(trim($this->search)) < 2) {
            $this->searchResults = [];
            return;
        }

        $search = str_replace(['%', '_'], ['\\%', '\\_'], $this->search);
        $existingIds = $this->question->outboundRelations()->pluck('target_question_id')->push($this->question->id);

        $this->searchResults = Question::where('user_id', current_user_id())
            ->where('question_text', 'like', '%' . $search . '%')
            ->whereNotIn('id', $existingIds)
            ->limit(10)
            ->get(['id', 'question_text', 'tags'])
            ->toArray();
    }

    public function addRelation(string $targetId): void
    {
        if ($targetId === $this->question->id) return;

        $target = Question::where('user_id', current_user_id())
            ->where('id', $targetId)
            ->first();

        if (!$target) return;

        $this->question->outboundRelations()->create([
            'target_question_id' => $target->id,
            'label' => $this->label,
            'relation_type' => 'manual',
        ]);

        $this->search = '';
        $this->searchResults = [];
    }

    public function removeRelation(string $relationId): void
    {
        $this->question->outboundRelations()->where('id', $relationId)->delete();
    }

    public function render()
    {
        return view('livewire.relations-panel');
    }
}
