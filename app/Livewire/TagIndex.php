<?php

namespace App\Livewire;

use App\Models\Question;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts::app')]
class TagIndex extends Component
{
    public string $search = '';

    public function getTagsProperty(): array
    {
        $questions = Question::where('user_id', current_user_id())
            ->where(function ($q) {
                $q->where('status', 'active')->orWhereNull('status');
            })
            ->get(['tags']);

        $tagCounts = [];
        foreach ($questions as $question) {
            if ($question->tags && is_array($question->tags)) {
                foreach ($question->tags as $tag) {
                    $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
                }
            }
        }

        arsort($tagCounts);

        $result = [];
        foreach ($tagCounts as $tag => $count) {
            if (!$this->search || str_contains($tag, strtolower(trim($this->search)))) {
                $result[] = ['tag' => $tag, 'count' => $count];
            }
        }

        return $result;
    }

    public function title(): string
    {
        return 'Tags';
    }

    public function render()
    {
        return view('livewire.tag-index', [
            'tags' => $this->tags,
        ]);
    }
}
