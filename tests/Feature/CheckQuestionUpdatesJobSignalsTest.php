<?php

namespace Tests\Feature;

use App\Contracts\StructuredSignalProviderInterface;
use App\Jobs\CheckQuestionUpdatesJob;
use App\Models\Question;
use App\Models\User;
use App\Notifications\AnswerChangedNotification;
use App\Services\KuaforiaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckQuestionUpdatesJobSignalsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        config(['services.kuaforia.mcp_api_key' => 'test-key']);
    }

    public function test_job_enriches_notification_with_signals_from_repository_workspace(): void
    {
        Http::fake([
            '*/consult*' => Http::response([
                'answer' => 'Nueva respuesta',
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

        $question = $this->questionWithVersion('Respuesta original');
        // G7 — el workspace sale del repositorio (sin fallback workspace_map).
        $question->repository->update(['resolved_workspace_id' => 'ws-1']);

        (new CheckQuestionUpdatesJob)->handle(
            app(KuaforiaService::class),
            app(StructuredSignalProviderInterface::class),
        );

        $data = $this->notificationData();

        $this->assertArrayHasKey('signals', $data);
        $this->assertSame('healthy', $data['signals']['workspace_health']['status']);
        $this->assertArrayHasKey('dependency_health_report', $data['signals']);
        $this->assertArrayHasKey('generated_at', $data['signals']);

        // El payload base conserva sus claves (los consumidores filtran por question_id).
        $this->assertSame($question->id, $data['question_id']);
        $this->assertSame(2, $data['version_number']);
    }

    public function test_job_degrades_to_base_notification_when_provider_fails(): void
    {
        Http::fake([
            '*/consult*' => Http::response([
                'answer' => 'Nueva respuesta',
                'confidence' => 90,
                'sources' => [],
            ]),
            '*/api/v1/mcp' => Http::response([], 500),
        ]);

        $question = $this->questionWithVersion('Respuesta original');
        $question->repository->update(['resolved_workspace_id' => 'ws-1']);

        (new CheckQuestionUpdatesJob)->handle(
            app(KuaforiaService::class),
            app(StructuredSignalProviderInterface::class),
        );

        $data = $this->notificationData();

        // Degradación explícita: notificación base idéntica a la de antes (sin signals).
        $this->assertArrayNotHasKey('signals', $data);
        $this->assertSame($question->id, $data['question_id']);
        $this->assertSame(2, $question->versions()->count());
    }

    public function test_job_uses_repository_workspace_and_credential_when_resolved(): void
    {
        // E2 — las señales usan el workspace y la credencial del repo (nunca la key
        // global ni un fallback). G7 — ya no existe workspace_map que pueda pisar esto.
        $mcpCalls = [];

        Http::fake(function ($request) use (&$mcpCalls) {
            if (str_contains($request->url(), '/api/v1/mcp')) {
                $mcpCalls[] = [
                    'auth' => $request->header('Authorization')[0] ?? null,
                    'arguments' => $request->data()['params']['arguments'] ?? [],
                ];

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
                'answer' => 'Nueva respuesta',
                'confidence' => 90,
                'sources' => [],
            ]);
        });

        $question = $this->questionWithVersion('Respuesta original');

        $question->repository->update([
            'resolved_workspace_id' => 'ws-repo',
            'credential' => ['api_key' => 'kfr_repo'],
        ]);

        (new CheckQuestionUpdatesJob)->handle(
            app(KuaforiaService::class),
            app(StructuredSignalProviderInterface::class),
        );

        $data = $this->notificationData();

        $this->assertArrayHasKey('signals', $data);
        $this->assertSame('healthy', $data['signals']['workspace_health']['status']);

        // Dos llamadas MCP (health + dependency), ambas con el workspace y la key del repo.
        $this->assertCount(2, $mcpCalls);
        foreach ($mcpCalls as $call) {
            $this->assertSame('Bearer kfr_repo', $call['auth']);
            $this->assertSame('ws-repo', $call['arguments']['workspace_id']);
        }
    }

    public function test_job_backfills_workspace_from_client_context_when_repository_missing_it(): void
    {
        // G7 — lazy backfill: repos creados antes de la extensión default_workspace no
        // tienen resolved_workspace_id. El job resuelve get_client_context con la
        // credencial del repo, persiste el workspace y lo usa para las señales.
        $mcpCalls = [];

        Http::fake(function ($request) use (&$mcpCalls) {
            if (str_contains($request->url(), '/api/v1/mcp')) {
                $body = $request->data();
                $mcpCalls[] = [
                    'name' => $body['params']['name'] ?? null,
                    'auth' => $request->header('Authorization')[0] ?? null,
                    'arguments' => $body['params']['arguments'] ?? [],
                ];

                if (($body['params']['name'] ?? null) === 'get_client_context') {
                    return Http::response([
                        'jsonrpc' => '2.0',
                        'id' => 1,
                        'result' => [
                            'content' => [[
                                'type' => 'text',
                                'text' => json_encode([
                                    'success' => true,
                                    'data' => [
                                        'tenant' => ['slug' => 'ispend', 'name' => 'Ispend'],
                                        'default_workspace' => ['id' => 'ws-backfill', 'name' => 'WS', 'slug' => 'ispend'],
                                    ],
                                ]),
                            ]],
                            'isError' => false,
                        ],
                    ]);
                }

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
                'answer' => 'Nueva respuesta',
                'confidence' => 90,
                'sources' => [],
            ]);
        });

        $question = $this->questionWithVersion('Respuesta original');

        $this->assertNull($question->repository->resolved_workspace_id);
        $question->repository->update(['credential' => ['api_key' => 'kfr_backfill']]);

        (new CheckQuestionUpdatesJob)->handle(
            app(KuaforiaService::class),
            app(StructuredSignalProviderInterface::class),
        );

        $data = $this->notificationData();

        // El workspace quedó persistido y las señales se generaron con él.
        $this->assertSame('ws-backfill', $question->repository->fresh()->resolved_workspace_id);
        $this->assertArrayHasKey('signals', $data);
        $this->assertSame('healthy', $data['signals']['workspace_health']['status']);

        // 1 llamada de contexto + 2 de señales, todas con la credencial del repo.
        $this->assertCount(3, $mcpCalls);
        $contextCalls = array_values(array_filter($mcpCalls, fn ($c) => $c['name'] === 'get_client_context'));
        $signalCalls = array_values(array_filter($mcpCalls, fn ($c) => $c['name'] !== 'get_client_context'));

        $this->assertCount(1, $contextCalls);
        $this->assertSame('Bearer kfr_backfill', $contextCalls[0]['auth']);

        $this->assertCount(2, $signalCalls);
        foreach ($signalCalls as $call) {
            $this->assertSame('Bearer kfr_backfill', $call['auth']);
            $this->assertSame('ws-backfill', $call['arguments']['workspace_id']);
        }
    }

    public function test_job_skips_signals_when_workspace_cannot_be_resolved(): void
    {
        // Repo sin resolved_workspace_id y un get_client_context que no trae
        // default_workspace (Kuaforia anterior a la extensión / tenant sin workspace):
        // backfill sin resultado → skip silencioso, cero llamadas a tools de señales.
        $signalToolCalls = [];

        Http::fake(function ($request) use (&$signalToolCalls) {
            if (str_contains($request->url(), '/api/v1/mcp')) {
                $body = $request->data();

                if (($body['params']['name'] ?? null) !== 'get_client_context') {
                    $signalToolCalls[] = $body['params']['name'] ?? null;
                }

                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'content' => [[
                            'type' => 'text',
                            'text' => json_encode([
                                'success' => true,
                                'data' => [
                                    'tenant' => ['slug' => 'ispend', 'name' => 'Ispend'],
                                ],
                            ]),
                        ]],
                        'isError' => false,
                    ],
                ]);
            }

            return Http::response([
                'answer' => 'Nueva respuesta',
                'confidence' => 90,
                'sources' => [],
            ]);
        });

        $question = $this->questionWithVersion('Respuesta original');

        (new CheckQuestionUpdatesJob)->handle(
            app(KuaforiaService::class),
            app(StructuredSignalProviderInterface::class),
        );

        $data = $this->notificationData();

        $this->assertArrayNotHasKey('signals', $data);
        $this->assertNull($question->repository->fresh()->resolved_workspace_id);
        $this->assertSame([], $signalToolCalls);
    }

    private function notificationData(): array
    {
        $notification = DB::table('notifications')
            ->where('type', AnswerChangedNotification::class)
            ->first();

        $this->assertNotNull($notification);

        return json_decode($notification->data, true);
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
