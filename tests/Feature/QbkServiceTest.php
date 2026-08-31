<?php

namespace Tests\Feature;

use App\Exceptions\KuaforiaException;
use App\Services\KuaforiaResponse;
use App\Services\QbkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QbkServiceTest extends TestCase
{
    use RefreshDatabase;

    private QbkService $service;

    private array $credential;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new QbkService;
        $this->credential = ['api_token' => 'qbk_test_token_abc123'];

        config(['services.qubeka.api_url' => 'http://mock-qubeka.test/api/v1']);

        // Clear circuit breaker state between tests.
        Cache::forget('qbk:paused');
        Cache::forget('qbk:failures');
    }

    public function test_consult_sends_correct_request_and_parses_response(): void
    {
        Http::fake([
            '*' => Http::response([
                'answer' => 'Laravel es un framework PHP',
                'confidence' => 0.5,
                'sources' => [
                    ['node_id' => 'NK-001', 'tipo' => 'N-K', 'texto_preview' => '...'],
                ],
                'found' => true,
            ]),
        ]);

        $response = $this->service->consult(
            '¿Qué es Laravel?',
            credential: $this->credential,
        );

        $this->assertInstanceOf(KuaforiaResponse::class, $response);
        $this->assertSame('Laravel es un framework PHP', $response->answerText);
        $this->assertSame(0.5, $response->confidence);
        $this->assertCount(1, $response->sources);
        $this->assertNull($response->conversationId);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/query')
                && $request->method() === 'POST'
                && $request->data()['question'] === '¿Qué es Laravel?'
                && $request->header('Authorization')[0] === 'Bearer qbk_test_token_abc123';
        });
    }

    public function test_consult_throws_on_401_without_triggering_circuit_breaker(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionCode(401);

        $this->service->consult('test', credential: $this->credential);

        // 401 no debe incrementar el contador de fallos.
        $this->assertNull(Cache::get('qbk:failures'));
    }

    public function test_consult_throws_on_503_and_increments_circuit_breaker(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Service unavailable'], 503),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionCode(503);

        $this->service->consult('test', credential: $this->credential);

        $this->assertSame(1, Cache::get('qbk:failures'));
    }

    public function test_consult_trips_circuit_breaker_after_3_failures(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Service unavailable'], 503),
        ]);

        // 3 fallos consecutivos.
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->service->consult('test', credential: $this->credential);
            } catch (KuaforiaException $e) {
                // Expected.
            }
        }

        $this->assertTrue(Cache::get('qbk:paused'));
    }

    public function test_consult_throws_when_paused_by_circuit_breaker(): void
    {
        Cache::put('qbk:paused', true, 60);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('QuBeKa en pausa temporal');

        $this->service->consult('test', credential: $this->credential);
    }

    public function test_consult_clears_failures_on_success(): void
    {
        Cache::put('qbk:failures', 2, 120);

        Http::fake([
            '*' => Http::response([
                'answer' => 'OK',
                'confidence' => 0.5,
                'sources' => [],
                'found' => true,
            ]),
        ]);

        $this->service->consult('test', credential: $this->credential);

        $this->assertNull(Cache::get('qbk:failures'));
    }

    public function test_consult_throws_when_credential_missing_token(): void
    {
        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('sin token de agente');

        $this->service->consult('test', credential: ['api_key' => 'wrong_key']);
    }

    public function test_consult_throws_when_credential_is_null(): void
    {
        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('sin token de agente');

        $this->service->consult('test', credential: []);
    }

    public function test_consult_handles_found_false_response(): void
    {
        Http::fake([
            '*' => Http::response([
                'answer' => 'No encontré información relevante',
                'confidence' => 0.0,
                'sources' => [],
                'found' => false,
            ]),
        ]);

        $response = $this->service->consult('test', credential: $this->credential);

        $this->assertSame('No encontré información relevante', $response->answerText);
        $this->assertSame(0.0, $response->confidence);
        $this->assertCount(0, $response->sources);
    }

    public function test_consult_handles_empty_answer(): void
    {
        Http::fake([
            '*' => Http::response([
                'answer' => '',
                'confidence' => 0.0,
                'sources' => [],
                'found' => false,
            ]),
        ]);

        $response = $this->service->consult('test', credential: $this->credential);

        $this->assertSame('', $response->answerText);
    }

    public function test_consult_sends_only_question_in_body_no_workspace_id(): void
    {
        Http::fake([
            '*' => Http::response([
                'answer' => 'OK',
                'confidence' => 0.5,
                'sources' => [],
                'found' => true,
            ]),
        ]);

        $this->service->consult('¿Qué es RAG?', credential: $this->credential);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $data['question'] === '¿Qué es RAG?'
                && ! isset($data['workspace_id']);
        });
    }

    public function test_consult_conversation_id_is_always_null(): void
    {
        Http::fake([
            '*' => Http::response([
                'answer' => 'OK',
                'confidence' => 0.5,
                'sources' => [],
                'found' => true,
                'conversation_id' => 'conv-123', // QBK no lo usa, pero por si acaso.
            ]),
        ]);

        $response = $this->service->consult('test', credential: $this->credential);

        $this->assertNull($response->conversationId);
    }
}
