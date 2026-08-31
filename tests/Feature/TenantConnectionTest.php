<?php

namespace Tests\Feature;

use App\LiveWire\Auth\Register;
use App\LiveWire\Settings;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class TenantConnectionTest extends TestCase
{
    use RefreshDatabase;

    // Fase B: la identidad es 100% vía MCP (get_client_context, contrato P3):
    // content[0].text como STRING JSON con data.tenant.slug/name. G7 — el contrato
    // extendido trae data.default_workspace.id (el workspace por defecto del tenant).
    private function fakeValidKey(): void
    {
        Http::fake([
            '*/api/v1/mcp' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'content' => [[
                        'type' => 'text',
                        'text' => json_encode([
                            'success' => true,
                            'data' => [
                                'tenant' => ['slug' => 'ispend', 'name' => 'Ispend'],
                                'scopes' => ['questions:read'],
                                'default_workspace' => [
                                    'id' => 'ws-ispend',
                                    'name' => 'Workspace Ispend',
                                    'slug' => 'ispend',
                                ],
                            ],
                        ]),
                    ]],
                    'isError' => false,
                ],
            ]),
        ]);
    }

    // P3: 401 con JSON plano (rompe el sobre JSON-RPC).
    private function fakeInvalidKey(): void
    {
        Http::fake([
            '*/api/v1/mcp' => Http::response(['success' => false, 'error' => 'Invalid or expired API key'], 401),
        ]);
    }

    public function test_register_creates_user_with_default_repository(): void
    {
        $this->fakeValidKey();
        $this->withSession([]);

        // set() sobre kuaforiaApiKey dispara el hook updatedKuaforiaApiKey (validación en vivo).
        Livewire::test(Register::class)
            ->set('name', 'Ana')
            ->set('email', 'ana@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('kuaforiaApiKey', 'kfr_test123456')
            ->assertSet('resolvedTenantSlug', 'ispend')
            ->assertSet('resolvedTenantName', 'Ispend')
            ->assertSet('keyStatus', 'Conectado a Ispend (ispend).')
            ->call('register')
            ->assertRedirect(route('onboarding'));

        $user = User::where('email', 'ana@example.com')->first();

        $this->assertNotNull($user);
        // G1: users ya no tiene tenant_slug ni kuaforia_api_key — la conexión vive
        // enteramente en `repositories` (verificado abajo contra el repo, no users).
        $this->assertFalse(array_key_exists('tenant_slug', $user->getAttributes()));
        $this->assertFalse(array_key_exists('kuaforia_api_key', $user->getAttributes()));

        $repo = $user->repositories()->first();

        $this->assertNotNull($repo);
        $this->assertSame('kuaforia', $repo->connector_type);
        $this->assertSame('Kuaforia - Ispend', $repo->name);
        $this->assertSame('active', $repo->status);
        $this->assertTrue($repo->is_default);
        $this->assertSame('ispend', $repo->resolved_tenant_slug);
        $this->assertSame('Ispend', $repo->resolved_tenant_name);
        // G7 — el workspace por defecto se persiste al crear el primer repositorio.
        $this->assertSame('ws-ispend', $repo->resolved_workspace_id);
        $this->assertSame('kfr_test123456', $repo->credential['api_key']);

        // La credencial se guarda cifrada en reposo.
        $raw = DB::table('repositories')->where('id', $repo->id)->value('credential');
        $this->assertNotSame('kfr_test123456', $raw);
    }

    public function test_register_rejects_invalid_key(): void
    {
        $this->fakeInvalidKey();

        Livewire::test(Register::class)
            ->set('email', 'ana@example.com')
            ->set('kuaforiaApiKey', 'kfr_key_invalida')
            ->assertSet('resolvedTenantSlug', null)
            ->assertSet('keyError', 'La API key de Kuaforia es inválida o fue revocada.');

        // Sin tenant resuelto el registro queda bloqueado.
        $this->assertDatabaseMissing('users', ['email' => 'ana@example.com']);
    }

    public function test_settings_updates_repository_credential(): void
    {
        $this->fakeValidKey();

        $user = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $user->uuid,
            'credential' => ['api_key' => 'kfr_anterior'],
            'resolved_tenant_slug' => 'ispend',
            'connector_type' => 'kuaforia',
        ]);

        $this->actingAs($user);

        Livewire::test(Settings::class)
            ->set('credentials', ['api_key' => 'kfr_nueva123456'])
            ->call('saveRepository')
            ->assertSet('repoStatus', 'Credencial actualizada. Organización: Ispend (ispend).');

        $repo = $user->repositories()->first();

        $this->assertSame('kfr_nueva123456', $repo->credential['api_key']);
        $this->assertSame('ispend', $repo->resolved_tenant_slug);
        $this->assertSame('Ispend', $repo->resolved_tenant_name);
        // G7 — revalidar también refresca (o completa) el workspace por defecto.
        $this->assertSame('ws-ispend', $repo->resolved_workspace_id);
    }

    public function test_settings_rejects_invalid_key_without_changes(): void
    {
        $this->fakeInvalidKey();

        $user = User::factory()->create();
        $repo = Repository::factory()->create([
            'user_id' => $user->uuid,
            'credential' => ['api_key' => 'kfr_anterior'],
            'resolved_tenant_slug' => 'ispend',
            'connector_type' => 'kuaforia',
        ]);

        $this->actingAs($user);

        Livewire::test(Settings::class)
            ->set('credentials', ['api_key' => 'kfr_key_invalida'])
            ->call('saveRepository')
            ->assertSet('repoError', 'La API key de Kuaforia es inválida o fue revocada.');

        $repo->refresh();

        $this->assertSame('kfr_anterior', $repo->credential['api_key']);
    }

    public function test_settings_disconnects_the_only_active_repository(): void
    {
        $user = User::factory()->create();
        $repo = Repository::factory()->create(['user_id' => $user->uuid, 'status' => 'active']);

        $this->actingAs($user);

        Livewire::test(Settings::class)
            ->call('startDisconnect', $repo->id)
            ->call('disconnectRepository')
            ->assertSet('repoStatus', 'Conexión desconectada.');

        $this->assertSame('revoked', $repo->fresh()->status);
    }

    public function test_settings_creates_first_repository_when_user_has_none(): void
    {
        $this->fakeValidKey();

        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(Settings::class)
            ->set('credentials', ['api_key' => 'kfr_nueva123456'])
            ->call('saveRepository')
            ->assertSet('repoStatus', 'Conectado a Ispend (ispend).');

        $repo = $user->repositories()->first();

        $this->assertNotNull($repo);
        $this->assertTrue($repo->is_default);
        $this->assertSame('Kuaforia - Ispend', $repo->name);
        $this->assertSame('active', $repo->status);
        // G7 — primer repositorio desde /settings también persiste el workspace.
        $this->assertSame('ws-ispend', $repo->resolved_workspace_id);
    }
}
