<?php

namespace Tests\Feature;

use App\Exceptions\KuaforiaException;
use App\Services\QbkContributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ola 3, Punto 1.1 — Fase B (B.3): cliente de evaluación de subconjunto contra
 * el payload real del contrato v1.8 §2.6 (validado con curl en Fase A).
 *
 * Lección aplicada del Punto 1: Http::fake() ACUMULA stubs y el primero matchea;
 * se usa UN fake por test que secuencia estados según el body del request.
 */
class EvaluarSubconjuntoTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION_ID = 64;

    private const NODOS = ['sandbox_64_c0_n0', 'sandbox_64_c0_n1', 'sandbox_64_c1_n0'];

    private QbkContributionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.qubeka.api_url' => 'http://localhost:8000/api/v1']);
        $this->service = app(QbkContributionService::class);
    }

    private function credential(): array
    {
        return ['api_token' => 'qk:1:test-token'];
    }

    /** Payload real confirmado en Fase A (sesión 64) para el caso combinado. */
    private function payloadCombinado(): array
    {
        return ['success' => true, 'data' => [
            'consecuencias' => [
                [
                    'tipo' => 'nodo_huerfano',
                    'nodos_afectados' => [
                        ['id' => 'sandbox_64_c0_n1', 'texto' => 'Sherlock Holmes es un personaje ficticio.'],
                        ['id' => 'sandbox_64_c0_n0', 'texto' => '¿Cuál es el origen del personaje?'],
                    ],
                    'descripcion' => '«Sherlock Holmes es un personaje ficticio.» (N-K) se aprobará sin su nodo padre y quedará como raíz del grafo.',
                ],
                [
                    'tipo' => 'enlace_perdido',
                    'nodos_afectados' => [
                        ['id' => 'sandbox_64_c0_n1', 'texto' => 'Sherlock Holmes es un personaje ficticio.'],
                        ['id' => 'sandbox_64_c0_n0', 'texto' => '¿Cuál es el origen del personaje?'],
                    ],
                    'descripcion' => 'El enlace no se recreará porque el padre quedó fuera de la selección.',
                ],
                [
                    'tipo' => 'sugerencia_no_resuelta',
                    'nodos_afectados' => [
                        ['id' => 'sandbox_64_c0_n1', 'texto' => 'Sherlock Holmes es un personaje ficticio.'],
                        ['id' => 'sandbox_64_c1_n0', 'texto' => 'Sherlock Holmes (repetido).'],
                    ],
                    'descripcion' => 'Ambos quedan en la selección: decidí cuál se promueve para no duplicar contenido.',
                ],
            ],
            'total' => 3,
            'subconjunto_evaluado' => 3,
        ]];
    }

    public function test_sin_consecuencias_devuelve_lista_vacia_y_total_cero(): void
    {
        Http::fake([
            'localhost:8000/api/v1/*' => Http::response([
                'success' => true,
                'data' => ['consecuencias' => [], 'total' => 0, 'subconjunto_evaluado' => 3],
            ], 200),
        ]);

        $result = $this->service->evaluarSubconjunto(self::SESSION_ID, self::NODOS, $this->credential());

        $this->assertSame([], $result['consecuencias']);
        $this->assertSame(0, $result['total']);
        $this->assertSame(3, $result['subconjunto_evaluado']);
    }

    public function test_nodo_huerfano_mapea_tipo_nodos_afectados_y_descripcion(): void
    {
        Http::fake([
            'localhost:8000/api/v1/*' => Http::response([
                'success' => true,
                'data' => [
                    'consecuencias' => [$this->payloadCombinado()['data']['consecuencias'][0]],
                    'total' => 1,
                    'subconjunto_evaluado' => 2,
                ],
            ], 200),
        ]);

        $result = $this->service->evaluarSubconjunto(self::SESSION_ID, self::NODOS, $this->credential());

        $this->assertCount(1, $result['consecuencias']);
        $c = $result['consecuencias'][0];
        $this->assertSame('nodo_huerfano', $c['tipo']);
        $this->assertCount(2, $c['nodos_afectados']);
        $this->assertSame('sandbox_64_c0_n1', $c['nodos_afectados'][0]['id']);
        $this->assertNotSame('', $c['descripcion']);
    }

    public function test_enlace_perdido_trae_ambos_extremos(): void
    {
        Http::fake([
            'localhost:8000/api/v1/*' => Http::response([
                'success' => true,
                'data' => [
                    'consecuencias' => [$this->payloadCombinado()['data']['consecuencias'][1]],
                    'total' => 1,
                    'subconjunto_evaluado' => 2,
                ],
            ], 200),
        ]);

        $result = $this->service->evaluarSubconjunto(self::SESSION_ID, self::NODOS, $this->credential());

        $c = $result['consecuencias'][0];
        $this->assertSame('enlace_perdido', $c['tipo']);
        $this->assertCount(2, $c['nodos_afectados'], 'NB5: ambos extremos del enlace');
    }

    public function test_sugerencia_no_resuelta(): void
    {
        Http::fake([
            'localhost:8000/api/v1/*' => Http::response([
                'success' => true,
                'data' => [
                    'consecuencias' => [$this->payloadCombinado()['data']['consecuencias'][2]],
                    'total' => 1,
                    'subconjunto_evaluado' => 2,
                ],
            ], 200),
        ]);

        $result = $this->service->evaluarSubconjunto(self::SESSION_ID, self::NODOS, $this->credential());

        $this->assertSame('sugerencia_no_resuelta', $result['consecuencias'][0]['tipo']);
    }

    public function test_caso_combinado_con_los_tres_tipos(): void
    {
        Http::fake([
            'localhost:8000/api/v1/*' => Http::response($this->payloadCombinado(), 200),
        ]);

        $result = $this->service->evaluarSubconjunto(self::SESSION_ID, self::NODOS, $this->credential());

        $this->assertCount(3, $result['consecuencias']);
        $this->assertSame(3, $result['total']);
        $this->assertSame(
            ['nodo_huerfano', 'enlace_perdido', 'sugerencia_no_resuelta'],
            array_column($result['consecuencias'], 'tipo'),
        );
    }

    public function test_envia_nodos_aprobados_en_el_body(): void
    {
        Http::fake([
            'localhost:8000/api/v1/*' => Http::response([
                'success' => true,
                'data' => ['consecuencias' => [], 'total' => 0, 'subconjunto_evaluado' => 3],
            ], 200),
        ]);

        $this->service->evaluarSubconjunto(self::SESSION_ID, self::NODOS, $this->credential());

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:8000/api/v1/sesiones-analisis/64/evaluar-subconjunto'
                && $request->hasHeader('Authorization', 'Bearer qk:1:test-token')
                && $request['nodos_aprobados'] === self::NODOS;
        });
    }

    public function test_error_422_propaga_el_mensaje_de_qubeka(): void
    {
        Http::fake([
            'localhost:8000/api/v1/*' => Http::response([
                'success' => false,
                'errors' => ['message' => 'nodos_aprobados contiene nodos que no pertenecen a la sesión: xyz'],
            ], 422),
        ]);

        try {
            $this->service->evaluarSubconjunto(self::SESSION_ID, ['xyz'], $this->credential());
            $this->fail('Se esperaba KuaforiaException');
        } catch (KuaforiaException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertStringContainsString('no pertenecen a la sesión', $e->getMessage());
        }
    }

    public function test_error_403(): void
    {
        Http::fake([
            'localhost:8000/api/v1/*' => Http::response([
                'success' => false, 'errors' => ['message' => 'No tienes permisos sobre esta sesión.'],
            ], 403),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionCode(403);

        $this->service->evaluarSubconjunto(self::SESSION_ID, self::NODOS, $this->credential());
    }

    public function test_error_404(): void
    {
        Http::fake([
            'localhost:8000/api/v1/*' => Http::response([
                'success' => false, 'errors' => ['message' => 'Sesión no encontrada.'],
            ], 404),
        ]);

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionCode(404);

        $this->service->evaluarSubconjunto(self::SESSION_ID, self::NODOS, $this->credential());
    }

    public function test_error_5xx_es_fallo_de_transporte(): void
    {
        Http::fake([
            'localhost:8000/api/v1/*' => Http::response(['success' => false], 500),
        ]);

        try {
            $this->service->evaluarSubconjunto(self::SESSION_ID, self::NODOS, $this->credential());
            $this->fail('Se esperaba KuaforiaException');
        } catch (KuaforiaException $e) {
            $this->assertSame(500, $e->getCode(), 'Fase C distingue transporte (5xx) de validación (422)');
        }
    }

    public function test_timeout_es_fallo_de_transporte(): void
    {
        Http::fake(function ($request) {
            Http::response(['success' => false], 500);

            throw new ConnectionException('cURL error 28: timeout');
        });

        $this->expectException(KuaforiaException::class);
        $this->expectExceptionCode(504);

        $this->service->evaluarSubconjunto(self::SESSION_ID, self::NODOS, $this->credential());
    }

    public function test_sin_credencial_lanza_error_legible(): void
    {
        $this->expectException(KuaforiaException::class);
        $this->expectExceptionMessage('sin token de agente');

        $this->service->evaluarSubconjunto(self::SESSION_ID, self::NODOS, null);
    }
}
