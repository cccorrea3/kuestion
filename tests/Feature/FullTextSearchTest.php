<?php

namespace Tests\Feature;

use App\Livewire\QuestionFeed;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FullTextSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.api_key' => 'test']);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_api_search_matches_word_with_fulltext(): void
    {
        Question::factory()->create([
            'user_id' => $this->user->uuid,
            'question_text' => '¿Cómo funciona el reembolso de suscripciones?',
        ]);
        Question::factory()->create([
            'user_id' => $this->user->uuid,
            'question_text' => '¿Qué pasa si cancelo mi plan premium?',
        ]);

        $response = $this->getJson('/api/questions?search=reembolso', ['X-App-Key' => 'test']);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.question_text', '¿Cómo funciona el reembolso de suscripciones?');
    }

    public function test_api_search_short_term_falls_back_to_like(): void
    {
        Question::factory()->create([
            'user_id' => $this->user->uuid,
            'question_text' => '¿Qué es la ia generativa?',
        ]);

        // "ia" tiene 2 caracteres → no indexado por FULLTEXT → fallback a LIKE.
        $response = $this->getJson('/api/questions?search=ia', ['X-App-Key' => 'test']);

        $response->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_feed_search_filters_results(): void
    {
        Question::factory()->create([
            'user_id' => $this->user->uuid,
            'question_text' => '¿Cómo registro un cobro duplicado?',
        ]);
        Question::factory()->create([
            'user_id' => $this->user->uuid,
            'question_text' => '¿Cuál es la política de vacaciones?',
        ]);

        Livewire::test(QuestionFeed::class)
            ->set('search', 'cobro')
            ->assertOk()
            ->assertSee('¿Cómo registro un cobro duplicado?')
            ->assertDontSee('¿Cuál es la política de vacaciones?');
    }

    public function test_suggest_relations_matches_keyword_with_fulltext(): void
    {
        Question::factory()->create([
            'user_id' => $this->user->uuid,
            'question_text' => '¿Qué son los embeddings de texto?',
            'tags' => ['rag'],
        ]);
        Question::factory()->create([
            'user_id' => $this->user->uuid,
            'question_text' => '¿Cómo funciona el vector store?',
            'tags' => [],
        ]);

        $response = $this->getJson('/api/questions/suggest-relations?text=embeddings', ['X-App-Key' => 'test']);

        $response->assertStatus(200)->assertJsonCount(1);
    }
}
