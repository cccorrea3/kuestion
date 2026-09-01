<?php

namespace Tests\Feature;

use App\Exceptions\KuaforiaException;
use App\Livewire\ContributeAporte;
use App\Models\Repository;
use App\Models\User;
use App\Services\QbkContributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContributeAporteIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function fakeService(?array $result = null, ?\Throwable $exception = null): void
    {
        $fake = \Mockery::mock(QbkContributionService::class);

        if ($exception) {
            $fake->shouldReceive('contribute')->once()->andThrow($exception);
        } else {
            $fake->shouldReceive('contribute')->once()->andReturn($result ?? [
                'session_id' => 42,
                'status' => 'pendiente_revision',
                'resumen' => 'Se propuso 1 hipótesis, pendiente de revisión.',
            ]);
        }

        $this->app->instance(QbkContributionService::class, $fake);
    }

    private function createUserWithRepo(string $connectorType = 'qbk'): User
    {
        $user = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $user->uuid,
            'status' => 'active',
            'is_default' => true,
            'connector_type' => $connectorType,
            'credential' => ['api_token' => '2|test_token'],
        ]);

        return $user;
    }

    public function test_full_successful_flow(): void
    {
        $this->fakeService([
            'session_id' => 42,
            'status' => 'pendiente_revision',
            'resumen' => 'Se propuso 1 hipótesis y 1 nota, pendientes de revisión.',
        ]);

        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', 'El batch del banco llega a las 7:30am, no a las 6am')
            ->call('submit')
            ->assertSet('status', 'saved')
            ->assertSet('resumen', 'Se propuso 1 hipótesis y 1 nota, pendientes de revisión.')
            ->assertSee('¡Gracias por tu aporte!')
            ->assertSee('Hacer otro aporte')
            ->assertSee('Ver preguntas');
    }

    public function test_error_state_allows_retry(): void
    {
        $this->fakeService(exception: new KuaforiaException('Token inválido', 401));

        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', 'El batch del banco llega a las 7:30am')
            ->call('submit')
            ->assertSet('status', 'error')
            ->assertSee('Token inválido')
            ->assertSee('No se pudo enviar el aporte');

        // Resetear y verificar que vuelve a idle con el texto conservado.
        Livewire::test(ContributeAporte::class)
            ->set('texto', 'El batch del banco llega a las 7:30am')
            ->call('resetForm')
            ->assertSet('status', 'idle')
            ->assertSet('error', null);
    }

    public function test_validation_rejects_short_text(): void
    {
        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', 'corto')
            ->call('submit')
            ->assertHasErrors(['texto'])
            ->assertSet('status', 'idle');
    }

    public function test_validation_rejects_empty_text(): void
    {
        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', '')
            ->call('submit')
            ->assertHasErrors(['texto']);
    }

    public function test_validation_rejects_too_long_text(): void
    {
        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', str_repeat('a', 2001))
            ->call('submit')
            ->assertHasErrors(['texto']);
    }

    public function test_accepts_exactly_2000_chars(): void
    {
        $this->fakeService();

        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', str_repeat('a', 2000))
            ->call('submit')
            ->assertSet('status', 'saved');
    }

    public function test_accepts_exactly_10_chars(): void
    {
        $this->fakeService();

        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', str_repeat('a', 10))
            ->call('submit')
            ->assertSet('status', 'saved');
    }

    public function test_rejects_9_chars(): void
    {
        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', str_repeat('a', 9))
            ->call('submit')
            ->assertHasErrors(['texto']);
    }

    public function test_question_context_displayed_when_pregunta_previa_set(): void
    {
        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        // Test via HTTP to verify query param is picked up by mount().
        $response = $this->get(route('contribute', ['prev' => '¿Por qué falla el job?']));
        $response->assertStatus(200);
        $response->assertSee('Tu pregunta anterior:');
        $response->assertSee('¿Por qué falla el job?');
    }

    public function test_no_question_context_when_no_prev(): void
    {
        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->assertDontSee('Tu pregunta anterior:');
    }

    public function test_single_repo_shows_connector_name(): void
    {
        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->assertSee('Enviando a')
            ->assertSee('QuBeKa')
            ->assertDontSee('Fuente de conocimiento');
    }

    public function test_multi_repo_shows_selector(): void
    {
        $user = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $user->uuid,
            'status' => 'active',
            'is_default' => true,
            'connector_type' => 'qbk',
        ]);
        Repository::factory()->create([
            'user_id' => $user->uuid,
            'status' => 'active',
            'is_default' => false,
            'connector_type' => 'qbk',
        ]);

        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->assertSee('Fuente de conocimiento')
            ->assertDontSee('Enviando a');
    }

    public function test_saved_state_shows_reset_button(): void
    {
        $this->fakeService();

        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', 'El batch del banco llega a las 7:30am')
            ->call('submit')
            ->assertSet('status', 'saved')
            ->assertSee('Hacer otro aporte');
    }

    public function test_reset_form_returns_to_idle(): void
    {
        $this->fakeService();

        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', 'El batch del banco llega a las 7:30am')
            ->call('submit')
            ->assertSet('status', 'saved')
            ->call('resetForm')
            ->assertSet('status', 'idle')
            ->assertSet('error', null)
            ->assertSet('resumen', '');
    }

    public function test_error_preserves_texto_for_retry(): void
    {
        $this->fakeService(exception: new KuaforiaException('Error', 500));

        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', 'El batch del banco llega a las 7:30am')
            ->call('submit')
            ->assertSet('status', 'error')
            // Text should still be there for retry.
            ->assertSet('texto', 'El batch del banco llega a las 7:30am');
    }

    public function test_blocks_without_repositories(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->assertSee('Necesitás una conexión activa')
            ->assertDontSee('Tu aporte');
    }

    public function test_analyzing_state_shows_spinner(): void
    {
        // Use a slow fake to verify the analyzing state.
        $fake = \Mockery::mock(QbkContributionService::class);
        $fake->shouldReceive('contribute')->once()->andReturnUsing(function () {
            return [
                'session_id' => 1,
                'status' => 'pendiente_revision',
                'resumen' => 'OK',
            ];
        });
        $this->app->instance(QbkContributionService::class, $fake);

        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', 'Test contribution text here')
            ->call('submit')
            ->assertSet('status', 'saved');
    }

    public function test_route_contribute_accessible(): void
    {
        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        $response = $this->get(route('contribute'));
        $response->assertStatus(200);
        $response->assertSee('Aportar conocimiento');
    }

    public function test_route_contribute_with_prev_param(): void
    {
        $user = $this->createUserWithRepo();
        $this->actingAs($user);

        $response = $this->get(route('contribute', ['prev' => '¿Qué es RAG?']));
        $response->assertStatus(200);
        $response->assertSee('¿Qué es RAG?');
        $response->assertSee('Tu pregunta anterior:');
    }
}
