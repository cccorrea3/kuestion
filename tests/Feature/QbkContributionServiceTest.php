<?php

namespace Tests\Feature;

use App\Exceptions\KuaforiaException;
use App\Services\QbkContributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QbkContributionServiceTest extends TestCase
{
    use RefreshDatabase;

    private QbkContributionService $service;

    private array $credential;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new QbkContributionService;
        $this->credential = ['api_token' => '2|test_token_123'];

        config(['services.qubeka.api_url' => 'http://localhost:8000/api/v1']);
    }

    public function test_successful_contribution(): void
    {
        Http::fake([
            'localhost:8000/api/v1/contribute' => Http::response([
                'success' => true,
                'data' => [
                    'session_id' => 42,
                    'status' => 'pendiente_revision',
                    'resumen' => 'Se propuso 1 hipótesis, pendiente de revisión.',
                ],
            ], 200),
        ]);

        $result = $this->service->contribute(
            texto: 'El batch del banco no llega antes de las 6am',
            credential: $this->credential,
        );

        $this->assertSame(42, $result['session_id']);
        $this->assertSame('pendiente_revision', $result['status']);
        $this->assertSame('Se propuso 1 hipótesis, pendiente de revisión.', $result['resumen']);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:8000/api/v1/contribute'
                && $request->data()['texto'] === 'El batch del banco no llega antes de las 6am'
                && $request->data()['origen'] === 'kuestion'
                && ! array_key_exists('pregunta_previa', $request->data());
        });
    }

    public function test_sends_pregunta_previa_when_provided(): void
    {
        Http::fake([
            'localhost:8000/api/v1/contribute' => Http::response([
                'success' => true,
                'data' => [
                    'session_id' => 43,
                    'status' => 'pendiente_revision',
                    'resumen' => 'OK',
                ],
            ], 200),
        ]);

        $result = $this->service->contribute(
            texto: 'El batch del banco no llega antes de las 6am',
            preguntaPrevia: '¿Por qué falla el job?',
            credential: $this->credential,
        );

        $this->assertSame(43, $result['session_id']);

        Http::assertSent(function ($request) {
            return $request->data()['pregunta_previa'] === '¿Por qué falla el job?'
                && $request->data()['origen'] === 'kuestion';
        });
    }

    public function test_omits_pregunta_previa_when_null(): void
    {
        Http::fake([
            'localhost:8000/api/v1/contribute' => Http::response([
                'success' => true,
                'data' => [
                    'session_id' => 44,
                    'status' => 'pendiente_revision',
                    'resumen' => 'OK',
                ],
            ], 200),
        ]);

        $this->service->contribute(
            texto: 'El batch del banco no llega antes de las 6am',
            preguntaPrevia: null,
            credential: $this->credential,
        );

        Http::assertSent(function ($request) {
            return ! array_key_exists('pregunta_previa', $request->data());
        });
    }

    public function test_omits_pregunta_previa_when_empty_string(): void
    {
        Http::fake([
            'localhost:8000/api/v1/contribute' => Http::response([
                'success' => true,
                'data' => [
                    'session_id' => 45,
                    'status' => 'pendiente_revision',
                    'resumen' => 'OK',
                ],
            ], 200),
        ]);

        $this->service->contribute(
            texto: 'El batch del banco no llega antes de las 6am',
            preguntaPrevia: '',
            credential: $this->credential,
        );

        Http::assertSent(function ($request) {
            return ! array_key_exists('pregunta_previa', $request->data());
        });
    }

    public function test_throws_on_401(): void
    {
        Http::fake([
            'localhost:8000/api/v1/contribute' => Http::response([
                'success' => false,
                'error' => 'Invalid token',
            ], 401),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('token de QuBeKa es inválido');
        $this->expectExceptionCode(401);

        $this->service->contribute(
            texto: 'El batch del banco no llega antes de las 6am',
            credential: $this->credential,
        );
    }

    public function test_throws_on_403(): void
    {
        Http::fake([
            'localhost:8000/api/v1/contribute' => Http::response([
                'success' => false,
                'error' => 'Insufficient abilities',
            ], 403),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('permiso de escritura');
        $this->expectExceptionCode(403);

        $this->service->contribute(
            texto: 'El batch del banco no llega antes de las 6am',
            credential: $this->credential,
        );
    }

    public function test_throws_on_500(): void
    {
        Http::fake([
            'localhost:8000/api/v1/contribute' => Http::response('Server Error', 500),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('respondió con error: 500');
        $this->expectExceptionCode(500);

        $this->service->contribute(
            texto: 'El batch del banco no llega antes de las 6am',
            credential: $this->credential,
        );
    }

    public function test_throws_on_422(): void
    {
        Http::fake([
            'localhost:8000/api/v1/contribute' => Http::response([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => ['texto' => ['The texto field is required.']],
            ], 422),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('respondió con error: 422');

        $this->service->contribute(
            texto: 'El batch del banco no llega antes de las 6am',
            credential: $this->credential,
        );
    }

    public function test_throws_on_timeout(): void
    {
        Http::fake([
            'localhost:8000/api/v1/contribute' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('tardó demasiado');
        $this->expectExceptionCode(504);

        $this->service->contribute(
            texto: 'El batch del banco no llega antes de las 6am',
            credential: $this->credential,
        );
    }

    public function test_throws_without_token(): void
    {
        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('sin token de agente');

        $this->service->contribute(
            texto: 'El batch del banco no llega antes de las 6am',
            credential: ['api_token' => ''],
        );
    }

    public function test_throws_with_null_credential(): void
    {
        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('sin token de agente');

        $this->service->contribute(
            texto: 'El batch del banco no llega antes de las 6am',
            credential: null,
        );
    }

    public function test_handles_missing_fields_in_response(): void
    {
        Http::fake([
            'localhost:8000/api/v1/contribute' => Http::response([
                'success' => true,
                'data' => [],
            ], 200),
        ]);

        $result = $this->service->contribute(
            texto: 'El batch del banco no llega antes de las 6am',
            credential: $this->credential,
        );

        // Should use defaults when fields are missing.
        $this->assertSame(0, $result['session_id']);
        $this->assertSame('desconocido', $result['status']);
        $this->assertSame('Tu aporte quedó registrado.', $result['resumen']);
    }

    public function test_handles_response_without_data_envelope(): void
    {
        Http::fake([
            'localhost:8000/api/v1/contribute' => Http::response([
                'session_id' => 50,
                'status' => 'pendiente_revision',
                'resumen' => 'Directo sin wrapper.',
            ], 200),
        ]);

        $result = $this->service->contribute(
            texto: 'El batch del banco no llega antes de las 6am',
            credential: $this->credential,
        );

        $this->assertSame(50, $result['session_id']);
        $this->assertSame('Directo sin wrapper.', $result['resumen']);
    }

    public function test_sends_correct_headers(): void
    {
        Http::fake([
            'localhost:8000/api/v1/contribute' => Http::response([
                'success' => true,
                'data' => [
                    'session_id' => 1,
                    'status' => 'pendiente_revision',
                    'resumen' => 'OK',
                ],
            ], 200),
        ]);

        $this->service->contribute(
            texto: 'Test contribution',
            credential: $this->credential,
        );

        Http::assertSent(function ($request) {
            return str_contains($request->header('Authorization')[0] ?? '', 'Bearer 2|test_token_123')
                && $request->header('Content-Type')[0] === 'application/json';
        });
    }
}
