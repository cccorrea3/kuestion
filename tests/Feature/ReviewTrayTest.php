<?php

namespace Tests\Feature;

use App\Livewire\ReviewTray;
use App\Models\ContributionDraft;
use App\Models\Question;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ReviewTrayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.qubeka.api_url' => 'http://localhost:8000/api/v1']);
        config(['services.qubeka.base_url' => 'http://localhost:8000']);
    }

    private function fakeList(?array $items): void
    {
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis*' => Http::response([
                'success' => true,
                'data' => $items ?? [],
                'meta' => [
                    'page' => 1,
                    'per_page' => 20,
                    'total' => count($items ?? []),
                    'last_page' => 1,
                ],
            ], 200),
        ]);
    }

    private function pendingItem(int $id = 42, array $extra = []): array
    {
        return array_merge([
            'session_id' => $id,
            'status' => 'lista_para_revision',
            'fecha_creacion' => '2026-08-29T10:30:00Z',
            'texto_original_del_aporte' => 'El batch del banco no llega antes de las 6am',
            'resumen_clasificacion' => 'Se propuso 1 hipótesis, pendiente de revisión.',
            'is_simple' => true,
            'pregunta_previa' => '¿Por qué falla el job?',
            'autor_email' => null,
            'autor_nombre' => 'Juan Pérez',
            'fecha_decision' => null,
        ], $extra);
    }

    public function test_review_tray_loads_pending_items(): void
    {
        $user = $this->createUser();
        $this->createRepositoryForUser($user, token: '2|test_token_123');

        $this->fakeList([$this->pendingItem()]);

        $response = $this->actingAs($user)->get(route('reviews.index'));

        $response->assertOk();
        $response->assertSee('El batch del banco no llega antes de las 6am');
        $response->assertSee('Se propuso 1 hipótesis, pendiente de revisión.');
        $response->assertSee('Aprobar');
        $response->assertSee('Rechazar');
        $response->assertSee('Pendiente');

        // B.2 — el estado en lenguaje natural cubre los estados técnicos de QuBeKa.
        foreach (['creada', 'procesando', 'lista_para_revision', 'pendiente_revision'] as $raw) {
            $this->assertSame('Pendiente', ReviewTray::estadoLegible($raw));
        }
        $this->assertSame('Aprobado', ReviewTray::estadoLegible('aprobada'));
        $this->assertSame('Aprobado', ReviewTray::estadoLegible('promocionada'));
        $this->assertSame('Rechazado', ReviewTray::estadoLegible('rechazada'));
        $this->assertSame('Error', ReviewTray::estadoLegible('error'));

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'http://localhost:8000/api/v1/sesiones-analisis')
                && $request->data()['estado'] === 'pendientes';
        });
    }

    public function test_review_tray_parses_real_qubeka_shape(): void
    {
        $user = $this->createUser();
        $this->createRepositoryForUser($user, token: '2|test_token_123');

        // QuBeKa real devuelve data = arreglo plano de ítems + meta (no data.items).
        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis*' => Http::response([
                'success' => true,
                'data' => [$this->pendingItem(7)],
                'meta' => ['page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1],
            ], 200),
        ]);

        $component = Livewire::actingAs($user)->test(ReviewTray::class);

        $component->assertOk();
        $component->assertSee('El batch del banco no llega antes de las 6am');
        $component->assertSet('total', 1);
    }

    public function test_review_tray_maps_legacy_field_names(): void
    {
        $user = $this->createUser();
        $this->createRepositoryForUser($user, token: '2|test_token_123');

        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis*' => Http::response([
                'success' => true,
                'data' => [[
                    'session_id' => 3,
                    'status' => 'lista_para_revision',
                    'creado_en' => '2026-08-29T10:30:00Z',
                    'contenido_entrada' => 'Texto con naming legacy',
                    'resumen' => 'Resumen legacy',
                    'is_simple' => false,
                    'pregunta_previa' => null,
                    'autor_email' => null,
                    'autor_nombre' => null,
                    'cerrado_en' => null,
                ]],
                'meta' => ['page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1],
            ], 200),
        ]);

        $component = Livewire::actingAs($user)->test(ReviewTray::class);

        $component->assertOk();
        $component->assertSee('Texto con naming legacy');
        $component->assertSee('Resumen legacy');
    }

    public function test_review_tray_shows_empty_state_when_no_pending(): void
    {
        $user = $this->createUser();
        $this->createRepositoryForUser($user, token: '2|test_token_123');

        $this->fakeList([]);

        $response = $this->actingAs($user)->get(route('reviews.index'));

        $response->assertOk();
        $response->assertSee('No hay aportes pendientes de confirmar');
    }

    public function test_review_tray_shows_error_when_no_repository(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->get(route('reviews.index'));

        $response->assertOk();
        $response->assertSee('No hay un repositorio conectado para acceder a la bandeja');
    }

    public function test_review_tray_shows_error_when_qubeka_down(): void
    {
        $user = $this->createUser();
        $this->createRepositoryForUser($user, token: '2|test_token_123');

        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis*' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $response = $this->actingAs($user)->get(route('reviews.index'));

        $response->assertOk();
        $response->assertSee('No se pudo cargar la bandeja');
        $response->assertSee('Reintentar');
    }

    public function test_401_marks_repository_invalid(): void
    {
        $user = $this->createUser();
        $repo = $this->createRepositoryForUser($user, token: '2|test_token_123');

        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis*' => Http::response([
                'success' => false,
                'errors' => ['message' => 'Invalid token'],
            ], 401),
        ]);

        Livewire::actingAs($user)->test(ReviewTray::class);

        $this->assertDatabaseHas('repositories', [
            'id' => $repo->id,
            'status' => 'invalid',
        ]);
    }

    public function test_history_tab_shows_processed_items(): void
    {
        $user = $this->createUser();
        $this->createRepositoryForUser($user, token: '2|test_token_123');

        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis*' => Http::response([
                'success' => true,
                'data' => [
                    $this->pendingItem(42, ['texto_original_del_aporte' => 'Aporte nuevo']),
                    [
                        'session_id' => 99,
                        'status' => 'aprobada',
                        'fecha_creacion' => '2026-09-05T08:00:00Z',
                        'texto_original_del_aporte' => 'Aporte aprobado',
                        'resumen_clasificacion' => 'Resumen aprobado',
                        'is_simple' => true,
                        'pregunta_previa' => null,
                        'autor_email' => null,
                        'autor_nombre' => null,
                        'fecha_decision' => '2026-09-05T08:05:00Z',
                    ],
                ],
                'meta' => ['page' => 1, 'per_page' => 20, 'total' => 2, 'last_page' => 1],
            ], 200),
        ]);

        $component = Livewire::actingAs($user)
            ->test(ReviewTray::class)
            ->call('switchEstado', 'historial');

        $component->assertOk();
        $component->assertSee('Aporte aprobado');
        $component->assertSee('Aprobado');

        Http::assertSent(fn ($request) => $request->data()['estado'] === 'historial');
    }

    public function test_approve_removes_item_and_syncs_draft(): void
    {
        $user = $this->createUser();
        $repo = $this->createRepositoryForUser($user, token: '2|test_token_123');

        $draft = ContributionDraft::create([
            'user_id' => $user->uuid,
            'repository_id' => $repo->id,
            'qbk_session_id' => 42,
            'texto' => 'Texto de prueba',
            'status' => ContributionDraft::STATUS_SENT,
            'attempts' => 1,
        ]);

        $this->fakeList([$this->pendingItem()]);

        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42/approve' => Http::response([
                'success' => true,
                'session_id' => 42,
                'status' => 'aprobada',
            ], 200),
            'localhost:8000/api/v1/sesiones-analisis?*' => Http::response([
                'success' => true,
                'data' => [],
                'meta' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1],
            ], 200),
        ]);

        Livewire::actingAs($user)
            ->test(ReviewTray::class)
            ->call('aprobar', 42);

        $this->assertDatabaseHas('contribution_drafts', [
            'id' => $draft->id,
            'status' => ContributionDraft::STATUS_REVIEWED,
        ]);

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/sesiones-analisis/42/approve')
                && $request->method() === 'POST';
        });
    }

    public function test_approve_error_keeps_item_pending(): void
    {
        $user = $this->createUser();
        $this->createRepositoryForUser($user, token: '2|test_token_123');

        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42/approve' => Http::response('Server Error', 500),
            'localhost:8000/api/v1/sesiones-analisis?*' => Http::response([
                'success' => true,
                'data' => [$this->pendingItem()],
                'meta' => ['page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1],
            ], 200),
        ]);

        Livewire::actingAs($user)
            ->test(ReviewTray::class)
            ->call('aprobar', 42)
            ->assertSet('error', 'QuBeKa respondió con error: 500')
            ->assertSet('total', 1);
    }

    public function test_reject_removes_item_and_syncs_draft(): void
    {
        $user = $this->createUser();
        $repo = $this->createRepositoryForUser($user, token: '2|test_token_123');

        $draft = ContributionDraft::create([
            'user_id' => $user->uuid,
            'repository_id' => $repo->id,
            'qbk_session_id' => 42,
            'texto' => 'Texto de prueba',
            'status' => ContributionDraft::STATUS_SENT,
            'attempts' => 1,
        ]);

        $this->fakeList([$this->pendingItem()]);

        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42/reject' => Http::response([
                'success' => true,
                'session_id' => 42,
                'status' => 'rechazada',
            ], 200),
            'localhost:8000/api/v1/sesiones-analisis?*' => Http::response([
                'success' => true,
                'data' => [],
                'meta' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1],
            ], 200),
        ]);

        Livewire::actingAs($user)
            ->test(ReviewTray::class)
            ->call('rechazar', 42);

        $this->assertDatabaseHas('contribution_drafts', [
            'id' => $draft->id,
            'status' => ContributionDraft::STATUS_REVIEWED,
        ]);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sesiones-analisis/42/reject'));
    }

    public function test_edit_simple_session_opens_inline_editor(): void
    {
        $user = $this->createUser();
        $this->createRepositoryForUser($user, token: '2|test_token_123');

        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42' => Http::response([
                'success' => true,
                'data' => [
                    'session_id' => 42,
                    'status' => 'lista_para_revision',
                    'is_simple' => true,
                    'pregunta_previa' => null,
                    'nodes' => [
                        ['id' => 'sandbox_1', 'tipo' => 'H', 'texto' => 'El batch no llega', 'relaciones' => []],
                    ],
                    'resumen' => 'Se propuso 1 hipótesis.',
                    'created_at' => '2026-08-29T10:30:00Z',
                    'workspace_nombre' => 'Investigación Jurídica',
                ],
            ], 200),
            'localhost:8000/api/v1/sesiones-analisis?*' => Http::response([
                'success' => true,
                'data' => [$this->pendingItem()],
                'meta' => ['page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1],
            ], 200),
        ]);

        $component = Livewire::actingAs($user)
            ->test(ReviewTray::class)
            ->call('edit', 42);

        $component->assertSet('editing', true);
        $component->assertSet('editingSessionId', 42);
        $component->assertCount('editingNodes', 1);
        $component->assertSee('Ajustar texto propuesto');
        $component->assertSee('Guardar y aprobar');
    }

    public function test_edit_complex_session_keeps_editor_closed(): void
    {
        $user = $this->createUser();
        $this->createRepositoryForUser($user, token: '2|test_token_123');

        $this->fakeList([$this->pendingItem(42, ['is_simple' => false])]);

        $component = Livewire::actingAs($user)
            ->test(ReviewTray::class)
            ->call('edit', 42);

        // Sesión compleja: no abre editor (redirige a QuBeKa, patrón ContributionReview).
        $component->assertSet('editing', false);
    }

    public function test_guardar_ajustes_sends_textos_ajustados_and_removes(): void
    {
        $user = $this->createUser();
        $repo = $this->createRepositoryForUser($user, token: '2|test_token_123');

        $draft = ContributionDraft::create([
            'user_id' => $user->uuid,
            'repository_id' => $repo->id,
            'qbk_session_id' => 42,
            'texto' => 'Texto de prueba',
            'status' => ContributionDraft::STATUS_SENT,
            'attempts' => 1,
        ]);

        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis/42' => Http::response([
                'success' => true,
                'data' => [
                    'session_id' => 42,
                    'status' => 'lista_para_revision',
                    'is_simple' => true,
                    'pregunta_previa' => null,
                    'nodes' => [
                        ['id' => 'sandbox_1', 'tipo' => 'H', 'texto' => 'El batch no llega', 'relaciones' => []],
                    ],
                    'resumen' => 'Se propuso 1 hipótesis.',
                    'created_at' => '2026-08-29T10:30:00Z',
                    'workspace_nombre' => 'Investigación Jurídica',
                ],
            ], 200),
            'localhost:8000/api/v1/sesiones-analisis/42/approve' => Http::response([
                'success' => true,
                'session_id' => 42,
                'status' => 'aprobada',
            ], 200),
            'localhost:8000/api/v1/sesiones-analisis?*' => Http::response([
                'success' => true,
                'data' => [$this->pendingItem()],
                'meta' => ['page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1],
            ], 200),
        ]);

        Livewire::actingAs($user)
            ->test(ReviewTray::class)
            ->call('edit', 42)
            ->set('editingNodes.0.editedText', 'Texto ajustado')
            ->call('guardarAjustes')
            ->assertSet('editing', false);

        $this->assertDatabaseHas('contribution_drafts', [
            'id' => $draft->id,
            'status' => ContributionDraft::STATUS_REVIEWED,
        ]);

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/sesiones-analisis/42/approve')
                && $request->data()['textos_ajustados'] === ['sandbox_1' => 'Texto ajustado'];
        });
    }

    public function test_go_to_page_requests_next_page(): void
    {
        $user = $this->createUser();
        $this->createRepositoryForUser($user, token: '2|test_token_123');

        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis*' => Http::response([
                'success' => true,
                'data' => [],
                'meta' => ['page' => 1, 'per_page' => 20, 'total' => 25, 'last_page' => 2],
            ], 200),
        ]);

        $component = Livewire::actingAs($user)
            ->test(ReviewTray::class)
            ->assertSet('total', 25);

        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis*' => Http::response([
                'success' => true,
                'data' => [],
                'meta' => ['page' => 2, 'per_page' => 20, 'total' => 25, 'last_page' => 2],
            ], 200),
        ]);

        $component->call('goToPage', 2);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'http://localhost:8000/api/v1/sesiones-analisis')
                && (int) $request->data()['page'] === 2;
        });
    }

    public function test_go_to_page_ignores_out_of_range(): void
    {
        $user = $this->createUser();
        $this->createRepositoryForUser($user, token: '2|test_token_123');

        Http::fake([
            'localhost:8000/api/v1/sesiones-analisis*' => Http::response([
                'success' => true,
                'data' => [],
                'meta' => ['page' => 1, 'per_page' => 20, 'total' => 25, 'last_page' => 2],
            ], 200),
        ]);

        $component = Livewire::actingAs($user)
            ->test(ReviewTray::class)
            ->call('goToPage', 99)
            ->assertSet('page', 1);
    }

    private function createUser(): object
    {
        return User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }

    // ------------------------------------------------------------------
    // Ola 2, Punto 2 — Fase D: pestaña "Pendientes de reconfirmar"
    // ------------------------------------------------------------------

    private function createExpiredQbkQuestion(object $user, int $diasAtras = 95): object
    {
        $repo = Repository::factory()->create([
            'name' => 'QBK Vigencia Repo',
            'user_id' => $user->uuid,
            'status' => 'active',
            'connector_type' => 'qbk',
            'credential' => ['api_token' => '2|test_token_123'],
        ]);

        $question = Question::factory()->create([
            'user_id' => $user->uuid,
            'repository_id' => $repo->id,
        ]);

        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => 'Respuesta test',
            'confidence' => 80,
            'sources' => [
                ['node_id' => 'NK-001', 'tipo' => 'N-K', 'fecha_ultima_confirmacion' => now()->subDays($diasAtras)->toIso8601String()],
            ],
            'response_hash' => hash('sha256', 'Respuesta test'),
            'is_current' => true,
            'found' => true,
        ]);

        return $question->refresh();
    }

    public function test_reconfirmar_tab_lista_preguntas_vencidas(): void
    {
        $user = User::factory()->create();
        $this->createRepositoryForUser($user, token: '2|test_token_123');
        $this->createExpiredQbkQuestion($user, 95);

        Livewire::actingAs($user)
            ->test(ReviewTray::class)
            ->call('switchEstado', 'reconfirmar')
            ->assertSet('estado', 'reconfirmar')
            ->assertSee('Pendientes de reconfirmar')
            ->assertSee('Sin reconfirmar desde hace 95 días')
            ->assertSee('Reconfirmar');
    }

    public function test_reconfirmar_tab_vacia_cuando_no_hay_vencidos(): void
    {
        $user = User::factory()->create();
        $this->createRepositoryForUser($user, token: '2|test_token_123');

        // Pregunta dentro del umbral: no aparece en la pestaña.
        $this->createExpiredQbkQuestion($user, 10);

        Livewire::actingAs($user)
            ->test(ReviewTray::class)
            ->call('switchEstado', 'reconfirmar')
            ->assertSee('Nada pendiente de reconfirmar');
    }

    public function test_reconfirmar_desde_la_pestaña_llama_patch_y_saca_el_item(): void
    {
        $user = User::factory()->create();
        $this->createRepositoryForUser($user, token: '2|test_token_123');
        $question = $this->createExpiredQbkQuestion($user, 95);

        Http::fake([
            'localhost:8000/api/v1/nodos/NK-001/reconfirmar' => Http::response([
                'success' => true,
                'data' => ['node_id' => 'NK-001', 'fecha_ultima_confirmacion' => now()->toIso8601String()],
            ], 200),
        ]);

        $component = Livewire::actingAs($user)
            ->test(ReviewTray::class)
            ->call('switchEstado', 'reconfirmar')
            ->call('reconfirmarPregunta', $question->id);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/nodos/NK-001/reconfirmar')
            && $request->method() === 'PATCH');

        // D.2 — tras reconfirmar, la vigencia ya no está vencida → el ítem sale de la lista.
        $this->assertEmpty($component->get('vencidas'));
        $this->assertNull($component->get('error'));
    }

    public function test_reconfirmar_desde_la_pestaña_muestra_error_legible(): void
    {
        $user = User::factory()->create();
        $this->createRepositoryForUser($user, token: '2|test_token_123');
        $question = $this->createExpiredQbkQuestion($user, 95);

        Http::fake([
            'localhost:8000/api/v1/nodos/NK-001/reconfirmar' => Http::response([
                'success' => false,
                'errors' => ['message' => 'Forbidden'],
            ], 403),
        ]);

        Livewire::actingAs($user)
            ->test(ReviewTray::class)
            ->call('switchEstado', 'reconfirmar')
            ->call('reconfirmarPregunta', $question->id)
            ->assertSee('No tenés permiso para reconfirmar este conocimiento');
    }

    private function createRepositoryForUser(object $user, string $token): object
    {
        return Repository::factory()->create([
            'name' => 'QBK Test Repo',
            'user_id' => $user->uuid,
            'status' => 'active',
            'is_default' => true,
            'credential' => ['api_token' => $token],
        ]);
    }
}
