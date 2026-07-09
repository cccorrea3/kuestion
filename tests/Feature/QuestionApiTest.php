<?php

namespace Tests\Feature;

use App\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QuestionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.api_key' => 'test']);
        config(['app.user_id' => '00000000-0000-0000-0000-000000000001']);

        Http::fake([
            '*/consult*' => Http::response([
                'answer' => 'París es la capital de Francia.',
                'confidence' => 95.5,
                'sources' => ['wikipedia'],
            ]),
        ]);
    }

    public function test_can_create_question(): void
    {
        $response = $this->postJson('/api/questions', [
            'question_text' => '¿Cuál es la capital de Francia?',
        ], ['X-App-Key' => 'test']);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'question_text', 'answer_text']);
    }

    public function test_list_questions(): void
    {
        Question::factory()->count(3)->create(['user_id' => config('app.user_id')]);

        $response = $this->getJson('/api/questions', ['X-App-Key' => 'test']);

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }
}
