<?php

namespace Tests\Feature;

use App\Jobs\CheckQuestionUpdatesJob;
use App\Livewire\QuestionDetail;
use App\Models\Question;
use App\Models\User;
use App\Notifications\AnswerChangedNotification;
use App\Services\ConnectorRegistry;
use App\Services\KuaforiaResponse;
use App\Services\QuestionChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Fakes\FakeRagProvider;
use Tests\TestCase;

/**
 * Fase 6 — Validación del ChangeDetector y cierre (Ola 1 Punto 1).
 *
 * Verifica que el flujo completo de vigilancia funciona con repos qbk:
 * - ChangeDetector detecta cambios y crea versiones
 * - Job horario procesa preguntas qbk
 * - "Comprobar ahora" funciona con repos qbk
 * - Regresión: Kuaforia sigue funcionando
 */
class QbkConnectorE2ETest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    // --- 6.1: E2E vigilancia completa con repo qbk ---

    public function test_qbk_vigilancia_detects_change_creates_version_and_notifies(): void
    {
        $fake = new FakeRagProvider;
        $fake->respondWith(new KuaforiaResponse(
            answerText: 'Respuesta actualizada desde QuBeKa',
            confidence: 75.0,
            sources: [['node_id' => 'NK-001', 'tipo' => 'N-K']],
        ));
        $this->app->instance(get_class($fake), $fake);

        config(['kuestion.connectors.qbk.rag_provider' => get_class($fake)]);

        $registry = new ConnectorRegistry;
        $checker = new QuestionChecker($registry);

        // Crear pregunta con repo qbk y respuesta inicial
        $question = Question::factory()->create([
            'user_id' => $this->user->uuid,
            'status' => 'active',
            'review_frequency' => 'weekly',
            'has_unreviewed_changes' => false,
        ]);
        $question->repository->update(['connector_type' => 'qbk']);
        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => 'Respuesta original de QuBeKa',
            'confidence' => 80,
            'sources' => [],
            'response_hash' => hash('sha256', 'Respuesta original de QuBeKa'),
            'is_current' => true,
        ]);

        // Ejecutar detección
        $result = $checker->check($question);

        // Verificar que se detectó el cambio
        $this->assertSame('changed', $result['status']);
        $this->assertSame(2, $result['version_number']);

        // Verificar que se creó la versión 2
        $this->assertSame(2, $question->versions()->count());
        $this->assertSame('Respuesta actualizada desde QuBeKa', $question->fresh()->currentVersion->answer_text);
        $this->assertEquals(75.0, (float) $question->fresh()->currentVersion->confidence);
        $this->assertEquals([['node_id' => 'NK-001', 'tipo' => 'N-K']], $question->fresh()->currentVersion->sources);

        // Verificar estados
        $this->assertTrue($question->fresh()->has_unreviewed_changes);
        $this->assertNotNull($question->fresh()->last_change_detected_at);
        $this->assertNotNull($question->fresh()->last_consulted_at);
        $this->assertNotNull($question->repository->fresh()->last_used_at);

        // Verificar notificación
        $notification = DB::table('notifications')->where('type', AnswerChangedNotification::class)->first();
        $this->assertNotNull($notification);
        $data = json_decode($notification->data, true);
        $this->assertSame($question->id, $data['question_id']);
        $this->assertSame(2, $data['version_number']);
        $this->assertSame('new_version', $data['change_type']);
    }

    public function test_qbk_vigilancia_unchanged_skips_versioning(): void
    {
        $fake = new FakeRagProvider;
        $fake->respondWith(new KuaforiaResponse(
            answerText: 'Respuesta original de QuBeKa',
            confidence: 80.0,
            sources: [],
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
        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => 'Respuesta original de QuBeKa',
            'confidence' => 80,
            'sources' => [],
            'response_hash' => hash('sha256', 'Respuesta original de QuBeKa'),
            'is_current' => true,
        ]);

        $result = $checker->check($question);

        $this->assertSame('unchanged', $result['status']);
        $this->assertSame(1, $question->versions()->count());
        $this->assertFalse($question->fresh()->has_unreviewed_changes);
        $this->assertCount(0, DB::table('notifications')->get());
    }

    // --- 6.2: Job horario con repos qbk ---

    public function test_job_processes_qbk_questions_when_due(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/mcp')) {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'content' => [['type' => 'text', 'text' => '{"status":"healthy","score":85}']],
                        'isError' => false,
                    ],
                ]);
            }

            return Http::response([
                'answer' => 'Nueva respuesta Qbk',
                'confidence' => 0.5,
                'sources' => [],
                'found' => true,
            ]);
        });

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
            'answer_text' => 'Respuesta original',
            'confidence' => 80,
            'sources' => [],
            'response_hash' => hash('sha256', 'Respuesta original'),
            'is_current' => true,
        ]);
        // Make it due
        $question->update(['last_consulted_at' => now()->subWeek()->subHour()]);

        (new CheckQuestionUpdatesJob)->handle(app(ConnectorRegistry::class));

        $this->assertSame(2, $question->versions()->count());
        $this->assertSame('Nueva respuesta Qbk', $question->fresh()->currentVersion->answer_text);
        $this->assertSame(1, DB::table('notifications')->where('type', AnswerChangedNotification::class)->count());
    }

    public function test_job_skips_qbk_questions_not_due(): void
    {
        Http::fake([
            '*' => Http::response([
                'answer' => 'Should not be called',
                'confidence' => 0.5,
                'sources' => [],
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
            'answer_text' => 'Respuesta original',
            'confidence' => 80,
            'sources' => [],
            'response_hash' => hash('sha256', 'Respuesta original'),
            'is_current' => true,
        ]);
        // Not due (consulted just now)
        $question->update(['last_consulted_at' => now()]);

        (new CheckQuestionUpdatesJob)->handle(app(ConnectorRegistry::class));

        $this->assertSame(1, $question->versions()->count());
        $this->assertCount(0, DB::table('notifications')->get());
    }

    // --- 6.3: "Comprobar ahora" con repos qbk ---

    public function test_check_now_works_with_qbk_repo(): void
    {
        $fake = new FakeRagProvider;
        $fake->respondWith(new KuaforiaResponse(
            answerText: 'Respuesta actualizada via checkNow',
            confidence: 85.0,
            sources: [],
        ));
        $this->app->instance(get_class($fake), $fake);

        config(['kuestion.connectors.qbk.rag_provider' => get_class($fake)]);

        $user = User::factory()->create();
        $question = Question::factory()->create([
            'user_id' => $user->uuid,
            'status' => 'active',
            'review_frequency' => 'weekly',
        ]);
        $question->repository->update(['connector_type' => 'qbk']);
        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => 'Respuesta original',
            'confidence' => 80,
            'sources' => [],
            'response_hash' => hash('sha256', 'Respuesta original'),
            'is_current' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(QuestionDetail::class, ['question' => $question])
            ->call('checkNow')
            ->assertSet('checkResultType', 'success')
            ->assertSet('checkResult', 'Cambio detectado: se creó la versión 2 con la respuesta actualizada.');

        $this->assertSame(2, $question->versions()->count());
        $this->assertSame('Respuesta actualizada via checkNow', $question->fresh()->currentVersion->answer_text);
    }

    // --- 6.4: Regresión — Kuaforia sigue funcionando ---

    public function test_kuaforia_vigilancia_still_works_after_qbk_changes(): void
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
        // Keep default connector_type = 'kuaforia'
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
