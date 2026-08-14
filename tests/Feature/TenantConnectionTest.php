<?php

namespace Tests\Feature;

use App\Livewire\Auth\Register;
use App\Livewire\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class TenantConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function fakeValidKey(): void
    {
        Http::fake([
            '*/api/validate-api-key' => Http::response([
                'tenant_slug' => 'ispend',
                'workspace_id' => 'ws_123',
            ]),
        ]);
    }

    private function fakeInvalidKey(): void
    {
        Http::fake([
            '*/api/validate-api-key' => Http::response(['error' => 'invalid key'], 401),
        ]);
    }

    public function test_register_resolves_tenant_and_persists_encrypted_key(): void
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
            ->assertSet('keyStatus', 'Conectado a Kuaforia.')
            ->call('register')
            ->assertRedirect(route('onboarding'));

        $user = User::where('email', 'ana@example.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('ispend', $user->tenant_slug);
        $this->assertSame('kfr_test123456', $user->kuaforia_api_key);

        // La key se guarda cifrada en BD.
        $raw = DB::table('users')->where('id', $user->id)->value('kuaforia_api_key');
        $this->assertNotSame('kfr_test123456', $raw);
        $this->assertStringContainsString('eyJpdiI6', $raw); // payload cifrado (encrypted)
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

    public function test_settings_revalidates_and_updates_api_key(): void
    {
        $this->fakeValidKey();

        $user = User::factory()->create(['kuaforia_api_key' => 'kfr_anterior']);

        $this->actingAs($user);

        Livewire::test(Settings::class)
            ->set('kuaforiaApiKey', 'kfr_nueva123456')
            ->call('updateKuaforiaApiKey')
            ->assertSet('kuaforiaStatus', 'API key actualizada. Organización: ispend.');

        $user->refresh();

        $this->assertSame('kfr_nueva123456', $user->kuaforia_api_key);
        $this->assertSame('ispend', $user->tenant_slug);
    }

    public function test_settings_rejects_invalid_key_without_changes(): void
    {
        $this->fakeInvalidKey();

        $user = User::factory()->create(['kuaforia_api_key' => 'kfr_anterior', 'tenant_slug' => 'ispend']);

        $this->actingAs($user);

        Livewire::test(Settings::class)
            ->set('kuaforiaApiKey', 'kfr_key_invalida')
            ->call('updateKuaforiaApiKey')
            ->assertSet('kuaforiaError', 'La API key de Kuaforia es inválida o fue revocada.');

        $user->refresh();

        $this->assertSame('kfr_anterior', $user->kuaforia_api_key);
        $this->assertSame('ispend', $user->tenant_slug);
    }
}
