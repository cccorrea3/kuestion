<?php

namespace Tests\Feature;

use App\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuggestRelationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.api_key' => 'test']);
        config(['app.user_id' => '00000000-0000-0000-0000-000000000001']);

        Question::factory()->create([
            'user_id' => config('app.user_id'),
            'question_text' => '¿Qué son los embeddings?',
            'tags' => ['embeddings', 'rag'],
        ]);
    }

    public function test_suggest_by_tags(): void
    {
        $response = $this->getJson('/api/questions/suggest-relations?text=embeddings&tags[]=embeddings', ['X-App-Key' => 'test']);

        $response->assertStatus(200);
    }

    public function test_suggest_no_auth(): void
    {
        $response = $this->getJson('/api/questions/suggest-relations?text=test');

        $response->assertStatus(401);
    }
}
