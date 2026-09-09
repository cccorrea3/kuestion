<?php

namespace Tests\Feature;

use App\Services\Explicacion\ExplicacionNormalizer;
use App\Services\Explicacion\ExplicacionPresenter;
use App\Services\QbkContributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ola 2, Punto 4 — Fase A (parsing) y Fase B (presentador).
 * Checklists FA y FB del plan (sección 3).
 */
class ExplicabilidadTest extends TestCase
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

    /**
     * Fixture §5.3 del contrato (forma real de QuBeKa, verificada en código).
     */
    private function explicacionFixture(): array
    {
        return [
            'decision_type' => 'H',
            'confidence' => 0.85,
            'reasons' => [
                'El texto usa "porque el batch no llega", señal de causa declarada → hipótesis contrastable.',
            ],
            'alternatives_considered' => [
                ['type' => 'N-K', 'reason' => 'No cita fuente verificable'],
            ],
            'detected_patterns' => ['causa_declarada', 'afirmacion_sin_fuente'],
        ];
    }

    // ─── FA — Parsing (Fase A) ───

    public function test_fa1_get_session_normalizes_full_metadata_per_node(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42' => Http::response([
                'success' => true,
                'data' => [
                    'session_id' => 42,
                    'status' => 'lista_para_revision',
                    'is_simple' => true,
                    'pregunta_previa' => null,
                    'nodes' => [
                        ['id' => 'sandbox_1', 'tipo' => 'H', 'texto' => 'El batch no llega', 'relaciones' => [], 'explicacion' => $this->explicacionFixture()],
                    ],
                    'resumen' => 'Se propuso 1 hipótesis.',
                    'created_at' => '2026-09-01T10:30:00Z',
                    'workspace_nombre' => 'WS',
                ],
            ], 200),
        ]);

        $result = $this->service->getSession(42, $this->credential);
        $exp = $result['nodes'][0]['explicacion'];

        $this->assertFalse($exp['sin_detalle']);
        $this->assertSame('H', $exp['decision_type']);
        $this->assertSame(0.85, $exp['confidence']);
        $this->assertCount(1, $exp['reasons']);
        $this->assertSame(['type' => 'N-K', 'reason' => 'No cita fuente verificable'], $exp['alternatives_considered'][0]);
        $this->assertSame(['causa_declarada', 'afirmacion_sin_fuente'], $exp['detected_patterns']);
    }

    public function test_fa2_get_session_without_metadata_flags_sin_detalle(): void
    {
        // Contrato viejo: nodos sin explicacion (sesiones pre-despliegue).
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/7' => Http::response([
                'success' => true,
                'data' => [
                    'session_id' => 7,
                    'status' => 'lista_para_revision',
                    'is_simple' => true,
                    'nodes' => [
                        ['id' => 'sandbox_1', 'tipo' => 'N-K', 'texto' => 'Dato viejo', 'relaciones' => []],
                    ],
                    'resumen' => 'Se propuso 1 nota.',
                ],
            ], 200),
        ]);

        $result = $this->service->getSession(7, $this->credential);
        $exp = $result['nodes'][0]['explicacion'];

        $this->assertTrue($exp['sin_detalle']);
        $this->assertNull($exp['decision_type']);
        $this->assertNull($exp['confidence']);
        $this->assertSame([], $exp['reasons']);
    }

    public function test_fa3_partial_metadata_uses_safe_defaults(): void
    {
        $exp = ExplicacionNormalizer::fromArray([
            'decision_type' => 'H',
            // confidence, reasons, alternatives ausentes; alternatives con entry inválida
            'reasons' => [],
            'alternatives_considered' => [['type' => ''], 'Texto suelto', ['type' => 'SQ', 'reason' => 'Competía en precedencia']],
            'detected_patterns' => ['causa_declarada', 42, ''],
        ]);

        $this->assertFalse($exp['sin_detalle']);
        $this->assertNull($exp['confidence']);
        $this->assertSame([], $exp['reasons']);
        $this->assertSame([['type' => 'SQ', 'reason' => 'Competía en precedencia']], $exp['alternatives_considered']);
        $this->assertSame(['causa_declarada'], $exp['detected_patterns']);
    }

    public function test_fa4_legacy_confianza_is_never_used_as_fallback(): void
    {
        // Regla Q4.5: explicacion null → sin_detalle; NUNCA caer a confianza/justificacion_ia.
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/9' => Http::response([
                'success' => true,
                'data' => [
                    'session_id' => 9,
                    'status' => 'lista_para_revision',
                    'is_simple' => true,
                    'nodes' => [
                        ['id' => 'sandbox_1', 'tipo' => 'H', 'texto' => 'X', 'relaciones' => [], 'confianza' => 0.9, 'justificacion_ia' => 'afirmación con causa'],
                    ],
                    'resumen' => '',
                ],
            ], 200),
        ]);

        $result = $this->service->getSession(9, $this->credential);

        $this->assertTrue($result['nodes'][0]['explicacion']['sin_detalle']);
        $this->assertNull($result['nodes'][0]['explicacion']['confidence']);
        $this->assertSame([], $result['nodes'][0]['explicacion']['reasons']);
    }

    public function test_fa5_multiple_nodes_keep_their_own_metadata(): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/11' => Http::response([
                'success' => true,
                'data' => [
                    'session_id' => 11,
                    'status' => 'lista_para_revision',
                    'is_simple' => false,
                    'nodes' => [
                        ['id' => 'sandbox_1', 'tipo' => 'H', 'texto' => 'A', 'relaciones' => [], 'explicacion' => $this->explicacionFixture()],
                        ['id' => 'sandbox_2', 'tipo' => 'N-K', 'texto' => 'B', 'relaciones' => []], // sin metadata
                    ],
                    'resumen' => 'Se propusieron 2 nodos.',
                ],
            ], 200),
        ]);

        $result = $this->service->getSession(11, $this->credential);

        $this->assertSame('H', $result['nodes'][0]['explicacion']['decision_type']);
        $this->assertTrue($result['nodes'][1]['explicacion']['sin_detalle']);
    }

    public function test_contribute_maps_inline_explicacion_nodo_principal(): void
    {
        Http::fake([
            'localhost:8000/api/v1/contribute' => Http::response([
                'success' => true,
                'data' => [
                    'session_id' => 55,
                    'status' => 'pendiente_revision',
                    'resumen' => 'Se propuso 1 hipótesis.',
                    'explicacion_nodo_principal' => $this->explicacionFixture(),
                ],
            ], 200),
        ]);

        $result = $this->service->contribute(texto: 'El batch no llega porque el banco falla', credential: $this->credential);

        $this->assertSame('H', $result['explicacion']['decision_type']);
        $this->assertSame(0.85, $result['explicacion']['confidence']);
    }

    public function test_contribute_without_inline_explicacion_omits_key(): void
    {
        Http::fake([
            'localhost:8000/api/v1/contribute' => Http::response([
                'success' => true,
                'data' => ['session_id' => 56, 'status' => 'pendiente_revision', 'resumen' => 'OK'],
            ], 200),
        ]);

        $result = $this->service->contribute(texto: 'Texto de aporte suficiente', credential: $this->credential);

        $this->assertArrayNotHasKey('explicacion', $result);
    }

    // ─── FB — Presentador (Fase B) ───

    private function presenter(): ExplicacionPresenter
    {
        return new ExplicacionPresenter;
    }

    public function test_fb1_frase_principal_por_tipo(): void
    {
        $p = $this->presenter();

        foreach (['Q', 'SQ', 'H', 'N-K', 'N-A'] as $tipo) {
            $exp = ExplicacionNormalizer::fromArray([
                'decision_type' => $tipo,
                'confidence' => 0.9,
                'reasons' => [],
                'alternatives_considered' => [],
                'detected_patterns' => [],
            ]);

            $frase = $p->frasePrincipal($exp);
            $this->assertStringContainsString('Se clasificó como', $frase, "Falta frase para tipo $tipo");
        }

        // Vocabulario del método QBK, sin jerga de código.
        $expH = ExplicacionNormalizer::fromArray(['decision_type' => 'H', 'confidence' => 0.9, 'reasons' => [], 'alternatives_considered' => [], 'detected_patterns' => []]);
        $this->assertStringContainsString('Hipótesis', $p->frasePrincipal($expH));

        $expNA = ExplicacionNormalizer::fromArray(['decision_type' => 'N-A', 'confidence' => 0.9, 'reasons' => [], 'alternatives_considered' => [], 'detected_patterns' => []]);
        $this->assertStringContainsString('Nota de acción', $p->frasePrincipal($expNA));
    }

    public function test_fb1_razon_se_agrega_como_oracion_aparte(): void
    {
        $exp = ExplicacionNormalizer::fromArray([
            'decision_type' => 'H',
            'confidence' => 0.85,
            'reasons' => ['El texto usa "porque el batch no llega", señal de causa declarada.'],
            'alternatives_considered' => [],
            'detected_patterns' => [],
        ]);

        $frase = $this->presenter()->frasePrincipal($exp);

        $this->assertStringContainsString('Se clasificó como Hipótesis', $frase);
        $this->assertStringContainsString('El texto usa "porque el batch no llega"', $frase);
    }

    public function test_fb2_alternativa_con_motivo_de_descarte(): void
    {
        $exp = ExplicacionNormalizer::fromArray([
            'decision_type' => 'H',
            'confidence' => 0.85,
            'reasons' => [],
            'alternatives_considered' => [['type' => 'N-K', 'reason' => 'No cita fuente verificable']],
            'detected_patterns' => [],
        ]);

        $alts = $this->presenter()->alternativas($exp);

        $this->assertSame(
            ['También se evaluó como Nota de conocimiento, pero se descartó porque no cita fuente verificable.'],
            $alts,
        );
    }

    public function test_fb3_semaforo_por_rango(): void
    {
        $p = $this->presenter();

        $mk = fn (float $c) => ExplicacionNormalizer::fromArray(['decision_type' => 'H', 'confidence' => $c, 'reasons' => [], 'alternatives_considered' => [], 'detected_patterns' => []]);

        // §2.4: verde >80%, amarillo 50-80%, rojo <50%.
        $this->assertSame('verde', $p->semaforo($mk(0.9)));
        $this->assertSame('amarillo', $p->semaforo($mk(0.65)));
        $this->assertSame('rojo', $p->semaforo($mk(0.4)));
        $this->assertSame('verde', $p->semaforo($mk(0.81)));
        $this->assertSame('amarillo', $p->semaforo($mk(0.5)));
    }

    public function test_fb3_confidence_ausente_no_genera_semaforo(): void
    {
        $exp = ExplicacionNormalizer::fromArray(['decision_type' => 'H', 'confidence' => null, 'reasons' => [], 'alternatives_considered' => [], 'detected_patterns' => []]);

        $this->assertNull($this->presenter()->semaforo($exp));
        $this->assertNull($this->presenter()->porcentaje($exp));
    }

    public function test_fb4_sin_detalle_copy_honesto(): void
    {
        $exp = ExplicacionNormalizer::sinDetalle();

        $this->assertSame('Este aporte no tiene detalle de clasificación disponible.', $this->presenter()->frasePrincipal($exp));
        $this->assertNull($this->presenter()->semaforo($exp));
        $this->assertNull($this->presenter()->advertencia($exp));
    }

    public function test_fb5_patrones_en_vocabulario_de_las_reglas_de_qubeka(): void
    {
        // Vocabulario de reglas-clasificacion-explicabilidad.md (QuBeKa).
        $exp = ExplicacionNormalizer::fromArray([
            'decision_type' => 'H',
            'confidence' => 0.85,
            'reasons' => [],
            'alternatives_considered' => [],
            'detected_patterns' => ['causa_declarada', 'patron_desconocido_xyz'],
        ]);

        $patrones = $this->presenter()->patronesLegibles($exp);

        $this->assertSame(['palabras de causa', 'patron desconocido xyz'], $patrones);
    }

    public function test_advertencia_solo_con_confianza_roja(): void
    {
        $p = $this->presenter();
        $mk = fn (float $c) => ExplicacionNormalizer::fromArray(['decision_type' => 'H', 'confidence' => $c, 'reasons' => [], 'alternatives_considered' => [], 'detected_patterns' => []]);

        $this->assertNotNull($p->advertencia($mk(0.4)));
        $this->assertNull($p->advertencia($mk(0.65)));
    }
}
