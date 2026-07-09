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
    protected $queryString = ['filter', 'search'];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function getQuestionsProperty(): LengthAwarePaginator
    {
        $query = Question::where('user_id', config('app.user_id'));

        if ($this->filter === 'changes') {
            $query->where('has_unreviewed_changes', true);
        } elseif ($this->filter === 'starred') {
            $query->where('is_starred', true);
        }

        if ($this->search) {
            $search = str_replace(['%', '_'], ['\\%', '\\_'], $this->search);
            $query->where('question_text', 'like', '%' . $search . '%');
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
