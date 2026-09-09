<?php

namespace Tests\Feature;

use App\Livewire\ContributeAporte;
use App\Livewire\ContributionReview;
use App\Livewire\ReviewTray;
use App\Models\ContributionDraft;
use App\Models\Repository;
use App\Models\User;
use App\Services\QbkContributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ola 2, Punto 4 — Fases C y D: integración del bloque de explicación.
 * Checklists FC y FD del plan (sección 3) + fallo visible en runtime (C.3).
 *
 * Nota de patrones: Http::fake reemplaza TODO el stack de fakes en cada llamada,
 * y el patrón del listado requiere wildcard (`sesiones-analisis*`) porque la
 * request lleva query string. El patrón específico del detalle se registra
 * primero (first match wins).
 */
class ExplicabilidadIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private array $explicacion;

    protected function setUp(): void
    {
        parent::setUp();

        // Igual que QbkContributionServiceTest: fijar la URL para que los fakes matcheen.
        config(['services.qubeka.api_url' => 'http://localhost:8000/api/v1']);

        $this->explicacion = [
            'decision_type' => 'H',
            'confidence' => 0.85,
            'reasons' => ['El texto usa "porque el batch no llega", señal de causa declarada.'],
            'alternatives_considered' => [
                ['type' => 'N-K', 'reason' => 'No cita fuente verificable'],
            ],
            'detected_patterns' => ['causa_declarada'],
        ];
    }

    private function userWithQbkRepo(): object
    {
        $user = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $user->uuid,
            'status' => 'active',
            'is_default' => true,
            'connector_type' => 'qbk',
            'credential' => ['api_token' => '2|test_token_123'],
        ]);

        return $user;
    }

    private function trayItem(int $id): array
    {
        return [
            'session_id' => $id,
            'status' => 'lista_para_revision',
            'fecha_creacion' => '2026-08-29T10:30:00Z',
            'texto_original_del_aporte' => 'Texto del aporte',
            'resumen_clasificacion' => 'Se propuso 1 hipótesis.',
            'is_simple' => true,
            'pregunta_previa' => null,
            'autor_email' => null,
            'autor_nombre' => 'Juan',
            'cerrado_en' => null,
        ];
    }

    private function trayList(array $items): array
    {
        return [
            'success' => true,
            'data' => $items,
            'meta' => ['page' => 1, 'per_page' => 20, 'total' => count($items), 'last_page' => 1],
        ];
    }

    private function sessionDetail(int $id, ?array $explicacion): array
    {
        $node = ['id' => 'sandbox_1', 'tipo' => $explicacion ? 'H' : 'N-K', 'texto' => 'T', 'relaciones' => []];

        if ($explicacion !== null) {
            $node['explicacion'] = $explicacion;
        }

        return [
            'success' => true,
            'data' => [
                'session_id' => $id,
                'status' => 'lista_para_revision',
                'is_simple' => true,
                'nodes' => [$node],
                'resumen' => 'OK',
            ],
        ];
    }

    // ─── FC — Confirmación inmediata del "Aportar" (Fase C) ───

    public function test_fc1_show_details_link_visible_without_inline_explicacion(): void
    {
        $user = $this->userWithQbkRepo();

        // D2: contribute sin explicación inline → el enlace consulta al expandir.
        Http::fake([
            'localhost:8000/api/v1/contribute' => Http::response([
                'success' => true,
                'data' => ['session_id' => 42, 'status' => 'pendiente_revision', 'resumen' => 'Se propuso 1 hipótesis.'],
            ], 200),
        ]);

        Livewire::actingAs($user)->test(ContributeAporte::class)
            ->set('texto', 'El job falla porque el batch del banco no llega antes de las 6am')
            ->call('submit')
            ->assertSet('status', 'saved')
            ->assertSet('sessionId', 42)
            ->assertSee('Ver detalles de la clasificación');
    }

    public function test_fc2_inline_explicacion_renders_block_without_second_call(): void
    {
        $user = $this->userWithQbkRepo();

        Http::fake([
            'localhost:8000/api/v1/contribute' => Http::response([
                'success' => true,
                'data' => [
                    'session_id' => 42,
                    'status' => 'pendiente_revision',
                    'resumen' => 'Se propuso 1 hipótesis.',
                    'explicacion_nodo_principal' => $this->explicacion,
                ],
            ], 200),
        ]);

        Livewire::actingAs($user)->test(ContributeAporte::class)
            ->set('texto', 'El job falla porque el batch del banco no llega antes de las 6am')
            ->call('submit')
            ->assertSet('status', 'saved')
            ->assertSee('Se clasificó como Hipótesis')
            ->assertSee('Confianza: 85%')
            ->assertSee('También se evaluó como Nota de conocimiento')
            ->assertDontSee('Cargando detalle...');

        Http::assertSentCount(1); // sin segunda consulta
    }

    public function test_fc2_cargar_detalle_fetches_and_renders_explanation(): void
    {
        $user = $this->userWithQbkRepo();

        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42' => Http::response(
                $this->sessionDetail(42, $this->explicacion), 200),
            'localhost:8000/api/v1/contribute' => Http::response([
                'success' => true,
                'data' => ['session_id' => 42, 'status' => 'pendiente_revision', 'resumen' => 'OK'],
            ], 200),
        ]);

        Livewire::actingAs($user)->test(ContributeAporte::class)
            ->set('texto', 'El job falla porque el batch del banco no llega antes de las 6am')
            ->call('submit')
            ->assertSet('explicacion', null)
            ->call('cargarDetalleClasificacion')
            ->assertSee('Se clasificó como Hipótesis')
            ->assertSee('Confianza: 85%');
    }

    public function test_fc3_session_without_metadata_shows_honest_state(): void
    {
        $user = $this->userWithQbkRepo();

        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/7' => Http::response(
                $this->sessionDetail(7, null), 200),
            'localhost:8000/api/v1/contribute' => Http::response([
                'success' => true,
                'data' => ['session_id' => 7, 'status' => 'pendiente_revision', 'resumen' => 'OK'],
            ], 200),
        ]);

        Livewire::actingAs($user)->test(ContributeAporte::class)
            ->set('texto', 'Texto de aporte suficientemente largo para validar')
            ->call('submit')
            ->call('cargarDetalleClasificacion')
            ->assertSet('explicacion.sin_detalle', true)
            ->assertSee('Este aporte no tiene detalle de clasificación disponible.');
    }

    public function test_fc4_qubeka_failure_shows_legible_error_and_retry_recovers(): void
    {
        $user = $this->userWithQbkRepo();

        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42' => Http::sequence()
                ->push(['success' => false], 500)
                ->push($this->sessionDetail(42, $this->explicacion), 200),
            'localhost:8000/api/v1/contribute' => Http::response([
                'success' => true,
                'data' => ['session_id' => 42, 'status' => 'pendiente_revision', 'resumen' => 'OK'],
            ], 200),
        ]);

        Livewire::actingAs($user)->test(ContributeAporte::class)
            ->set('texto', 'El job falla porque el batch del banco no llega antes de las 6am')
            ->call('submit')
            ->call('cargarDetalleClasificacion')
            ->assertSet('detalleCargando', false)
            ->assertSee('QuBeKa respondió con error: 500')
            ->assertSee('Reintentar')
            // C.3 — el reintento (mismo flujo real) recupera el bloque.
            ->call('cargarDetalleClasificacion')
            ->assertSee('Se clasificó como Hipótesis');
    }

    public function test_fc4_timeout_al_expandir_muestra_error_legible(): void
    {
        $user = $this->userWithQbkRepo();

        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42' => fn () => throw new ConnectionException('Connection timed out'),
            'localhost:8000/api/v1/contribute' => Http::response([
                'success' => true,
                'data' => ['session_id' => 42, 'status' => 'pendiente_revision', 'resumen' => 'OK'],
            ], 200),
        ]);

        Livewire::actingAs($user)->test(ContributeAporte::class)
            ->set('texto', 'El job falla porque el batch del banco no llega antes de las 6am')
            ->call('submit')
            ->call('cargarDetalleClasificacion')
            ->assertSee('La conexión con QuBeKa tardó demasiado. Intentá de nuevo.')
            ->assertSee('Reintentar');
    }

    // ─── FD — Revisión Ola 1 y bandeja (Fase D) ───

    private function mockSessionService(array $data): void
    {
        $service = $this->createMock(QbkContributionService::class);
        $service->method('getSession')->willReturn($data);
        $this->app->instance(QbkContributionService::class, $service);
    }

    public function test_fd1_why_toggle_per_node_and_metadata_normalized(): void
    {
        $user = $this->userWithQbkRepo();

        $this->mockSessionService([
            'session_id' => 42,
            'status' => 'lista_para_revision',
            'is_simple' => true,
            'pregunta_previa' => null,
            'nodes' => [
                ['id' => 'sandbox_1', 'tipo' => 'H', 'texto' => 'Hipótesis uno', 'relaciones' => [], 'explicacion' => $this->explicacion],
                ['id' => 'sandbox_2', 'tipo' => 'N-K', 'texto' => 'Nota dos', 'relaciones' => []], // pre-despliegue
            ],
            'resumen' => 'Se propusieron 2 nodos.',
            'created_at' => '2026-09-01T10:30:00Z',
            'workspace_nombre' => 'WS',
        ]);

        Livewire::actingAs($user)->test(ContributionReview::class, ['sessionId' => 42])
            ->assertStatus(200)
            ->assertSee('Hipótesis uno')
            ->assertSee('Nota dos')
            // Toggle "¿Por qué?" presente por nodo (colapsado por defecto).
            ->assertSee('¿Por qué?');

        $component = Livewire::actingAs($user)->test(ContributionReview::class, ['sessionId' => 42]);
        $this->assertSame('H', $component->get('nodes.0.explicacion.decision_type'));
        $this->assertSame(0.85, $component->get('nodes.0.explicacion.confidence'));
        $this->assertTrue($component->get('nodes.1.explicacion.sin_detalle'));
    }

    public function test_fd2_approve_flow_unaffected_by_explicacion_block(): void
    {
        $user = $this->userWithQbkRepo();

        $service = $this->createMock(QbkContributionService::class);
        $service->method('getSession')->willReturn([
            'session_id' => 42,
            'status' => 'lista_para_revision',
            'is_simple' => true,
            'nodes' => [
                ['id' => 'sandbox_1', 'tipo' => 'H', 'texto' => 'T', 'relaciones' => [], 'explicacion' => $this->explicacion],
            ],
            'resumen' => 'OK',
            'created_at' => null,
            'workspace_nombre' => 'WS',
        ]);
        $service->method('approve')->willReturn([
            'success' => true, 'session_id' => 42, 'status' => 'aprobada', 'nodos_creados' => 0, 'enlaces_creados' => 0,
        ]);
        $this->app->instance(QbkContributionService::class, $service);

        // FK: contribution_drafts.user_id referencia users.uuid.
        ContributionDraft::create([
            'user_id' => $user->uuid,
            'repository_id' => Repository::first()->id,
            'qbk_session_id' => 42,
            'texto' => 'T',
            'status' => ContributionDraft::STATUS_SENT,
            'attempts' => 1,
        ]);

        Livewire::actingAs($user)->test(ContributionReview::class, ['sessionId' => 42])
            ->assertStatus(200)
            ->call('approve')
            ->assertSet('status', 'approved');

        $this->assertSame(
            ContributionDraft::STATUS_REVIEWED,
            ContributionDraft::where('qbk_session_id', 42)->first()->status,
        );
    }

    public function test_fd3_tray_why_button_loads_explanation_on_demand(): void
    {
        $user = $this->userWithQbkRepo();

        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42' => Http::response(
                $this->sessionDetail(42, $this->explicacion), 200),
            'localhost:8000/api/v1/sesiones-analisis*' => Http::response(
                $this->trayList([$this->trayItem(42)]), 200),
        ]);

        Livewire::actingAs($user)->test(ReviewTray::class)
            ->assertSee('¿Por qué?')
            ->call('cargarExplicacion', 42)
            ->assertSet('explicaciones.42.decision_type', 'H')
            ->assertSee('Se clasificó como Hipótesis');
    }

    public function test_fd3_tray_why_session_without_metadata_is_honest(): void
    {
        $user = $this->userWithQbkRepo();

        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/9' => Http::response(
                $this->sessionDetail(9, null), 200),
            'localhost:8000/api/v1/sesiones-analisis*' => Http::response(
                $this->trayList([$this->trayItem(9)]), 200),
        ]);

        Livewire::actingAs($user)->test(ReviewTray::class)
            ->call('cargarExplicacion', 9)
            ->assertSet('explicaciones.9.sin_detalle', true)
            ->assertSee('Este aporte no tiene detalle de clasificación disponible.');
    }

    public function test_fd3_tray_why_failure_is_visible_with_retry(): void
    {
        $user = $this->userWithQbkRepo();

        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42' => Http::response(['success' => false], 503),
            'localhost:8000/api/v1/sesiones-analisis*' => Http::response(
                $this->trayList([$this->trayItem(42)]), 200),
        ]);

        Livewire::actingAs($user)->test(ReviewTray::class)
            ->call('cargarExplicacion', 42)
            ->assertSee('QuBeKa respondió con error: 503')
            ->assertSee('Reintentar');
    }

    public function test_fd3_tray_why_navigates_history_without_error(): void
    {
        $user = $this->userWithQbkRepo();

        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis*' => Http::response(
                $this->trayList([]), 200),
        ]);

        Livewire::actingAs($user)->test(ReviewTray::class)
            ->call('switchEstado', 'historial')
            ->assertSet('estado', 'historial')
            ->assertStatus(200);
    }
}
