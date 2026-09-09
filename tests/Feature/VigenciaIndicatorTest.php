<?php

namespace Tests\Feature;

use App\Livewire\QuestionDetail;
use App\Livewire\QuestionFeed;
use App\Livewire\ReviewTray;
use App\Models\Question;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ola 2, Punto 3 — Indicador de vigencia visible (checklists FA y FB/FD con mock).
 * Fase A: confirmadorQbk() (D-Confirmador, contrato §5.3). Fases B/D: componente
 * x-vigencia-indicator en detalle, feed y bandeja.
 */
class VigenciaIndicatorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        config(['services.qubeka.api_url' => 'http://localhost:8000/api/v1']);
        config(['kuestion.reconfirmacion.umbral_dias' => 90]);
    }

    private function createQbkQuestion(array $sources = []): Question
    {
        $repo = Repository::factory()->create([
            'user_id' => $this->user->uuid,
            'connector_type' => 'qbk',
            'credential' => ['api_token' => '2|qbk_test_token'],
        ]);

        $question = Question::factory()->create([
            'user_id' => $this->user->uuid,
            'repository_id' => $repo->id,
        ]);

        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => 'Respuesta test',
            'confidence' => 80,
            'sources' => $sources,
            'response_hash' => hash('sha256', 'Respuesta test'),
            'is_current' => true,
            'found' => true,
        ]);

        return $question->refresh();
    }

    // ------------------------------------------------------------------
    // Fase A — confirmadorQbk() (A.1, D-Confirmador: valor VARIABLE)
    // ------------------------------------------------------------------

    public function test_confirmador_devuelve_el_nombre_real_del_usuario_de_qubeka(): void
    {
        // FB.5 caso 1: PAT humano → nombre real, renderizado tal cual.
        $question = $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => '2026-09-01T10:00:00+00:00', 'ultimo_confirmador_nombre' => 'María Fernández'],
        ]);

        $this->assertSame('María Fernández', $question->confirmadorQbk());
    }

    public function test_confirmador_devuelve_el_literal_del_conector_cuando_fue_el_conector(): void
    {
        // FB.5 caso 2: vía conector (B1 MVP) → "Kuestion (conector)", también tal cual.
        $question = $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => '2026-09-01T10:00:00+00:00', 'ultimo_confirmador_nombre' => 'Kuestion (conector)'],
        ]);

        $this->assertSame('Kuestion (conector)', $question->confirmadorQbk());
    }

    public function test_confirmador_usa_la_confirmacion_mas_reciente(): void
    {
        $question = $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => '2026-06-01T10:00:00+00:00', 'ultimo_confirmador_nombre' => 'Kuestion (conector)'],
            ['node_id' => 'NK-002', 'fecha_ultima_confirmacion' => '2026-09-01T10:00:00+00:00', 'ultimo_confirmador_nombre' => 'Juan Pérez'],
        ]);

        $this->assertSame('Juan Pérez', $question->confirmadorQbk());
    }

    public function test_confirmador_null_sin_reconfirmaciones(): void
    {
        $question = $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'tipo' => 'N-K'],
        ]);

        $this->assertNull($question->confirmadorQbk());
    }

    public function test_confirmador_null_para_kuaforia(): void
    {
        $repo = Repository::factory()->create([
            'user_id' => $this->user->uuid,
            'connector_type' => 'kuaforia',
        ]);
        $question = Question::factory()->create([
            'user_id' => $this->user->uuid,
            'repository_id' => $repo->id,
        ]);

        $this->assertNull($question->confirmadorQbk());
    }

    // ------------------------------------------------------------------
    // Fase B — indicador completo en el detalle (FB.1–FB.6)
    // ------------------------------------------------------------------

    public function test_detail_estado_verde_vigente(): void
    {
        $question = $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => now()->subDays(2)->toIso8601String(), 'ultimo_confirmador_nombre' => 'María Fernández'],
        ]);

        Livewire::actingAs($this->user)
            ->test(QuestionDetail::class, ['question' => $question])
            ->assertSee('Vigente')
            ->assertSee('Última confirmación: hace 2 días')
            ->assertSee('María Fernández') // FB.5: nombre real en el tooltip.
            ->assertDontSee('Reconfirmar'); // D3: sin acción en estado vigente.
    }

    public function test_detail_estado_amarillo_vencida_con_boton(): void
    {
        $question = $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => now()->subDays(95)->toIso8601String()],
        ]);

        Livewire::actingAs($this->user)
            ->test(QuestionDetail::class, ['question' => $question])
            ->assertSee('Pendiente de reconfirmación')
            ->assertSee('Última confirmación: hace 95 días')
            ->assertSee('Reconfirmar');
    }

    public function test_detail_sin_dato_absorbe_copy_honesto_y_ofrece_accion(): void
    {
        // FB.4: QBK nuevo → "sin reconfirmaciones registradas" + [Reconfirmar].
        $question = $this->createQbkQuestion();

        Livewire::actingAs($this->user)
            ->test(QuestionDetail::class, ['question' => $question])
            ->assertSee('Pendiente de reconfirmación')
            ->assertSee('sin reconfirmaciones registradas')
            ->assertSee('Reconfirmar');
    }

    public function test_detail_kuaforia_no_renderiza_indicador(): void
    {
        // A.2 — regla Kuaforia (bloqueante B2 abierto): sin señal por respuesta,
        // 'no_aplica' no renderiza nada (ni copy técnico).
        $repo = Repository::factory()->create([
            'user_id' => $this->user->uuid,
            'connector_type' => 'kuaforia',
        ]);
        $question = Question::factory()->create([
            'user_id' => $this->user->uuid,
            'repository_id' => $repo->id,
        ]);
        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => 'Respuesta test',
            'confidence' => 80,
            'sources' => [],
            'response_hash' => hash('sha256', 'Respuesta test'),
            'is_current' => true,
        ]);

        Livewire::actingAs($this->user)
            ->test(QuestionDetail::class, ['question' => $question])
            ->assertDontSee('Pendiente de reconfirmación')
            ->assertDontSee('Vigente')
            ->assertDontSee('Reconfirmar');
    }

    // ------------------------------------------------------------------
    // Fase D — mini-indicador en el feed (FD.1) y bandeja (FD.2)
    // ------------------------------------------------------------------

    public function test_feed_mini_badge_coherente_sin_boton(): void
    {
        // FD.1: badge compacto (estado + fecha); D.1: el feed es informativo,
        // la acción Reconfirmar se abre en el detalle (Punto 3 sobre Punto 2).
        $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => now()->subDays(95)->toIso8601String()],
        ]);

        Livewire::actingAs($this->user)
            ->test(QuestionFeed::class)
            ->assertSee('Pendiente de reconfirmación · hace 95 días')
            ->assertDontSee('Reconfirmar');
    }

    public function test_feed_confirmada_muestra_badge_verde(): void
    {
        $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => now()->subDays(2)->toIso8601String()],
        ]);

        Livewire::actingAs($this->user)
            ->test(QuestionFeed::class)
            ->assertSee('Última confirmación: hace 2 días')
            ->assertDontSee('Reconfirmar'); // D3: sin acción en estado vigente.
    }

    public function test_bandeja_pestaña_muestra_indicador_completo_para_sin_dato(): void
    {
        // FD.2 + FB.4: la pestaña incluye ítems sin reconfirmaciones registradas.
        $this->createQbkQuestion();

        Livewire::actingAs($this->user)
            ->test(ReviewTray::class)
            ->call('switchEstado', 'reconfirmar')
            ->assertSee('sin reconfirmaciones registradas')
            ->assertSee('Reconfirmar');
    }

    public function test_bandeja_pestaña_muestra_confirmador_variable(): void
    {
        $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => now()->subDays(95)->toIso8601String(), 'ultimo_confirmador_nombre' => 'Kuestion (conector)'],
        ]);

        Livewire::actingAs($this->user)
            ->test(ReviewTray::class)
            ->call('switchEstado', 'reconfirmar')
            ->assertSee('Pendiente de reconfirmación')
            ->assertSee('Kuestion (conector)');
    }

    public function test_bandeja_reconfirmar_desde_sin_dato_actualiza_el_indicador(): void
    {
        // FC.1 vía bandeja con un ítem sin_dato: tras reconfirmar, sale de la lista.
        $question = $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'tipo' => 'N-K'],
        ]);

        Http::fake([
            'localhost:8000/api/v1/nodos/NK-001/reconfirmar' => Http::response([
                'success' => true,
                'data' => ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => now()->toIso8601String()],
            ], 200),
        ]);

        Livewire::actingAs($this->user)
            ->test(ReviewTray::class)
            ->call('switchEstado', 'reconfirmar')
            ->assertSee($question->question_text)
            ->call('reconfirmarPregunta', $question->id)
            ->assertSee('Nada pendiente de reconfirmar.');
    }
}
