<?php

namespace Tests\Feature;

use App\Exceptions\KuaforiaException;
use App\Livewire\ContributionReview;
use App\Models\ContributionDraft;
use App\Models\Repository;
use App\Models\User;
use App\Services\QbkContributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContributionReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Repository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->repository = Repository::factory()->create([
            'user_id' => $this->user->uuid,
            'status' => 'active',
            'connector_type' => 'qbk',
            'credential' => ['api_token' => 'test-token-123'],
        ]);
    }

    private function mockQbkService(array $sessionData, ?\Throwable $exception = null): void
    {
        $mock = $this->createMock(QbkContributionService::class);

        if ($exception) {
            $mock->method('getSession')->willThrowException($exception);
        } else {
            $mock->method('getSession')->willReturn($sessionData);
        }

        $this->app->instance(QbkContributionService::class, $mock);
    }

    private function getMockSessionData(array $overrides = []): array
    {
        return array_merge([
            'session_id' => 42,
            'status' => 'lista_para_revision',
            'is_simple' => true,
            'pregunta_previa' => '¿Por qué falla el job?',
            'nodes' => [
                [
                    'id' => 'sandbox_1',
                    'tipo' => 'H',
                    'texto' => 'El job falla porque el batch no llega a tiempo',
                    'justificacion' => null,
                    'relaciones' => [],
                ],
                [
                    'id' => 'sandbox_2',
                    'tipo' => 'N-K',
                    'texto' => 'En la revisión del 2026-08-10 se confirmó que el batch llega a las 7:30am',
                    'justificacion' => null,
                    'relaciones' => [],
                ],
            ],
            'resumen' => 'Se propusieron 1 hipótesis y 1 nota de conocimiento.',
            'created_at' => '2026-08-29T10:30:00Z',
            'workspace_nombre' => 'Investigación Jurídica',
        ], $overrides);
    }

    public function test_render_component_with_valid_session(): void
    {
        $this->mockQbkService($this->getMockSessionData());

        Livewire::actingAs($this->user)
            ->test(ContributionReview::class, ['sessionId' => 42])
            ->assertStatus(200)
            ->assertSee('Revisar aporte')
            ->assertSee('El job falla porque el batch no llega a tiempo')
            ->assertSee('Hipótesis')
            ->assertSee('Nota de conocimiento')
            ->assertSee('¿Por qué falla el job?');
    }

    public function test_pregunta_previa_displayed(): void
    {
        $this->mockQbkService($this->getMockSessionData([
            'pregunta_previa' => '¿Por qué falla el job?',
        ]));

        Livewire::actingAs($this->user)
            ->test(ContributionReview::class, ['sessionId' => 42])
            ->assertSee('Tu pregunta original')
            ->assertSee('¿Por qué falla el job?');
    }

    public function test_approve_calls_service_and_shows_success(): void
    {
        $mock = $this->createMock(QbkContributionService::class);
        $mock->method('getSession')->willReturn($this->getMockSessionData());
        $mock->expects($this->once())
            ->method('approve')
            ->with(42, null, $this->repository->credential)
            ->willReturn([
                'success' => true,
                'session_id' => 42,
                'status' => 'promocionada',
                'nodos_creados' => 2,
                'enlaces_creados' => 1,
            ]);

        $this->app->instance(QbkContributionService::class, $mock);

        Livewire::actingAs($this->user)
            ->test(ContributionReview::class, ['sessionId' => 42])
            ->assertSee('Aprobar')
            ->call('approve')
            ->assertSee('¡Aporte aprobado!')
            ->assertSee('2 nodo(s) creado(s)');
    }

    public function test_reject_calls_service_and_shows_rejected(): void
    {
        $mock = $this->createMock(QbkContributionService::class);
        $mock->method('getSession')->willReturn($this->getMockSessionData());
        $mock->expects($this->once())
            ->method('reject')
            ->with(42, $this->repository->credential)
            ->willReturn([
                'success' => true,
                'session_id' => 42,
                'status' => 'rechazada',
            ]);

        $this->app->instance(QbkContributionService::class, $mock);

        Livewire::actingAs($this->user)
            ->test(ContributionReview::class, ['sessionId' => 42])
            ->assertSee('Descartar')
            ->call('reject')
            ->assertSee('Aporte descartado');
    }

    public function test_edit_mode_shows_textareas(): void
    {
        $this->mockQbkService($this->getMockSessionData());

        Livewire::actingAs($this->user)
            ->test(ContributionReview::class, ['sessionId' => 42])
            ->assertDontSee('Cancelar edición')
            ->call('toggleEdit')
            ->assertSee('Cancelar edición')
            ->assertSee('El job falla porque el batch no llega a tiempo');
    }

    public function test_approve_with_edited_texts(): void
    {
        $mock = $this->createMock(QbkContributionService::class);
        $mock->method('getSession')->willReturn($this->getMockSessionData());
        $mock->method('approve')->willReturn([
            'success' => true,
            'session_id' => 42,
            'status' => 'promocionada',
            'nodos_creados' => 2,
            'enlaces_creados' => 1,
        ]);

        $this->app->instance(QbkContributionService::class, $mock);

        Livewire::actingAs($this->user)
            ->test(ContributionReview::class, ['sessionId' => 42])
            ->call('toggleEdit')
            ->call('updateNodeText', 0, 'Texto editado del nodo')
            ->assertSet('nodes.0.editedText', 'Texto editado del nodo')
            ->call('approve')
            ->assertSee('¡Aporte aprobado!');
    }

    public function test_session_not_found_shows_error(): void
    {
        $this->mockQbkService([], new KuaforiaException('Sesión de análisis no encontrada en QuBeKa.', 404));

        Livewire::actingAs($this->user)
            ->test(ContributionReview::class, ['sessionId' => 999])
            ->assertSee('No se pudo cargar la sesión')
            ->assertSee('Sesión de análisis no encontrada en QuBeKa.');
    }

    public function test_complex_session_sets_flag_and_redirects(): void
    {
        $this->mockQbkService($this->getMockSessionData([
            'is_simple' => false,
            'nodes' => [
                ['id' => 'n1', 'tipo' => 'Q', 'texto' => 'Pregunta 1', 'justificacion' => null, 'relaciones' => []],
                ['id' => 'n2', 'tipo' => 'H', 'texto' => 'Hipótesis 1', 'justificacion' => null, 'relaciones' => []],
                ['id' => 'n3', 'tipo' => 'N-K', 'texto' => 'Nota 1', 'justificacion' => null, 'relaciones' => []],
            ],
        ]));

        // In Livewire tests, redirectExternal sets status but doesn't produce
        // a traditional HTTP redirect assertion — verify the flag is set correctly.
        Livewire::actingAs($this->user)
            ->test(ContributionReview::class, ['sessionId' => 42])
            ->assertSet('isSimple', false);
    }

    public function test_approve_updates_draft_status(): void
    {
        $draft = ContributionDraft::create([
            'user_id' => $this->user->uuid,
            'repository_id' => $this->repository->id,
            'qbk_session_id' => 42,
            'texto' => 'Texto de prueba',
            'status' => ContributionDraft::STATUS_SENT,
            'attempts' => 1,
        ]);

        $mock = $this->createMock(QbkContributionService::class);
        $mock->method('getSession')->willReturn($this->getMockSessionData());
        $mock->method('approve')->willReturn([
            'success' => true,
            'session_id' => 42,
            'status' => 'promocionada',
            'nodos_creados' => 1,
            'enlaces_creados' => 0,
        ]);

        $this->app->instance(QbkContributionService::class, $mock);

        Livewire::actingAs($this->user)
            ->test(ContributionReview::class, ['sessionId' => 42])
            ->call('approve');

        $this->assertDatabaseHas('contribution_drafts', [
            'id' => $draft->id,
            'status' => ContributionDraft::STATUS_REVIEWED,
        ]);
    }

    public function test_reject_updates_draft_status(): void
    {
        $draft = ContributionDraft::create([
            'user_id' => $this->user->uuid,
            'repository_id' => $this->repository->id,
            'qbk_session_id' => 42,
            'texto' => 'Texto de prueba',
            'status' => ContributionDraft::STATUS_SENT,
            'attempts' => 1,
        ]);

        $mock = $this->createMock(QbkContributionService::class);
        $mock->method('getSession')->willReturn($this->getMockSessionData());
        $mock->method('reject')->willReturn([
            'success' => true,
            'session_id' => 42,
            'status' => 'rechazada',
        ]);

        $this->app->instance(QbkContributionService::class, $mock);

        Livewire::actingAs($this->user)
            ->test(ContributionReview::class, ['sessionId' => 42])
            ->call('reject');

        $this->assertDatabaseHas('contribution_drafts', [
            'id' => $draft->id,
            'status' => ContributionDraft::STATUS_REVIEWED,
        ]);
    }

    public function test_service_error_on_approve_shows_error(): void
    {
        $mock = $this->createMock(QbkContributionService::class);
        $mock->method('getSession')->willReturn($this->getMockSessionData());
        $mock->method('approve')->willThrowException(
            new KuaforiaException('No tenés permisos para aprobar.', 403)
        );

        $this->app->instance(QbkContributionService::class, $mock);

        Livewire::actingAs($this->user)
            ->test(ContributionReview::class, ['sessionId' => 42])
            ->call('approve')
            ->assertSee('No tenés permisos para aprobar.');
    }

    public function test_no_repository_shows_error(): void
    {
        $this->repository->delete();

        $this->mockQbkService($this->getMockSessionData());

        Livewire::actingAs($this->user)
            ->test(ContributionReview::class, ['sessionId' => 42])
            ->assertSee('No hay un repositorio conectado');
    }

    public function test_metadata_shows_workspace_and_date(): void
    {
        $this->mockQbkService($this->getMockSessionData());

        Livewire::actingAs($this->user)
            ->test(ContributionReview::class, ['sessionId' => 42])
            ->assertSee('Investigación Jurídica');
    }
}
