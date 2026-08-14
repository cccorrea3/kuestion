<?php

namespace Tests\Feature;

use App\Jobs\CheckQuestionUpdatesJob;
use App\Models\Question;
use App\Models\User;
use App\Services\KuaforiaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckQuestionUpdatesJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_job_does_not_duplicate_versions_when_run_twice(): void
    {
        Http::fake([
            '*/consult*' => Http::response([
                'answer' => 'Nueva respuesta',
                'confidence' => 90,
                'sources' => [],
            ]),
        ]);

        $question = $this->questionWithVersion('Respuesta original');

        (new CheckQuestionUpdatesJob)->handle(app(KuaforiaService::class));

        $this->assertSame(2, $question->versions()->count());
        $this->assertSame(1, DB::table('notifications')->where('type', 'answer_changed')->count());

        // Segunda corrida: la pregunta ya no está vencida (last_consulted_at actualizado).
        (new CheckQuestionUpdatesJob)->handle(app(KuaforiaService::class));

        $this->assertSame(2, $question->versions()->count());
        $this->assertSame(1, DB::table('notifications')->where('type', 'answer_changed')->count());
    }

    public function test_job_marks_error_without_new_version_when_kuaforia_returns_empty(): void
    {
        Http::fake([
            '*/consult*' => Http::response(['answer' => '']),
        ]);

        $question = $this->questionWithVersion('Respuesta original');

        (new CheckQuestionUpdatesJob)->handle(app(KuaforiaService::class));

        // No se crea versión nueva y se actualiza last_consulted_at.
        $this->assertSame(1, $question->versions()->count());
        $this->assertNotNull($question->fresh()->last_consulted_at);

        $error = DB::table('notifications')->where('type', 'query_error')->first();
        $this->assertNotNull($error);
        $this->assertSame($question->id, json_decode($error->data, true)['question_id']);

        // Anti-spam: forzando la pregunta a vencida de nuevo, no se duplica la notificación.
        $question->update(['last_consulted_at' => now()->subMonth()]);

        (new CheckQuestionUpdatesJob)->handle(app(KuaforiaService::class));

        $this->assertSame(1, DB::table('notifications')->where('type', 'query_error')->count());
        $this->assertSame(1, $question->versions()->count());
    }

    private function questionWithVersion(string $answerText): Question
    {
        $question = Question::factory()->create([
            'user_id' => $this->user->uuid,
            'status' => 'active',
            'review_frequency' => 'weekly',
        ]);

        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => $answerText,
            'confidence' => 80,
            'sources' => [],
            'response_hash' => hash('sha256', $answerText),
            'is_current' => true,
        ]);

        return $question;
    }
}
