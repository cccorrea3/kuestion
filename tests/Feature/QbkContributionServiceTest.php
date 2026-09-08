<?php

namespace Tests\Feature;

use App\Exceptions\KuaforiaException;
use App\Services\QbkContributionService;
use App\Services\QbkService;
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

    // ------------------------------------------------------------------
    // reconfirmarNodo tests (Ola 2, Punto 2 — Fase A, checklist FA)
    // ------------------------------------------------------------------

    public function test_reconfirmar_nodo_success(): void
    {
        Http::fake([
            'localhost:8000/api/v1/nodos/NK-001/reconfirmar' => Http::response([
                'success' => true,
                'data' => [
                    'node_id' => 'NK-001',
                    'fecha_ultima_confirmacion' => '2026-09-08T15:00:00+00:00',
                    'ultimo_confirmador_id' => null,
                ],
            ], 200),
        ]);

        $result = $this->service->reconfirmarNodo('NK-001', $this->credential);

        $this->assertSame('NK-001', $result['node_id']);
        $this->assertSame('2026-09-08T15:00:00+00:00', $result['fecha_ultima_confirmacion']);
        $this->assertNull($result['ultimo_confirmador_id']);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:8000/api/v1/nodos/NK-001/reconfirmar'
                && $request->method() === 'PATCH';
        });
    }

    public function test_reconfirmar_nodo_throws_on_401(): void
    {
        Http::fake([
            'localhost:8000/api/v1/nodos/NK-001/reconfirmar' => Http::response([
                'success' => false,
                'errors' => ['message' => 'Token de autenticación inválido.'],
            ], 401),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('token de QuBeKa es inválido');
        $this->expectExceptionCode(401);

        $this->service->reconfirmarNodo('NK-001', $this->credential);
    }

    public function test_reconfirmar_nodo_throws_on_403(): void
    {
        Http::fake([
            'localhost:8000/api/v1/nodos/NK-001/reconfirmar' => Http::response([
                'success' => false,
                'errors' => ['message' => 'No tienes permisos de revisión sobre este workspace.'],
            ], 403),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('No tenés permiso para reconfirmar este conocimiento');
        $this->expectExceptionCode(403);

        $this->service->reconfirmarNodo('NK-001', $this->credential);
    }

    public function test_reconfirmar_nodo_throws_on_404(): void
    {
        Http::fake([
            'localhost:8000/api/v1/nodos/NK-001/reconfirmar' => Http::response([
                'success' => false,
                'errors' => ['message' => 'Nodo no disponible.'],
            ], 404),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('ya no está disponible para reconfirmar');
        $this->expectExceptionCode(404);

        $this->service->reconfirmarNodo('NK-001', $this->credential);
    }

    public function test_reconfirmar_nodo_throws_on_500(): void
    {
        Http::fake([
            'localhost:8000/api/v1/nodos/NK-001/reconfirmar' => Http::response('Server Error', 500),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('respondió con error: 500');
        $this->expectExceptionCode(500);

        $this->service->reconfirmarNodo('NK-001', $this->credential);
    }

    public function test_reconfirmar_nodo_throws_on_timeout(): void
    {
        Http::fake([
            'localhost:8000/api/v1/nodos/NK-001/reconfirmar' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('tardó demasiado');
        $this->expectExceptionCode(504);

        $this->service->reconfirmarNodo('NK-001', $this->credential);
    }

    public function test_reconfirmar_nodo_throws_without_token(): void
    {
        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('sin token de agente');

        $this->service->reconfirmarNodo('NK-001', ['api_token' => '']);
    }

    /**
     * FA.6 — /query con contrato viejo (sin fecha_ultima_confirmacion en sources[])
     * no rompe: el campo simplemente no existe en sources y la lógica de vigencia
     * degrada a 'sin_dato' (fallback copy honesto P5/6).
     */
    public function test_consult_with_legacy_sources_without_fecha_ultima_confirmacion(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => true,
                'data' => [
                    'answer' => 'Respuesta OK',
                    'confidence' => 0.8,
                    'sources' => [
                        ['node_id' => 'NK-001', 'tipo' => 'N-K', 'estado_validacion' => 'validado'],
                        ['node_id' => 'NK-002', 'tipo' => 'N-K', 'estado_validacion' => 'validado'],
                    ],
                    'found' => true,
                ],
            ], 200),
        ]);

        $response = (new QbkService)->consult('test', credential: $this->credential);

        $this->assertSame('Respuesta OK', $response->answerText);
        $this->assertCount(2, $response->sources);
        $this->assertArrayNotHasKey('fecha_ultima_confirmacion', $response->sources[0]);
    }

    public function test_consult_with_fecha_ultima_confirmacion_in_sources(): void
    {
        // Extensión aditiva del contrato (§5.2): el campo viaja dentro de sources
        // sin procesamiento extra — QbkService pasa sources tal cual (FA.6/FA.7).
        Http::fake([
            '*' => Http::response([
                'success' => true,
                'data' => [
                    'answer' => 'Respuesta OK',
                    'confidence' => 0.8,
                    'sources' => [
                        [
                            'node_id' => 'NK-001',
                            'tipo' => 'N-K',
                            'estado_validacion' => 'validado',
                            'fecha_ultima_confirmacion' => '2026-09-01T10:00:00+00:00',
                            'ultimo_confirmador_nombre' => 'Kuestion (conector)',
                        ],
                    ],
                    'found' => true,
                ],
            ], 200),
        ]);

        $response = (new QbkService)->consult('test', credential: $this->credential);

        $this->assertSame('2026-09-01T10:00:00+00:00', $response->sources[0]['fecha_ultima_confirmacion']);
        $this->assertSame('Kuestion (conector)', $response->sources[0]['ultimo_confirmador_nombre']);
    }

    // ------------------------------------------------------------------
    // listSessions tests (Ola 2, Punto 1 — Fase A)
    // ------------------------------------------------------------------

    public function test_list_sessions_returns_normalized_items(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis*' => Http::response([
                'success' => true,
                'data' => [
                    'items' => [
                        [
                            'session_id' => 42,
                            'status' => 'lista_para_revision',
                            'creado_en' => '2026-08-29T10:30:00Z',
                            'contenido_entrada' => 'El batch del banco no llega antes de las 6am',
                            'resumen' => 'Se propuso 1 hipótesis, pendiente de revisión.',
                            'is_simple' => true,
                            'pregunta_previa' => '¿Por qué falla el job?',
                            'autor_email' => 'juan@proteam.cl',
                            'autor_nombre' => 'Juan Pérez',
                            'cerrado_en' => null,
                        ],
                    ],
                    'total' => 1,
                ],
            ], 200),
        ]);

        $result = $this->service->listSessions(
            page: 1,
            perPage: 20,
            estado: 'pendientes',
            credential: $this->credential,
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']['items']);
        $item = $result['data']['items'][0];

        $this->assertSame(42, $item['session_id']);
        $this->assertSame('lista_para_revision', $item['status']);
        $this->assertSame('2026-08-29T10:30:00Z', $item['fecha_creacion']);
        $this->assertSame('El batch del banco no llega antes de las 6am', $item['texto_original_del_aporte']);
        $this->assertSame('Se propuso 1 hipótesis, pendiente de revisión.', $item['resumen_clasificacion']);
        $this->assertTrue($item['is_simple']);
        $this->assertSame('¿Por qué falla el job?', $item['pregunta_previa']);
        $this->assertSame('juan@proteam.cl', $item['autor_email']);
        $this->assertSame('Juan Pérez', $item['autor_nombre']);
        $this->assertNull($item['fecha_decision']);
        $this->assertSame(1, $result['data']['total']);
        $this->assertSame(1, $result['data']['page']);
        $this->assertSame(20, $result['data']['per_page']);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'http://localhost:8000/api/v1/sesiones-analisis')
                && $request->method() === 'GET'
                && $request->data()['estado'] === 'pendientes'
                && $request->data()['page'] === 1
                && $request->data()['per_page'] === 20;
        });
    }

    public function test_list_sessions_normalizes_legacy_field_names(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis*' => Http::response([
                'success' => true,
                'data' => [
                    'items' => [
                        [
                            'session_id' => 99,
                            'status' => 'aprobada',
                            'creado_en' => '2026-09-05T08:00:00Z',
                            'contenido_entrada' => 'Texto original legacy',
                            'resumen' => 'Resumen legacy',
                            'is_simple' => false,
                            'pregunta_previa' => null,
                            'autor_email' => null,
                            'autor_nombre' => null,
                            'cerrado_en' => '2026-09-05T08:05:00Z',
                        ],
                    ],
                    'total' => 1,
                ],
            ], 200),
        ]);

        $result = $this->service->listSessions(estado: 'historial', credential: $this->credential);

        $item = $result['data']['items'][0];

        // Los nombres reales del contrato se miran igual en el formato interno.
        $this->assertSame('2026-09-05T08:00:00Z', $item['fecha_creacion']);
        $this->assertSame('Texto original legacy', $item['texto_original_del_aporte']);
        $this->assertSame('Resumen legacy', $item['resumen_clasificacion']);
        $this->assertSame('2026-09-05T08:05:00Z', $item['fecha_decision']);
    }

    public function test_list_sessions_accepts_legacy_dataset_with_different_keys(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis*' => Http::response([
                'success' => true,
                'data' => [
                    'items' => [
                        [
                            'id' => 123,
                            'estado' => 'rechazada',
                            'fecha_creacion' => '2026-09-10T12:00:00Z',
                            'texto_original' => 'Texto legacy alternativo',
                            'clasificacion_resumen' => 'Resumen alternativo',
                            'es_compleja' => true,
                            'pregunta' => 'Pregunta previa alternativa',
                            'email_autor' => 'marta@proteam.cl',
                            'nombre_autor' => 'Marta Gómez',
                            'fecha_decision' => '2026-09-10T12:02:00Z',
                        ],
                    ],
                    'total' => 1,
                ],
            ], 200),
        ]);

        $result = $this->service->listSessions(estado: 'historial', credential: $this->credential);

        $item = $result['data']['items'][0];

        // Cuando QuBeKa usa nombres totalmente distintos, el servicio usa los default
        // del mapeo interno (session_id, status, is_simple) y null en los campos
        // sin equivalente (fecha_creacion, texto_original_del_aporte, etc.).
        // Esto es aceptable para el contrato mínimo y se documenta en el plan D1.
        // Nota: si el array legacy tiene la key 'fecha_creacion', el servicio la normaliza
        // porque el mapeo la busca primero — no es la intención del test medir eso, pero
        // refleja el comportamiento real del normalizeSessionItems.
        $this->assertSame(0, $item['session_id']);
        $this->assertSame('desconocido', $item['status']);
        $this->assertFalse($item['is_simple']);
        $this->assertSame('2026-09-10T12:00:00Z', $item['fecha_creacion']);
        $this->assertNull($item['texto_original_del_aporte']);
    }

    public function test_list_sessions_compacts_pagination(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis*' => Http::response([
                'success' => true,
                'data' => [
                    'items' => [],
                    'total' => 0,
                ],
            ], 200),
        ]);

        $result = $this->service->listSessions(page: 2, perPage: 200, credential: $this->credential);

        Http::assertSent(function ($request) {
            return $request->data()['per_page'] === 100;
        });

        $this->assertSame(100, $result['data']['per_page']);
    }

    public function test_list_sessions_default_estado_is_pendientes(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis*' => Http::response([
                'success' => true,
                'data' => [
                    'items' => [],
                    'total' => 0,
                ],
            ], 200),
        ]);

        $this->service->listSessions(credential: $this->credential);

        Http::assertSent(function ($request) {
            return $request->data()['estado'] === 'pendientes';
        });
    }

    public function test_list_sessions_throws_on_401(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis*' => Http::response([
                'success' => false,
                'errors' => ['message' => 'Invalid token'],
            ], 401),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('token de QuBeKa es inválido');
        $this->expectExceptionCode(401);

        $this->service->listSessions(credential: $this->credential);
    }

    public function test_list_sessions_throws_on_403(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis*' => Http::response([
                'success' => false,
                'errors' => ['message' => 'Forbidden'],
            ], 403),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('permiso de lectura');
        $this->expectExceptionCode(403);

        $this->service->listSessions(credential: $this->credential);
    }

    public function test_list_sessions_throws_on_500(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis*' => Http::response('Server Error', 500),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('respondió con error: 500');
        $this->expectExceptionCode(500);

        $this->service->listSessions(credential: $this->credential);
    }

    public function test_list_sessions_throws_on_timeout(): void
    {
        // El * es necesario: la URL real lleva query string (?estado=&page=...)
        // y sin él el request no se fakea (se escapa al servicio real si está arriba).
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis*' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('tardó demasiado');
        $this->expectExceptionCode(504);

        $this->service->listSessions(credential: $this->credential);
    }

    public function test_list_sessions_throws_without_token(): void
    {
        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('sin token de agente');

        $this->service->listSessions(credential: ['api_token' => '']);
    }

    public function test_list_sessions_throws_with_null_credential(): void
    {
        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('sin token de agente');

        $this->service->listSessions(credential: null);
    }

    public function test_list_sessions_parses_real_qubeka_shape(): void
    {
        // QuBeKa real (helper ApiResponse::paginated) devuelve data = arreglo plano
        // de ítems y la paginación en meta — NO data.items/data.total.
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'session_id' => 42,
                        'status' => 'lista_para_revision',
                        'creado_en' => '2026-08-29T10:30:00Z',
                        'contenido_entrada' => 'El batch del banco no llega antes de las 6am',
                        'resumen' => 'Se propuso 1 hipótesis, pendiente de revisión.',
                        'is_simple' => true,
                        'pregunta_previa' => '¿Por qué falla el job?',
                        'autor_email' => 'juan@proteam.cl',
                        'autor_nombre' => 'Juan Pérez',
                        'cerrado_en' => null,
                    ],
                ],
                'meta' => ['page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1],
            ], 200),
        ]);

        $result = $this->service->listSessions(
            page: 2,
            perPage: 30,
            estado: 'pendientes',
            credential: $this->credential,
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']['items']);
        $this->assertSame(1, $result['data']['total']);
        $this->assertSame(2, $result['data']['page']);
        $this->assertSame(30, $result['data']['per_page']);

        $item = $result['data']['items'][0];
        $this->assertSame(42, $item['session_id']);
        $this->assertSame('lista_para_revision', $item['status']);
        $this->assertSame('2026-08-29T10:30:00Z', $item['fecha_creacion']);
        $this->assertSame('El batch del banco no llega antes de las 6am', $item['texto_original_del_aporte']);
        $this->assertSame('Se propuso 1 hipótesis, pendiente de revisión.', $item['resumen_clasificacion']);
        $this->assertTrue($item['is_simple']);
        $this->assertSame('¿Por qué falla el job?', $item['pregunta_previa']);
        $this->assertSame('Juan Pérez', $item['autor_nombre']);
    }

    public function test_list_sessions_empty_flat_data(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis*' => Http::response([
                'success' => true,
                'data' => [],
                'meta' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 0],
            ], 200),
        ]);

        $result = $this->service->listSessions(credential: $this->credential);

        $this->assertCount(0, $result['data']['items']);
        $this->assertSame(0, $result['data']['total']);
    }

    public function test_list_sessions_empty_items(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis*' => Http::response([
                'success' => true,
                'data' => [
                    'items' => [],
                    'total' => 0,
                ],
            ], 200),
        ]);

        $result = $this->service->listSessions(credential: $this->credential);

        $this->assertCount(0, $result['data']['items']);
        $this->assertSame(0, $result['data']['total']);
    }

    public function test_list_sessions_handles_missing_data_envelope(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis*' => Http::response([
                'items' => [
                    [
                        'session_id' => 7,
                        'status' => 'lista_para_revision',
                        'creado_en' => '2026-09-12T09:00:00Z',
                        'contenido_entrada' => 'Texto',
                        'resumen' => 'Resumen',
                        'is_simple' => true,
                    ],
                ],
                'total' => 1,
            ], 200),
        ]);

        $result = $this->service->listSessions(credential: $this->credential);

        $this->assertCount(1, $result['data']['items']);
        $this->assertSame(7, $result['data']['items'][0]['session_id']);
        $this->assertSame(1, $result['data']['total']);
    }
}
