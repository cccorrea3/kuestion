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

    // ------------------------------------------------------------------
    // getSession tests (Punto 4 — Fase 1)
    // ------------------------------------------------------------------

    public function test_get_session_returns_detail(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42' => Http::response([
                'success' => true,
                'data' => [
                    'session_id' => 42,
                    'status' => 'lista_para_revision',
                    'is_simple' => true,
                    'pregunta_previa' => '¿Por qué falla el job?',
                    'nodes' => [
                        ['id' => 'sandbox_1', 'tipo' => 'H', 'texto' => 'El batch no llega antes de las 6am', 'relaciones' => []],
                    ],
                    'resumen' => 'Se propuso 1 hipótesis.',
                    'created_at' => '2026-08-29T10:30:00Z',
                    'workspace_nombre' => 'Investigación Jurídica',
                ],
            ], 200),
        ]);

        $result = $this->service->getSession(42, $this->credential);

        $this->assertSame(42, $result['session_id']);
        $this->assertSame('lista_para_revision', $result['status']);
        $this->assertTrue($result['is_simple']);
        $this->assertSame('¿Por qué falla el job?', $result['pregunta_previa']);
        $this->assertCount(1, $result['nodes']);
        $this->assertSame('sandbox_1', $result['nodes'][0]['id']);
        $this->assertSame('Investigación Jurídica', $result['workspace_nombre']);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:8000/api/v1/sesiones-analisis/42'
                && $request->method() === 'GET';
        });
    }

    public function test_get_session_handles_complex_session(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/99' => Http::response([
                'success' => true,
                'data' => [
                    'session_id' => 99,
                    'status' => 'lista_para_revision',
                    'is_simple' => false,
                    'pregunta_previa' => null,
                    'nodes' => [],
                    'resumen' => '',
                    'created_at' => null,
                    'workspace_nombre' => '',
                ],
            ], 200),
        ]);

        $result = $this->service->getSession(99, $this->credential);

        $this->assertFalse($result['is_simple']);
        $this->assertNull($result['pregunta_previa']);
    }

    public function test_get_session_throws_on_401(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42' => Http::response([
                'success' => false,
                'error' => 'Invalid token',
            ], 401),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('token de QuBeKa es inválido');
        $this->expectExceptionCode(401);

        $this->service->getSession(42, $this->credential);
    }

    public function test_get_session_throws_on_404(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/999' => Http::response([
                'success' => false,
                'error' => 'Session not found',
            ], 404),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('no encontrada');
        $this->expectExceptionCode(404);

        $this->service->getSession(999, $this->credential);
    }

    public function test_get_session_throws_on_500(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42' => Http::response('Server Error', 500),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('respondió con error: 500');
        $this->expectExceptionCode(500);

        $this->service->getSession(42, $this->credential);
    }

    public function test_get_session_throws_on_timeout(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('tardó demasiado');
        $this->expectExceptionCode(504);

        $this->service->getSession(42, $this->credential);
    }

    public function test_get_session_throws_without_token(): void
    {
        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('sin token de agente');

        $this->service->getSession(42, ['api_token' => '']);
    }

    public function test_get_session_handles_missing_envelope(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42' => Http::response([
                'session_id' => 42,
                'status' => 'lista_para_revision',
                'is_simple' => true,
                'nodes' => [],
            ], 200),
        ]);

        $result = $this->service->getSession(42, $this->credential);

        $this->assertSame(42, $result['session_id']);
        $this->assertTrue($result['is_simple']);
    }

    // ------------------------------------------------------------------
    // approve tests (Punto 4 — Fase 1)
    // ------------------------------------------------------------------

    public function test_approve_without_textos_ajustados(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42/approve' => Http::response([
                'success' => true,
                'session_id' => 42,
                'status' => 'promocionada',
                'nodos_creados' => 2,
                'enlaces_creados' => 1,
            ], 200),
        ]);

        $result = $this->service->approve(42, null, $this->credential);

        $this->assertTrue($result['success']);
        $this->assertSame(42, $result['session_id']);
        $this->assertSame('promocionada', $result['status']);
        $this->assertSame(2, $result['nodos_creados']);
        $this->assertSame(1, $result['enlaces_creados']);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:8000/api/v1/sesiones-analisis/42/approve'
                && $request->method() === 'POST'
                && $request->data() === [];
        });
    }

    public function test_approve_with_textos_ajustados(): void
    {
        $ajustes = [
            'sandbox_1' => 'Texto ajustado de la hipótesis',
            'sandbox_2' => 'Texto ajustado de la nota',
        ];

        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42/approve' => Http::response([
                'success' => true,
                'session_id' => 42,
                'status' => 'promocionada',
                'nodos_creados' => 2,
                'enlaces_creados' => 1,
            ], 200),
        ]);

        $result = $this->service->approve(42, $ajustes, $this->credential);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['nodos_creados']);

        Http::assertSent(function ($request) use ($ajustes) {
            return $request->data()['textos_ajustados'] === $ajustes;
        });
    }

    public function test_approve_throws_on_401(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42/approve' => Http::response([
                'success' => false,
                'error' => 'Invalid token',
            ], 401),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('token de QuBeKa es inválido');
        $this->expectExceptionCode(401);

        $this->service->approve(42, null, $this->credential);
    }

    public function test_approve_throws_on_403(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42/approve' => Http::response([
                'success' => false,
                'error' => 'Forbidden',
            ], 403),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('permisos para aprobar');
        $this->expectExceptionCode(403);

        $this->service->approve(42, null, $this->credential);
    }

    public function test_approve_throws_on_404(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/999/approve' => Http::response([
                'success' => false,
                'error' => 'Not found',
            ], 404),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('no encontrada');
        $this->expectExceptionCode(404);

        $this->service->approve(999, null, $this->credential);
    }

    public function test_approve_throws_on_500(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42/approve' => Http::response('Server Error', 500),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('respondió con error: 500');
        $this->expectExceptionCode(500);

        $this->service->approve(42, null, $this->credential);
    }

    public function test_approve_throws_on_timeout(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42/approve' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('tardó demasiado');
        $this->expectExceptionCode(504);

        $this->service->approve(42, null, $this->credential);
    }

    public function test_approve_throws_without_token(): void
    {
        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('sin token de agente');

        $this->service->approve(42, null, ['api_token' => '']);
    }

    // ------------------------------------------------------------------
    // reject tests (Punto 4 — Fase 1)
    // ------------------------------------------------------------------

    public function test_reject_returns_success(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42/reject' => Http::response([
                'success' => true,
                'session_id' => 42,
                'status' => 'rechazada',
            ], 200),
        ]);

        $result = $this->service->reject(42, $this->credential);

        $this->assertTrue($result['success']);
        $this->assertSame(42, $result['session_id']);
        $this->assertSame('rechazada', $result['status']);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:8000/api/v1/sesiones-analisis/42/reject'
                && $request->method() === 'POST';
        });
    }

    public function test_reject_throws_on_401(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42/reject' => Http::response([
                'success' => false,
                'error' => 'Invalid token',
            ], 401),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('token de QuBeKa es inválido');
        $this->expectExceptionCode(401);

        $this->service->reject(42, $this->credential);
    }

    public function test_reject_throws_on_403(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42/reject' => Http::response([
                'success' => false,
                'error' => 'Forbidden',
            ], 403),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('permisos para rechazar');
        $this->expectExceptionCode(403);

        $this->service->reject(42, $this->credential);
    }

    public function test_reject_throws_on_404(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/999/reject' => Http::response([
                'success' => false,
                'error' => 'Not found',
            ], 404),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('no encontrada');
        $this->expectExceptionCode(404);

        $this->service->reject(999, $this->credential);
    }

    public function test_reject_throws_on_500(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42/reject' => Http::response('Server Error', 500),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('respondió con error: 500');
        $this->expectExceptionCode(500);

        $this->service->reject(42, $this->credential);
    }

    public function test_reject_throws_on_timeout(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42/reject' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('tardó demasiado');
        $this->expectExceptionCode(504);

        $this->service->reject(42, $this->credential);
    }

    public function test_reject_throws_without_token(): void
    {
        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('sin token de agente');

        $this->service->reject(42, ['api_token' => '']);
    }
}
