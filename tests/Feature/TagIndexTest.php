<?php

namespace Tests\Feature;

use App\Livewire\QuestionFeed;
use App\Livewire\TagIndex;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TagIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_tag_shows_unreviewed_badge(): void
    {
        Question::factory()->create([
            'user_id' => $this->user->uuid,
            'tags' => ['laravel'],
            'has_unreviewed_changes' => true,
        ]);
        Question::factory()->create([
            'user_id' => $this->user->uuid,
            'tags' => ['laravel'],
            'has_unreviewed_changes' => false,
        ]);
        Question::factory()->create([
            'user_id' => $this->user->uuid,
            'tags' => ['rag'],
            'has_unreviewed_changes' => true,
        ]);

        Livewire::test(TagIndex::class)
            ->assertSee('1 sin revisar');
    }

    public function test_tag_without_unreviewed_has_no_badge(): void
    {
        Question::factory()->create([
            'user_id' => $this->user->uuid,
            'tags' => ['laravel'],
            'has_unreviewed_changes' => false,
        ]);

        Livewire::test(TagIndex::class)
            ->assertDontSee('sin revisar');
    }

    public function test_badge_disappears_when_change_accepted(): void
    {
        $question = Question::factory()->create([
            'user_id' => $this->user->uuid,
            'tags' => ['laravel'],
            'has_unreviewed_changes' => true,
        ]);

        Livewire::test(TagIndex::class)->assertSee('1 sin revisar');

        // Aceptar el cambio (13.1: el badge se actualiza dinámicamente).
        $question->update(['has_unreviewed_changes' => false]);

        Livewire::test(TagIndex::class)->assertDontSee('sin revisar');
    }

    public function test_badge_links_to_changes_filter_for_the_tag(): void
    {
        Question::factory()->create([
            'user_id' => $this->user->uuid,
            'tags' => ['laravel'],
            'has_unreviewed_changes' => true,
        ]);

        Livewire::test(TagIndex::class)
            ->assertSee(route('questions.index', ['filter' => 'changes', 'tag' => 'laravel']));
    }

    public function test_feed_filters_by_tag(): void
    {
        Question::factory()->create([
            'user_id' => $this->user->uuid,
            'question_text' => '¿Qué es Laravel?',
            'tags' => ['laravel'],
        ]);
        Question::factory()->create([
            'user_id' => $this->user->uuid,
            'question_text' => '¿Qué es RAG?',
            'tags' => ['rag'],
        ]);

        Livewire::test(QuestionFeed::class, ['tag' => 'laravel'])
            ->assertSee('¿Qué es Laravel?')
            ->assertDontSee('¿Qué es RAG?');
    }

    public function test_feed_combines_tag_with_changes_filter(): void
    {
        Question::factory()->create([
            'user_id' => $this->user->uuid,
            'question_text' => 'Laravel con cambios',
            'tags' => ['laravel'],
            'has_unreviewed_changes' => true,
        ]);
        Question::factory()->create([
            'user_id' => $this->user->uuid,
            'question_text' => 'Laravel sin cambios',
            'tags' => ['laravel'],
            'has_unreviewed_changes' => false,
        ]);
        Question::factory()->create([
            'user_id' => $this->user->uuid,
            'question_text' => 'RAG con cambios',
            'tags' => ['rag'],
            'has_unreviewed_changes' => true,
        ]);

        Livewire::test(QuestionFeed::class, ['filter' => 'changes', 'tag' => 'laravel'])
            ->assertSee('Laravel con cambios')
            ->assertDontSee('Laravel sin cambios')
            ->assertDontSee('RAG con cambios');
    }
}
