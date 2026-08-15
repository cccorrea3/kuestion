<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Repository;
use App\Models\User;
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
        $user = User::factory()->create();
        // D2 — la creación por API resuelve el repositorio activo del usuario.
        Repository::factory()->create(['user_id' => $user->uuid]);
        $this->actingAs($user);

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

        $question = Question::first();
        $this->assertNotNull($question->repository_id);
    }

    public function test_create_question_blocks_without_active_repository(): void
    {
        // Sin repositorios activos (todos revoked) → bloqueo §6.5/6.12.
        auth()->user()->repositories()->update(['status' => 'revoked']);

        $response = $this->postJson('/api/questions', [
            'question_text' => '¿Cuál es la capital de Francia?',
        ], ['X-App-Key' => 'test']);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'Necesitás una conexión activa con Kuaforia para crear preguntas.');

        $this->assertSame(0, Question::count());
    }

    public function test_list_questions(): void
    {
        Question::factory()->count(3)->create(['user_id' => current_user_id()]);

        $response = $this->getJson('/api/questions', ['X-App-Key' => 'test']);

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }
}
