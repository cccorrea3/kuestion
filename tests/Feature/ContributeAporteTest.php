<?php

namespace Tests\Feature;

use App\Livewire\ContributeAporte;
use App\Models\Repository;
use App\Models\User;
use App\Services\QbkIdentityResolver;
use App\Services\QbkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ContributeAporteTest extends TestCase
{
    use RefreshDatabase;

    private function fakeQbkConfig(): void
    {
        config(['kuestion.connectors.qbk' => [
            'display_name' => 'QuBeKa',
            'description' => 'Plataforma de conocimiento QuBeKa',
            'auth_fields' => [
                ['key' => 'api_token', 'label' => 'Token de agente', 'hint' => ''],
            ],
            'help_url' => null,
            'identity_resolver' => QbkIdentityResolver::class,
            'rag_provider' => QbkService::class,
            'signal_provider' => null,
        ]]);
    }

    public function test_renders_form_with_active_repository(): void
    {
        $this->fakeQbkConfig();

        $user = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $user->uuid,
            'status' => 'active',
            'is_default' => true,
            'connector_type' => 'qbk',
        ]);

        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->assertStatus(200)
            ->assertSee('Aportar conocimiento')
            ->assertSee('Tu aporte');
    }

    public function test_blocks_without_active_repositories(): void
    {
        $this->fakeQbkConfig();

        $user = User::factory()->create();
        Repository::factory()->create(['user_id' => $user->uuid, 'status' => 'revoked']);

        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->assertSee('Necesitás una conexión activa');
    }

    public function test_validates_min_length(): void
    {
        $this->fakeQbkConfig();

        $user = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $user->uuid,
            'status' => 'active',
            'is_default' => true,
            'connector_type' => 'qbk',
        ]);

        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', 'corto')
            ->call('submit')
            ->assertHasErrors(['texto']);
    }

    public function test_validates_max_length(): void
    {
        $this->fakeQbkConfig();

        $user = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $user->uuid,
            'status' => 'active',
            'is_default' => true,
            'connector_type' => 'qbk',
        ]);

        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', str_repeat('a', 2001))
            ->call('submit')
            ->assertHasErrors(['texto']);
    }

    public function test_successful_contribution(): void
    {
        $this->fakeQbkConfig();

        $user = User::factory()->create();
        $repo = Repository::factory()->create([
            'user_id' => $user->uuid,
            'status' => 'active',
            'is_default' => true,
            'connector_type' => 'qbk',
            'credential' => ['api_token' => '2|test_token_123'],
        ]);

        Http::fake([
            config('services.qubeka.api_url').'/contribute' => Http::response([
                'success' => true,
                'data' => [
                    'session_id' => 42,
                    'status' => 'pendiente_revision',
                    'resumen' => 'Se propuso 1 hipótesis, pendiente de revisión.',
                ],
            ], 200),
        ]);

        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', 'El job falla porque el batch del banco no llega antes de las 6am')
            ->call('submit')
            ->assertSet('status', 'saved')
            ->assertSet('resumen', 'Se propuso 1 hipótesis, pendiente de revisión.')
            ->assertSee('¡Gracias por tu aporte!')
            ->assertSee('Hacer otro aporte');
    }

    public function test_contribution_sends_correct_payload(): void
    {
        $this->fakeQbkConfig();

        $user = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $user->uuid,
            'status' => 'active',
            'is_default' => true,
            'connector_type' => 'qbk',
            'credential' => ['api_token' => '2|test_token_123'],
        ]);

        Http::fake([
            config('services.qubeka.api_url').'/contribute' => Http::response([
                'success' => true,
                'data' => [
                    'session_id' => 42,
                    'status' => 'pendiente_revision',
                    'resumen' => 'OK',
                ],
            ], 200),
        ]);

        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', 'El job falla porque el batch del banco no llega antes de las 6am')
            ->set('preguntaPrevia', '¿Por qué falla el job?')
            ->call('submit')
            ->assertSet('status', 'saved');

        Http::assertSent(function ($request) {
            return $request->url() === config('services.qubeka.api_url').'/contribute'
                && $request->data()['texto'] === 'El job falla porque el batch del banco no llega antes de las 6am'
                && $request->data()['origen'] === 'kuestion'
                && $request->data()['pregunta_previa'] === '¿Por qué falla el job?';
        });
    }

    public function test_contribution_without_pregunta_previa(): void
    {
        $this->fakeQbkConfig();

        $user = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $user->uuid,
            'status' => 'active',
            'is_default' => true,
            'connector_type' => 'qbk',
            'credential' => ['api_token' => '2|test_token_123'],
        ]);

        Http::fake([
            config('services.qubeka.api_url').'/contribute' => Http::response([
                'success' => true,
                'data' => [
                    'session_id' => 42,
                    'status' => 'pendiente_revision',
                    'resumen' => 'OK',
                ],
            ], 200),
        ]);

        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', 'El batch del banco llega a las 7:30am')
            ->call('submit')
            ->assertSet('status', 'saved');

        Http::assertSent(function ($request) {
            return $request->url() === config('services.qubeka.api_url').'/contribute'
                && ! array_key_exists('pregunta_previa', $request->data());
        });
    }

    public function test_handles_401_error(): void
    {
        $this->fakeQbkConfig();

        $user = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $user->uuid,
            'status' => 'active',
            'is_default' => true,
            'connector_type' => 'qbk',
            'credential' => ['api_token' => '2|invalid_token'],
        ]);

        Http::fake([
            config('services.qubeka.api_url').'/contribute' => Http::response([
                'success' => false,
                'error' => 'Invalid token',
            ], 401),
        ]);

        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', 'El batch del banco llega a las 7:30am')
            ->call('submit')
            ->assertSet('status', 'error')
            ->assertSee('token de QuBeKa es inválido');
    }

    public function test_handles_500_error(): void
    {
        $this->fakeQbkConfig();

        $user = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $user->uuid,
            'status' => 'active',
            'is_default' => true,
            'connector_type' => 'qbk',
            'credential' => ['api_token' => '2|valid_token'],
        ]);

        Http::fake([
            config('services.qubeka.api_url').'/contribute' => Http::response('Server Error', 500),
        ]);

        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', 'El batch del banco llega a las 7:30am')
            ->call('submit')
            ->assertSet('status', 'error')
            ->assertSee('respondió con error');
    }

    public function test_handles_timeout(): void
    {
        $this->fakeQbkConfig();

        $user = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $user->uuid,
            'status' => 'active',
            'is_default' => true,
            'connector_type' => 'qbk',
            'credential' => ['api_token' => '2|valid_token'],
        ]);

        Http::fake([
            config('services.qubeka.api_url').'/contribute' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->set('texto', 'El batch del banco llega a las 7:30am')
            ->call('submit')
            ->assertSet('status', 'error')
            ->assertSee('tardó demasiado');
    }

    public function test_pregunta_previa_from_query_param(): void
    {
        $this->fakeQbkConfig();

        $user = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $user->uuid,
            'status' => 'active',
            'is_default' => true,
            'connector_type' => 'qbk',
        ]);

        $this->actingAs($user);

        // Test via HTTP to verify query param is picked up by mount().
        $response = $this->get(route('contribute', ['prev' => '¿Por qué falla el job?']));
        $response->assertStatus(200);
        $response->assertSee('¿Por qué falla el job?');
    }

    public function test_single_repository_shows_name_not_selector(): void
    {
        $this->fakeQbkConfig();

        $user = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $user->uuid,
            'status' => 'active',
            'is_default' => true,
            'connector_type' => 'qbk',
            'resolved_tenant_name' => 'Investigación',
        ]);

        $this->actingAs($user);

        Livewire::test(ContributeAporte::class)
            ->assertSee('Enviando a')
            ->assertSee('QuBeKa')
            ->assertDontSee('Fuente de conocimiento');
    }

    public function test_multiple_repositories_shows_selector(): void
    {
        $this->fakeQbkConfig();

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
            ->assertSee('Fuente de conocimiento');
    }

    public function test_route_contribute_exists(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = $this->get(route('contribute'));

        $response->assertStatus(200);
    }
}
