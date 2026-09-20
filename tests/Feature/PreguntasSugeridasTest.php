<?php

namespace Tests\Feature;

use App\Livewire\CreateQuestion;
use App\Models\Question;
use App\Models\Repository;
use App\Models\User;
use App\Services\QbkSuggestionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ola 3, Punto 3 — Fase B (B.5): flujo de preguntas sugeridas sobre la vista
 * real de CreateQuestion (lección del review del Punto 1: no llamar métodos
 * salteando la vista).
 */
class PreguntasSugeridasTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        config(['services.qubeka.api_url' => 'http://mock-qubeka.test/api/v1']);
    }

    private function crearRepoQbk(array $atributos = []): Repository
    {
        return Repository::factory()->create(array_merge([
            'user_id' => $this->user->uuid,
            'connector_type' => 'qbk',
            'credential' => ['api_token' => '2|qbk_test_token'],
            'status' => 'active',
            'is_default' => true,
        ], $atributos));
    }

    private function fakeQbk(array $suggestions): void
    {
        Http::fake([
            '*/suggestions*' => Http::response([
                'success' => true,
                'data' => ['suggestions' => $suggestions],
            ]),
            '*/query' => Http::response([
                'answer' => 'Respuesta de prueba',
                'confidence' => 0.5,
                'sources' => [],
                'found' => true,
            ]),
        ]);
    }

    private function llamadasASuggestions(): int
    {
        // recorded() (Factory.php:441, Laravel instalado) devuelve [] con cero
        // requests; assertSent() exige al menos uno y rompe el caso "0 llamadas".
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/suggestions'))
            ->count();
    }

    public function test_carga_puebla_la_propiedad_desde_qbk(): void
    {
        $this->crearRepoQbk();
        $this->fakeQbk([
            ['texto' => '¿Qué es X?', 'fuente' => 'pregunta_abierta', 'nodo_origen_id' => 'NK-001'],
            ['texto' => '¿Qué es Y?', 'fuente' => 'contenido', 'nodo_origen_id' => null],
        ]);

        $test = Livewire::test(CreateQuestion::class)
            ->call('cargarSugerencias'); // simula el wire:init del navegador

        $preguntas = $test->instance()->preguntasSugeridas;

        $this->assertCount(2, $preguntas);
        $this->assertSame('¿Qué es X?', $preguntas[0]['texto']);
        // D2 — nodo_origen_id null se mantiene, no filtra.
        $this->assertSame('¿Qué es Y?', $preguntas[1]['texto']);
        $this->assertNull($preguntas[1]['nodo_origen_id']);
    }

    public function test_clic_precarga_el_texto_y_no_crea_pregunta(): void
    {
        $this->crearRepoQbk();
        $this->fakeQbk([
            ['texto' => '¿Qué conviene vigilar periódicamente y por qué?', 'fuente' => 'pregunta_abierta', 'nodo_origen_id' => 'NK-001'],
        ]);

        Livewire::test(CreateQuestion::class)
            ->call('cargarSugerencias')
            ->call('usarSugerencia', '¿Qué conviene vigilar periódicamente y por qué?')
            ->assertSet('questionText', '¿Qué conviene vigilar periódicamente y por qué?')
            ->assertSet('status', 'idle');

        // Precarga, nunca auto-ejecución (§1.1).
        $this->assertSame(0, Question::count());
    }

    public function test_save_invalida_el_cache(): void
    {
        $this->crearRepoQbk();
        $this->fakeQbk([
            ['texto' => '¿Qué es X?', 'fuente' => 'pregunta_abierta', 'nodo_origen_id' => null],
        ]);

        Livewire::test(CreateQuestion::class)
            ->call('cargarSugerencias')
            ->set('questionText', '¿Qué es X?')
            ->call('save')
            ->assertSet('status', 'saved');

        $this->assertSame(1, Question::count());

        // Con el cache invalidado, la próxima carga vuelve a consultar (§1.4).
        Livewire::test(CreateQuestion::class)->call('cargarSugerencias');

        $this->assertSame(2, $this->llamadasASuggestions());
    }

    public function test_segunda_carga_dentro_de_10_min_usa_cache(): void
    {
        $this->crearRepoQbk();
        $this->fakeQbk([
            ['texto' => '¿Qué es X?', 'fuente' => 'pregunta_abierta', 'nodo_origen_id' => null],
        ]);

        Livewire::test(CreateQuestion::class)->call('cargarSugerencias');
        Livewire::test(CreateQuestion::class)->call('cargarSugerencias');

        $this->assertSame(1, $this->llamadasASuggestions());
    }

    public function test_sin_repo_qbk_usa_el_catalogo_generico(): void
    {
        Repository::factory()->create([
            'user_id' => $this->user->uuid,
            'connector_type' => 'kuaforia',
            'status' => 'active',
            'is_default' => true,
        ]);

        Http::fake();

        $test = Livewire::test(CreateQuestion::class)->call('cargarSugerencias');

        $esperadas = app(QbkSuggestionService::class)->genericas();

        // El set es el del catálogo; el orden lo fija la rotación (C.5, con su test propio).
        $this->assertEqualsCanonicalizing($esperadas, $test->instance()->preguntasSugeridas);
        $this->assertSame(0, $this->llamadasASuggestions());
    }

    public function test_fallo_de_qbk_deja_la_pantalla_operativa_sin_seccion(): void
    {
        $this->crearRepoQbk();
        Http::fake(['*/suggestions*' => Http::response(['message' => 'Server Error'], 500)]);

        Livewire::test(CreateQuestion::class)
            ->call('cargarSugerencias')
            ->assertSet('preguntasSugeridas', [])
            ->assertSet('status', 'idle')
            ->assertDontSee('Quizás te interese preguntar')
            ->assertSee('Consultar y guardar'); // el flujo sigue operativo (FB-5)
    }

    public function test_200_vacio_de_qbk_muestra_el_catalogo_generico(): void
    {
        $this->crearRepoQbk();
        $this->fakeQbk([]);

        $test = Livewire::test(CreateQuestion::class)->call('cargarSugerencias');

        $esperadas = app(QbkSuggestionService::class)->genericas();

        $this->assertEqualsCanonicalizing($esperadas, $test->instance()->preguntasSugeridas);
    }

    public function test_render_muestra_la_seccion_sin_estados_tecnicos(): void
    {
        $this->crearRepoQbk();
        $this->fakeQbk([
            ['texto' => '¿Qué es X?', 'fuente' => 'pregunta_abierta', 'nodo_origen_id' => 'NK-001'],
            ['texto' => '¿Qué es Y?', 'fuente' => 'contenido', 'nodo_origen_id' => null],
        ]);

        Livewire::test(CreateQuestion::class)
            ->call('cargarSugerencias')
            ->assertSee('Quizás te interese preguntar')
            ->assertSee('¿Qué es X?')
            ->assertSee('¿Qué es Y?')
            ->assertSee('usarSugerenciaIndice(0)')
            // C.3 — nada de estados técnicos en la UI: ni fuente ni ids.
            ->assertDontSee('pregunta_abierta')
            ->assertDontSee('NK-001');
    }

    public function test_sin_sugerencias_la_seccion_no_se_renderiza(): void
    {
        $this->crearRepoQbk();
        Http::fake(['*/suggestions*' => Http::response(['message' => 'Server Error'], 500)]);

        Livewire::test(CreateQuestion::class)
            ->call('cargarSugerencias')
            ->assertDontSee('Quizás te interese preguntar');
    }

    public function test_rotacion_cambia_el_orden_entre_dias_sin_cambiar_el_set(): void
    {
        Carbon::setTestNow('2026-03-10 12:00:00'); // z=68

        try {
            $this->crearRepoQbk();
            $this->fakeQbk([
                ['texto' => '¿A?', 'fuente' => 'pregunta_abierta', 'nodo_origen_id' => null],
                ['texto' => '¿B?', 'fuente' => 'pregunta_abierta', 'nodo_origen_id' => null],
                ['texto' => '¿C?', 'fuente' => 'pregunta_abierta', 'nodo_origen_id' => null],
            ]);

            $t1 = Livewire::test(CreateQuestion::class)->call('cargarSugerencias');
            $orden1 = array_column($t1->instance()->preguntasSugeridas, 'texto');

            Carbon::setTestNow('2026-03-11 12:00:00'); // z=69 → offset distinto

            // Cache hit (dentro de los 10 min): la rotación aplica al mostrar (C.5).
            $t2 = Livewire::test(CreateQuestion::class)->call('cargarSugerencias');
            $orden2 = array_column($t2->instance()->preguntasSugeridas, 'texto');

            $this->assertEqualsCanonicalizing($orden1, $orden2); // mismas sugerencias
            $this->assertNotSame($orden1, $orden2); // en otro orden
        } finally {
            Carbon::setTestNow();
        }
    }
}
