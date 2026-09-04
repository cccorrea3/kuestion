<?php

namespace Tests\Feature;

use App\Jobs\CheckQuestionUpdatesJob;
use App\Models\Question;
use App\Models\User;
use App\Notifications\AnswerChangedNotification;
use App\Services\ConnectorRegistry;
use App\Services\KuaforiaResponse;
use App\Services\QuestionChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeRagProvider;
use Tests\TestCase;

/**
 * Ola 1 P5/6 — Fase 4: validar que las preguntas con found:false quedan
 * vigiladas automáticamente, sin gates condicionales (4.1–4.3).
 */
class VigilanciaFoundFalseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    // 4.1 — flujo completo "sin respuesta → con respuesta" con texto informativo (NO vacío).
    public function test_found_false_to_true_full_flow(): void
    {
        $fake = new FakeRagProvider;
        $fake->respondWith(new KuaforiaResponse(
            answerText: 'No encontré información relevante para tu pregunta.',
            confidence: 0.0,
            sources: [],
            found: false,
        ));
        $this->app->instance(get_class($fake), $fake);
        config(['kuestion.connectors.qbk.rag_provider' => get_class($fake)]);
        $registry = new ConnectorRegistry;
        $checker = new QuestionChecker($registry);

        $question = Question::factory()->create([
            'user_id' => $this->user->uuid,
            'status' => 'active',
            'review_frequency' => 'weekly',
            'has_unreviewed_changes' => false,
        ]);
        $question->repository->update(['connector_type' => 'qbk']);

        // Primera consulta: found=false con texto informativo → versión 1 registra found=false.
        $result = $checker->check($question);

        $this->assertSame('changed', $result['status']);
        $v1 = $question->fresh()->currentVersion;
        $this->assertSame(1, $v1->version_number);
        $this->assertFalse($v1->found);
        $this->assertFalse($v1->was_empty_prev);
        $this->assertNotNull($question->fresh()->last_consulted_at);

        // Alguien aporta contenido → la re-consulta devuelve found=true con respuesta real.
        $fake->respondWith(new KuaforiaResponse(
            answerText: 'El batch del banco llega después de las 6am por una demora del proveedor.',
            confidence: 80.0,
            sources: [['node_id' => 'NK-001', 'tipo' => 'N-K']],
            found: true,
        ));

        $result = $checker->check($question);

        $this->assertSame('changed', $result['status']);
        $this->assertTrue($result['was_empty_prev']);

        $v2 = $question->fresh()->currentVersion;
        $this->assertSame(2, $v2->version_number);
        $this->assertTrue($v2->found);
        $this->assertTrue($v2->was_empty_prev);
        $this->assertTrue($question->fresh()->has_unreviewed_changes);

        // Notificación de la versión 2 con el flag was_empty_prev.
        $notifications = DB::table('notifications')->where('type', AnswerChangedNotification::class)->get();
        $this->assertCount(2, $notifications);
        $data = $notifications->map(fn ($n) => json_decode($n->data, true))
            ->firstWhere('version_number', 2);
        $this->assertNotNull($data);
        $this->assertArrayHasKey('was_empty_prev', $data);
        $this->assertTrue($data['was_empty_prev']);
    }

    // 4.2 — el job procesa preguntas con found=false (sin gates): si la respuesta
    // sigue siendo la misma (found=false, mismo texto informativo), queda unchanged.
    public function test_job_processes_found_false_questions_when_due(): void
    {
        Http::fake([
            '*' => Http::response([
                'answer' => 'No encontré información relevante para tu pregunta.',
                'confidence' => 0.0,
                'sources' => [],
                'found' => false,
            ]),
        ]);

        $question = Question::factory()->create([
            'user_id' => $this->user->uuid,
            'status' => 'active',
            'review_frequency' => 'weekly',
            'has_unreviewed_changes' => false,
        ]);
        $question->repository->update([
            'connector_type' => 'qbk',
            'credential' => ['api_token' => 'qbk_test_token'],
        ]);
        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => 'No encontré información relevante para tu pregunta.',
            'confidence' => 0,
            'sources' => [],
            'response_hash' => hash('sha256', 'No encontré información relevante para tu pregunta.'),
            'found' => false,
            'was_empty_prev' => false,
            'is_current' => true,
        ]);
        // Vence hoy (consultada hace una semana).
        $question->update(['last_consulted_at' => now()->subWeek()->subHour()]);

        (new CheckQuestionUpdatesJob)->handle(app(ConnectorRegistry::class));

        // Fue procesada: last_consulted_at se actualizó y no se creó versión nueva
        // (misma respuesta → unchanged). Si el job la hubiera saltado, last_consulted_at
        // seguiría vencido.
        $this->assertSame(1, $question->versions()->count());
        $fresh = $question->fresh();
        $this->assertNotNull($fresh->last_consulted_at);
        $this->assertTrue($fresh->last_consulted_at->gt(now()->subDay()));
    }

    // 4.2 cont. — el job detecta la transición found:false → found:true.
    public function test_job_detects_transition_to_found_true(): void
    {
        Http::fake([
            '*' => Http::response([
                'answer' => 'Ahora hay una respuesta real para esta pregunta.',
                'confidence' => 0.8,
                'sources' => [['node_id' => 'NK-001']],
                'found' => true,
            ]),
        ]);

        $question = Question::factory()->create([
            'user_id' => $this->user->uuid,
            'status' => 'active',
            'review_frequency' => 'weekly',
            'has_unreviewed_changes' => false,
        ]);
        $question->repository->update([
            'connector_type' => 'qbk',
            'credential' => ['api_token' => 'qbk_test_token'],
        ]);
        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => 'No encontré información relevante para tu pregunta.',
            'confidence' => 0,
            'sources' => [],
            'response_hash' => hash('sha256', 'No encontré información relevante para tu pregunta.'),
            'found' => false,
            'was_empty_prev' => false,
            'is_current' => true,
        ]);
        $question->update(['last_consulted_at' => now()->subWeek()->subHour()]);

        (new CheckQuestionUpdatesJob)->handle(app(ConnectorRegistry::class));

        $v2 = $question->fresh()->currentVersion;
        $this->assertSame(2, $v2->version_number);
        $this->assertTrue($v2->found);
        $this->assertTrue($v2->was_empty_prev);
        $this->assertTrue($question->fresh()->has_unreviewed_changes);
    }

    // 4.3 — regresión: preguntas con respuesta existente siguen funcionando igual (QBK).
    public function test_regression_qbk_answer_change_still_detected(): void
    {
        $fake = new FakeRagProvider;
        $fake->respondWith(new KuaforiaResponse(
            answerText: 'Respuesta QBK actualizada (cambio normal)',
            confidence: 90.0,
            sources: [],
            found: true,
        ));
        $this->app->instance(get_class($fake), $fake);
        config(['kuestion.connectors.qbk.rag_provider' => get_class($fake)]);
        $registry = new ConnectorRegistry;
        $checker = new QuestionChecker($registry);

        $question = Question::factory()->create([
            'user_id' => $this->user->uuid,
            'status' => 'active',
            'review_frequency' => 'weekly',
        ]);
        $question->repository->update(['connector_type' => 'qbk']);
        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => 'Respuesta QBK original',
            'confidence' => 80,
            'sources' => [],
            'response_hash' => hash('sha256', 'Respuesta QBK original'),
            'found' => true,
            'was_empty_prev' => false,
            'is_current' => true,
        ]);

        $result = $checker->check($question);

        $this->assertSame('changed', $result['status']);
        $this->assertFalse($result['was_empty_prev']);
        $this->assertSame(2, $question->versions()->count());
        $this->assertSame('Respuesta QBK actualizada (cambio normal)', $question->fresh()->currentVersion->answer_text);
    }

    // 4.3 — regresión: Kuaforia (default, sin found explícito) sigue funcionando.
    public function test_regression_kuaforia_still_works(): void
    {
        Http::fake([
            '*/consult*' => Http::response([
                'answer' => 'Kuaforia respuesta actualizada',
                'confidence' => 90,
                'sources' => [],
            ]),
            '*/api/v1/mcp' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'content' => [['type' => 'text', 'text' => '{"status":"healthy","score":85}']],
                    'isError' => false,
                ],
            ]),
        ]);

        $registry = app(ConnectorRegistry::class);
        $checker = new QuestionChecker($registry);

        $question = Question::factory()->create([
            'user_id' => $this->user->uuid,
            'status' => 'active',
            'review_frequency' => 'weekly',
            'has_unreviewed_changes' => false,
        ]);
        $question->repository->update(['resolved_workspace_id' => 'ws-1']);
        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => 'Kuaforia respuesta original',
            'confidence' => 80,
            'sources' => [],
            'response_hash' => hash('sha256', 'Kuaforia respuesta original'),
            'is_current' => true,
        ]);

        $result = $checker->check($question);

        $this->assertSame('changed', $result['status']);
        $this->assertSame(2, $question->versions()->count());
        $this->assertSame('Kuaforia respuesta actualizada', $question->fresh()->currentVersion->answer_text);
    }
}
