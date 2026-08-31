<?php

namespace Tests\Feature;

use App\Exceptions\KuaforiaException;
use App\Models\Question;
use App\Models\User;
use App\Notifications\AnswerChangedNotification;
use App\Notifications\QueryErrorNotification;
use App\Services\ConnectorRegistry;
use App\Services\KuaforiaResponse;
use App\Services\QbkIdentityResolver;
use App\Services\QuestionChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeRagProvider;
use Tests\TestCase;

class QuestionCheckerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        config(['services.kuaforia.mcp_api_key' => 'test-key']);
    }

    public function test_check_detects_change_creates_version_and_notifies(): void
    {
        $fake = new FakeRagProvider;
        $fake->respondWith(new KuaforiaResponse(
            answerText: 'Respuesta nueva tras modificar el caso',
            confidence: 90.0,
            sources: [],
        ));
        $this->registerFake($fake);
        $checker = $this->checkerWith($fake);

        $question = $this->questionWithVersion('Respuesta original');

        $result = $checker->check($question);

        $this->assertSame('changed', $result['status']);
        $this->assertSame(2, $result['version_number']);
        $this->assertStringContainsString('versión 2', $result['message']);

        $this->assertSame(2, $question->versions()->count());
        $this->assertSame('Respuesta nueva tras modificar el caso', $question->fresh()->currentVersion->answer_text);
        $this->assertTrue($question->fresh()->has_unreviewed_changes);
        $this->assertNotNull($question->fresh()->last_change_detected_at);
        $this->assertNotNull($question->fresh()->last_consulted_at);
        $this->assertNotNull($question->repository->fresh()->last_used_at);

        // Notificación con el payload base intacto.
        $notification = DB::table('notifications')->where('type', AnswerChangedNotification::class)->first();
        $this->assertNotNull($notification);
        $data = json_decode($notification->data, true);
        $this->assertSame($question->id, $data['question_id']);
        $this->assertSame(2, $data['version_number']);
    }

    public function test_check_unchanged_updates_last_consulted_without_new_version(): void
    {
        $fake = new FakeRagProvider;
        $fake->respondWith(new KuaforiaResponse(
            answerText: 'Respuesta original',
            confidence: 80.0,
            sources: [],
        ));
        $this->registerFake($fake);
        $checker = $this->checkerWith($fake);

        $question = $this->questionWithVersion('Respuesta original');
        $question->update(['last_consulted_at' => now()->subDays(10)]);

        $result = $checker->check($question);

        $this->assertSame('unchanged', $result['status']);
        $this->assertSame(1, $question->versions()->count());
        $this->assertNotNull($question->fresh()->last_consulted_at);
        $this->assertFalse($question->fresh()->has_unreviewed_changes);
        $this->assertCount(0, DB::table('notifications')->get());
    }

    public function test_check_marks_repository_invalid_on_401(): void
    {
        $fake = new FakeRagProvider;
        $fake->throwWhenCalled(new KuaforiaException('Invalid or expired API key', 401));
        $this->registerFake($fake);
        $checker = $this->checkerWith($fake);

        $question = $this->questionWithVersion('Respuesta original');

        $result = $checker->check($question);

        $this->assertSame('error', $result['status']);
        $this->assertSame('invalid', $question->repository->fresh()->status);
        $this->assertCount(0, DB::table('notifications')->get());
    }

    public function test_check_returns_error_on_transient_kuaforia_failure(): void
    {
        $fake = new FakeRagProvider;
        $fake->throwWhenCalled(new KuaforiaException('Kuaforia respondió con error: 503', 503));
        $this->registerFake($fake);
        $checker = $this->checkerWith($fake);

        $question = $this->questionWithVersion('Respuesta original');

        $result = $checker->check($question);

        $this->assertSame('error', $result['status']);
        // 503 no invalida el repositorio (el breaker es por servicio, no por repo).
        $this->assertSame('active', $question->repository->fresh()->status);
        $this->assertSame(1, $question->versions()->count());
    }

    public function test_check_skips_when_repository_has_no_resolved_tenant(): void
    {
        $fake = new FakeRagProvider;
        $this->registerFake($fake);
        $checker = $this->checkerWith($fake);

        $question = $this->questionWithVersion('Respuesta original');
        $question->repository->update(['resolved_tenant_slug' => null]);

        $result = $checker->check($question);

        $this->assertSame('skipped', $result['status']);
        $this->assertCount(0, $fake->calls);
        $this->assertSame(1, $question->versions()->count());
    }

    public function test_check_empty_response_notifies_error_once(): void
    {
        $fake = new FakeRagProvider;
        $fake->respondWith(new KuaforiaResponse(answerText: '   ', confidence: 0.0, sources: []));
        $this->registerFake($fake);
        $checker = $this->checkerWith($fake);

        $question = $this->questionWithVersion('Respuesta original');

        $result = $checker->check($question);

        $this->assertSame('empty', $result['status']);
        $this->assertSame(1, $question->versions()->count());

        $this->assertCount(1, DB::table('notifications')
            ->where('type', QueryErrorNotification::class)
            ->get());
    }

    public function test_check_enriches_notification_with_signals(): void
    {
        Http::fake([
            '*/api/v1/mcp' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'content' => [['type' => 'text', 'text' => '{"status":"healthy","score":85}']],
                    'isError' => false,
                ],
            ]),
            '*/consult*' => Http::response([
                'answer' => 'Respuesta nueva',
                'confidence' => 90,
                'sources' => [],
            ]),
        ]);

        $registry = app(ConnectorRegistry::class);
        $checker = new QuestionChecker($registry);

        $question = $this->questionWithVersion('Respuesta original');
        $question->repository->update(['resolved_workspace_id' => 'ws-1']);

        $result = $checker->check($question);

        $this->assertSame('changed', $result['status']);

        $notification = DB::table('notifications')->where('type', AnswerChangedNotification::class)->first();
        $data = json_decode($notification->data, true);
        $this->assertArrayHasKey('signals', $data);
        $this->assertSame('healthy', $data['signals']['workspace_health']['status']);
    }

    // --- Routing tests (Ola 1 Punto 1 — Fase 2) ---

    public function test_checker_resolves_correct_provider_by_connector_type(): void
    {
        $fake = new FakeRagProvider;
        $fake->respondWith(new KuaforiaResponse(
            answerText: 'Respuesta del conector custom',
            confidence: 85.0,
            sources: [],
        ));

        config(['kuestion.connectors.test_connector' => [
            'display_name' => 'Test Connector',
            'description' => 'For testing routing',
            'auth_fields' => [['key' => 'api_token', 'label' => 'Token']],
            'help_url' => null,
            'identity_resolver' => QbkIdentityResolver::class,
            'rag_provider' => get_class($fake),
            'signal_provider' => null,
        ]]);
        $this->app->instance(get_class($fake), $fake);

        $registry = new ConnectorRegistry;
        $checker = new QuestionChecker($registry);

        $question = $this->questionWithVersion('Respuesta original');
        $question->repository->update(['connector_type' => 'test_connector']);

        $result = $checker->check($question);

        $this->assertSame('changed', $result['status']);
        $this->assertCount(1, $fake->calls);
    }

    public function test_checker_uses_kuaforia_for_default_connector_type(): void
    {
        Http::fake([
            '*/consult*' => Http::response([
                'answer' => 'Respuesta original',
                'confidence' => 80,
                'sources' => [],
            ]),
        ]);

        $registry = app(ConnectorRegistry::class);
        $checker = new QuestionChecker($registry);

        $question = $this->questionWithVersion('Respuesta original');
        // connector_type defaults to 'kuaforia' from factory

        $result = $checker->check($question);

        $this->assertSame('unchanged', $result['status']);
    }

    private function registerFake(FakeRagProvider $fake): void
    {
        $this->app->instance(get_class($fake), $fake);
    }

    private function checkerWith(FakeRagProvider $fake): QuestionChecker
    {
        // Register the fake under _test_fake AND override kuaforia's rag_provider
        // so tests that don't explicitly set connector_type still use the fake.
        config(['kuestion.connectors._test_fake' => [
            'display_name' => 'Fake',
            'description' => '',
            'auth_fields' => [],
            'help_url' => null,
            'identity_resolver' => QbkIdentityResolver::class,
            'rag_provider' => get_class($fake),
            'signal_provider' => null,
        ]]);
        config(['kuestion.connectors.kuaforia.rag_provider' => get_class($fake)]);

        $registry = new ConnectorRegistry;

        return new QuestionChecker($registry);
    }

    private function questionWithVersion(string $answerText, string $connectorType = 'kuaforia'): Question
    {
        $question = Question::factory()->create([
            'user_id' => $this->user->uuid,
            'status' => 'active',
            'review_frequency' => 'weekly',
            'has_unreviewed_changes' => false,
        ]);

        $question->repository->update(['connector_type' => $connectorType]);

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
