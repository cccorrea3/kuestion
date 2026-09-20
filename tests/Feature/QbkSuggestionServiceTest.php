<?php

namespace Tests\Feature;

use App\Services\QbkSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QbkSuggestionServiceTest extends TestCase
{
    use RefreshDatabase;

    private QbkSuggestionService $service;

    private array $credential;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new QbkSuggestionService;
        $this->credential = ['api_token' => 'qbk_test_token_abc123'];

        config(['services.qubeka.api_url' => 'http://mock-qubeka.test/api/v1']);
    }

    public function test_maps_suggestions_from_real_envelope(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => true,
                'data' => [
                    'suggestions' => [
                        ['texto' => '¿Qué es X?', 'fuente' => 'pregunta_abierta', 'nodo_origen_id' => 'NK-001'],
                        ['texto' => '¿Qué es Y?', 'fuente' => 'contenido', 'nodo_origen_id' => null],
                    ],
                ],
            ]),
        ]);

        $resultado = $this->service->sugerencias($this->credential);
        $sugerencias = $resultado['sugerencias'];

        $this->assertTrue($resultado['ok']);
        $this->assertCount(2, $sugerencias);
        $this->assertSame('¿Qué es X?', $sugerencias[0]['texto']);
        $this->assertSame('pregunta_abierta', $sugerencias[0]['fuente']);
        $this->assertSame('NK-001', $sugerencias[0]['nodo_origen_id']);
        // D2 — nodo_origen_id null no filtra la sugerencia.
        $this->assertSame('¿Qué es Y?', $sugerencias[1]['texto']);
        $this->assertNull($sugerencias[1]['nodo_origen_id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/suggestions')
                && $request->method() === 'GET'
                && str_contains($request->url(), 'limit=5')
                && $request->header('Authorization')[0] === 'Bearer qbk_test_token_abc123';
        });
    }

    public function test_unknown_fuente_is_kept_unclassified(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => true,
                'data' => [
                    'suggestions' => [
                        ['texto' => '¿Qué es Z?', 'fuente' => 'fuente_futura_inexistente', 'nodo_origen_id' => 'NK-002'],
                    ],
                ],
            ]),
        ]);

        $sugerencias = $this->service->sugerencias($this->credential)['sugerencias'];

        $this->assertCount(1, $sugerencias);
        $this->assertSame('fuente_futura_inexistente', $sugerencias[0]['fuente']);
    }

    public function test_empty_suggestions_returns_empty_array(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => true,
                'data' => ['suggestions' => []],
            ]),
        ]);

        $resultado = $this->service->sugerencias($this->credential);

        // 200 vacío es un éxito del transporte (ok=true) — el fallback a
        // genéricas lo decide el componente, no el cliente (plan B.2).
        $this->assertTrue($resultado['ok']);
        $this->assertSame([], $resultado['sugerencias']);
    }

    public function test_401_degrades_to_failed_without_suggestions(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->assertFalse($this->service->sugerencias($this->credential)['ok']);
    }

    public function test_500_degrades_to_failed_without_suggestions(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Server Error'], 500)]);

        $this->assertFalse($this->service->sugerencias($this->credential)['ok']);
    }

    public function test_timeout_degrades_to_empty_array(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Timeout');
        });

        $this->assertFalse($this->service->sugerencias($this->credential)['ok']);
    }

    public function test_malformed_json_degrades_to_failed(): void
    {
        Http::fake(['*' => Http::response('{not-json', 200)]);

        $this->assertFalse($this->service->sugerencias($this->credential)['ok']);
    }

    public function test_unexpected_envelope_degrades_to_empty_array(): void
    {
        // Sobre inesperado: data sin suggestions, y variante pelada (sin data).
        Http::fake([
            'http://mock-qubeka.test/api/v1/suggestions*' => Http::sequence()
                ->push(['success' => true, 'data' => ['otra_cosa' => 1]])
                ->push(['suggestions' => [['texto' => 'x']]]),
        ]);

        $this->assertFalse($this->service->sugerencias($this->credential)['ok']);
        $this->assertFalse($this->service->sugerencias($this->credential)['ok']);
    }

    public function test_missing_token_returns_empty_without_http_call(): void
    {
        Http::fake();

        $this->assertSame(['ok' => false, 'sugerencias' => []], $this->service->sugerencias(['api_key' => 'no_token']));
        $this->assertSame(['ok' => false, 'sugerencias' => []], $this->service->sugerencias(null));
        $this->assertSame(['ok' => false, 'sugerencias' => []], $this->service->sugerencias([]));

        Http::assertNothingSent();
    }

    public function test_invalid_items_are_dropped_but_valid_ones_survive(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => true,
                'data' => [
                    'suggestions' => [
                        'no-soy-array',
                        ['sin_texto' => true],
                        ['texto' => '   '],
                        ['texto' => '¿Pregunta válida?', 'fuente' => 'contenido'],
                    ],
                ],
            ]),
        ]);

        $sugerencias = $this->service->sugerencias($this->credential)['sugerencias'];

        $this->assertCount(1, $sugerencias);
        $this->assertSame('¿Pregunta válida?', $sugerencias[0]['texto']);
    }

    public function test_genericas_reads_catalog_from_config(): void
    {
        config(['kuestion.sugerencias_genericas' => ['¿Una?', '¿Dos?']]);

        $genericas = $this->service->genericas();

        $this->assertCount(2, $genericas);
        $this->assertSame('¿Una?', $genericas[0]['texto']);
        $this->assertNull($genericas[0]['fuente']);
        $this->assertNull($genericas[0]['nodo_origen_id']);
    }
}
