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

    public function test_job_enriches_notification_with_signals_when_workspace_mapped(): void
    {
        config(['services.kuaforia.workspace_map' => ['ispend' => 'ws-1']]);

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
        config(['services.kuaforia.workspace_map' => ['ispend' => 'ws-1']]);

        Http::fake([
            '*/consult*' => Http::response([
                'answer' => 'Nueva respuesta',
                'confidence' => 90,
                'sources' => [],
            ]),
            '*/api/v1/mcp' => Http::response([], 500),
        ]);

        $question = $this->questionWithVersion('Respuesta original');

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
        // E2 — Sistema de Conectores: con `resolved_workspace_id` persistido (P3), las
        // señales usan el workspace del repo y su credencial, no el fallback workspace_map
        // ni la key global. workspace_map queda configurado con OTRO workspace para
        // demostrar que el repo tiene prioridad.
        config(['services.kuaforia.workspace_map' => ['ispend' => 'ws-map']]);

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

    public function test_job_skips_signals_without_workspace_map(): void
    {
        // workspace_map vacío (default): skip silencioso, cero llamadas al puente MCP.
        Http::fake([
            '*/consult*' => Http::response([
                'answer' => 'Nueva respuesta',
                'confidence' => 90,
                'sources' => [],
            ]),
        ]);

        $this->questionWithVersion('Respuesta original');

        (new CheckQuestionUpdatesJob)->handle(
            app(KuaforiaService::class),
            app(StructuredSignalProviderInterface::class),
        );

        $data = $this->notificationData();

        $this->assertArrayNotHasKey('signals', $data);
        Http::assertSentCount(1); // solo la consulta REST, ninguna llamada MCP.
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
