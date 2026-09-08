<?php

namespace Tests\Feature;

use App\Exceptions\KuaforiaException;
use App\Livewire\QuestionDetail;
use App\Livewire\QuestionFeed;
use App\Models\Question;
use App\Models\Repository;
use App\Models\User;
use App\Services\QbkContributionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ola 2, Punto 2 — Fases B y C: estado de vigencia, copy ramificado y acción
 * de reconfirmación (checklists FB y FC con mock del contrato §5).
 */
class QuestionVigenciaReconfirmacionTest extends TestCase
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

    private function createQbkQuestion(array $sources = [], array $repoAttrs = []): Question
    {
        $repo = Repository::factory()->create(array_merge([
            'user_id' => $this->user->uuid,
            'connector_type' => 'qbk',
            'credential' => ['api_token' => '2|qbk_test_token'],
        ], $repoAttrs));

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
    // Fase B — estado de vigencia (B.2)
    // ------------------------------------------------------------------

    public function test_vigencia_no_aplica_para_kuaforia(): void
    {
        $repo = Repository::factory()->create([
            'user_id' => $this->user->uuid,
            'connector_type' => 'kuaforia',
        ]);
        $question = Question::factory()->create([
            'user_id' => $this->user->uuid,
            'repository_id' => $repo->id,
        ]);

        $this->assertSame('no_aplica', $question->vigenciaQbk()['estado']);
    }

    public function test_vigencia_sin_dato_cuando_sources_sin_campo(): void
    {
        // Contrato viejo: sources sin fecha_ultima_confirmacion (FA.6).
        $question = $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'tipo' => 'N-K'],
        ]);

        $vigencia = $question->vigenciaQbk();

        $this->assertSame('sin_dato', $vigencia['estado']);
        $this->assertNull($vigencia['ultima_confirmacion']);
    }

    public function test_vigencia_confirmada_dentro_del_umbral(): void
    {
        $question = $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => now()->subDays(10)->toIso8601String()],
        ]);

        $vigencia = $question->vigenciaQbk();

        $this->assertSame('confirmada', $vigencia['estado']);
        $this->assertSame(10, $vigencia['dias']);
    }

    public function test_vigencia_vencida_supera_el_umbral(): void
    {
        $question = $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => now()->subDays(95)->toIso8601String()],
        ]);

        $vigencia = $question->vigenciaQbk();

        $this->assertSame('vencida', $vigencia['estado']);
        $this->assertSame(95, $vigencia['dias']);
    }

    public function test_vigencia_usa_la_fecha_mas_reciente_entre_las_fuentes(): void
    {
        // D1: vigencia de la pregunta = confirmación más reciente entre fuentes.
        $question = $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => now()->subDays(120)->toIso8601String()],
            ['node_id' => 'NK-002', 'fecha_ultima_confirmacion' => now()->subDays(5)->toIso8601String()],
        ]);

        $this->assertSame('confirmada', $question->vigenciaQbk()['estado']);
    }

    public function test_vigencia_sin_dato_con_sources_null(): void
    {
        $question = $this->createQbkQuestion([]);

        $this->assertSame('sin_dato', $question->vigenciaQbk()['estado']);
    }

    // ------------------------------------------------------------------
    // Fase B — copy ramificado (B.3, checklist FB)
    // ------------------------------------------------------------------

    public function test_feed_muestra_fecha_de_confirmacion_cuando_esta_confirmada(): void
    {
        $question = $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => '2026-09-01T10:00:00+00:00'],
        ]);

        Livewire::actingAs($this->user)
            ->test(QuestionFeed::class)
            ->assertSee('Última confirmación:')
            ->assertDontSee('sin reconfirmaciones registradas');
    }

    public function test_feed_muestra_botón_reconfirmar_cuando_esta_vencida(): void
    {
        $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => '2026-05-01T10:00:00+00:00'],
        ]);

        Livewire::actingAs($this->user)
            ->test(QuestionFeed::class)
            ->assertSee('Sin reconfirmar desde hace')
            ->assertSee('Reconfirmar')
            ->assertDontSee('sin reconfirmaciones registradas');
    }

    public function test_feed_mantiene_copy_honesto_cuando_no_hay_dato(): void
    {
        $this->createQbkQuestion();

        Livewire::actingAs($this->user)
            ->test(QuestionFeed::class)
            ->assertSee('sin reconfirmaciones registradas')
            ->assertDontSee('Reconfirmar');
    }

    public function test_detail_muestra_fecha_de_confirmacion_cuando_esta_confirmada(): void
    {
        $question = $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => '2026-09-01T10:00:00+00:00'],
        ]);

        Livewire::actingAs($this->user)
            ->test(QuestionDetail::class, ['question' => $question])
            ->assertSee('Última confirmación:')
            ->assertDontSee('sin reconfirmaciones registradas');
    }

    public function test_detail_muestra_botón_reconfirmar_cuando_esta_vencida(): void
    {
        $question = $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => '2026-05-01T10:00:00+00:00'],
        ]);

        Livewire::actingAs($this->user)
            ->test(QuestionDetail::class, ['question' => $question])
            ->assertSee('Sin reconfirmar desde hace')
            ->assertSee('Reconfirmar');
    }

    public function test_detail_mantiene_copy_honesto_cuando_no_hay_dato(): void
    {
        $question = $this->createQbkQuestion();

        Livewire::actingAs($this->user)
            ->test(QuestionDetail::class, ['question' => $question])
            ->assertSee('sin reconfirmaciones registradas')
            ->assertDontSee('Reconfirmar');
    }

    // ------------------------------------------------------------------
    // Fase C — acción reconfirmar (C.1/C.3, checklist FC con mock)
    // ------------------------------------------------------------------

    public function test_detail_reconfirmar_exitoso_llama_patch_por_cada_nodo(): void
    {
        $question = $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => '2026-05-01T10:00:00+00:00'],
            ['node_id' => 'NK-002', 'fecha_ultima_confirmacion' => '2026-05-01T10:00:00+00:00'],
        ]);

        Http::fake([
            'localhost:8000/api/v1/nodos/*/reconfirmar' => Http::response([
                'success' => true,
                'data' => [
                    'node_id' => 'NK-001',
                    'fecha_ultima_confirmacion' => now()->toIso8601String(),
                    'ultimo_confirmador_id' => null,
                ],
            ], 200),
        ]);

        Livewire::actingAs($this->user)
            ->test(QuestionDetail::class, ['question' => $question])
            ->call('reconfirmar')
            ->assertSee('¡Confirmado! Última confirmación: ahora.');

        Http::assertSentCount(2);
    }

    public function test_detail_reconfirmar_no_hace_nada_si_no_esta_vencida(): void
    {
        $question = $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => now()->subDays(5)->toIso8601String()],
        ]);

        Http::fake();

        Livewire::actingAs($this->user)
            ->test(QuestionDetail::class, ['question' => $question])
            ->call('reconfirmar');

        Http::assertNothingSent();
    }

    public function test_detail_reconfirmar_403_muestra_mensaje_legible(): void
    {
        $question = $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => '2026-05-01T10:00:00+00:00'],
        ]);

        Http::fake([
            'localhost:8000/api/v1/nodos/NK-001/reconfirmar' => Http::response([
                'success' => false,
                'errors' => ['message' => 'Forbidden'],
            ], 403),
        ]);

        Livewire::actingAs($this->user)
            ->test(QuestionDetail::class, ['question' => $question])
            ->call('reconfirmar')
            ->assertSee('No tenés permiso para reconfirmar este conocimiento');
    }

    public function test_detail_reconfirmar_404_muestra_mensaje_legible(): void
    {
        $question = $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => '2026-05-01T10:00:00+00:00'],
        ]);

        Http::fake([
            'localhost:8000/api/v1/nodos/NK-001/reconfirmar' => Http::response([
                'success' => false,
                'errors' => ['message' => 'Nodo no disponible.'],
            ], 404),
        ]);

        Livewire::actingAs($this->user)
            ->test(QuestionDetail::class, ['question' => $question])
            ->call('reconfirmar')
            ->assertSee('Este conocimiento ya no está disponible para reconfirmar');
    }

    public function test_detail_reconfirmar_timeout_muestra_mensaje_legible(): void
    {
        $question = $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => '2026-05-01T10:00:00+00:00'],
        ]);

        Http::fake([
            'localhost:8000/api/v1/nodos/NK-001/reconfirmar' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        Livewire::actingAs($this->user)
            ->test(QuestionDetail::class, ['question' => $question])
            ->call('reconfirmar')
            ->assertSee('tardó demasiado');
    }

    public function test_detail_reconfirmar_sin_fuentes_muestra_error(): void
    {
        $question = $this->createQbkQuestion([
            ['tipo' => 'N-K'], // sin node_id
            ['node_id' => '', 'fecha_ultima_confirmacion' => '2026-05-01T10:00:00+00:00'],
        ]);

        Http::fake();

        Livewire::actingAs($this->user)
            ->test(QuestionDetail::class, ['question' => $question])
            ->call('reconfirmar')
            ->assertSee('no tiene fuentes reconfirmables');

        Http::assertNothingSent();
    }

    public function test_feed_reconfirmar_exitoso_despacha_evento(): void
    {
        $question = $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => '2026-05-01T10:00:00+00:00'],
        ]);

        Http::fake([
            'localhost:8000/api/v1/nodos/*/reconfirmar' => Http::response([
                'success' => true,
                'data' => ['node_id' => 'NK-001'],
            ], 200),
        ]);

        Livewire::actingAs($this->user)
            ->test(QuestionFeed::class)
            ->call('reconfirmar', $question->id)
            ->assertDispatched('reconfirmar-ok');
    }

    public function test_feed_reconfirmar_error_despacha_evento_con_mensaje(): void
    {
        $question = $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => '2026-05-01T10:00:00+00:00'],
        ]);

        Http::fake([
            'localhost:8000/api/v1/nodos/NK-001/reconfirmar' => Http::response([
                'success' => false,
                'errors' => ['message' => 'Forbidden'],
            ], 403),
        ]);

        Livewire::actingAs($this->user)
            ->test(QuestionFeed::class)
            ->call('reconfirmar', $question->id)
            ->assertDispatched('reconfirmar-error', message: 'No tenés permiso para reconfirmar este conocimiento en QuBeKa.');
    }

    public function test_feed_reconfirmar_otro_usuario_no_encuentra_la_pregunta(): void
    {
        $question = $this->createQbkQuestion([
            ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => '2026-05-01T10:00:00+00:00'],
        ]);

        $otro = User::factory()->create();

        Http::fake();

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($otro)
            ->test(QuestionFeed::class)
            ->call('reconfirmar', $question->id);
    }

    public function test_401_durante_reconfirmar_marca_repo_invalid_en_checker_no_aplica_aqui(): void
    {
        // Sanity: el mensaje de token revocado es el mismo del resto del servicio QBK.
        $service = new QbkContributionService;

        Http::fake([
            'localhost:8000/api/v1/nodos/NK-001/reconfirmar' => Http::response([
                'success' => false,
                'errors' => ['message' => 'Invalid token'],
            ], 401),
        ]);

        try {
            $service->reconfirmarNodo('NK-001', ['api_token' => '2|qbk_test_token']);
            $this->fail('Se esperaba KuaforiaException');
        } catch (KuaforiaException $e) {
            $this->assertSame(401, $e->getCode());
            $this->assertStringContainsString('token de QuBeKa es inválido', $e->getMessage());
        }
    }
}
