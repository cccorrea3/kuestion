<?php

namespace App\Livewire;

use App\Models\Question;
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

    public function getQuestionsProperty(): LengthAwarePaginator
    {
        $query = Question::where('user_id', current_user_id());

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
        ]);
    }
}
