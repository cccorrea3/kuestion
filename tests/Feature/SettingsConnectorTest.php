<?php

namespace Tests\Feature;

use App\LiveWire\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsConnectorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['password' => 'password']);
        $this->actingAs($this->user);
    }

    public function test_connectors_property_returns_both_kuaforia_and_qbk(): void
    {
        $component = Livewire::test(Settings::class);

        $connectors = $component->get('connectors');

        $this->assertArrayHasKey('kuaforia', $connectors);
        $this->assertArrayHasKey('qbk', $connectors);
        $this->assertSame('Kuaforia', $connectors['kuaforia']['display_name']);
        $this->assertSame('QuBeKa', $connectors['qbk']['display_name']);
    }

    public function test_connector_type_defaults_to_kuaforia(): void
    {
        Livewire::test(Settings::class)
            ->assertSet('connectorType', 'kuaforia');
    }

    public function test_connector_type_preselects_from_existing_repo(): void
    {
        $this->user->repositories()->create([
            'connector_type' => 'qbk',
            'name' => 'Mi Repo QBK',
            'credential' => ['api_token' => '1|test'],
            'status' => 'active',
            'is_default' => true,
        ]);

        Livewire::test(Settings::class)
            ->assertSet('connectorType', 'qbk');
    }

    public function test_set_connector_type_resets_form(): void
    {
        Livewire::test(Settings::class)
            ->set('credentials', ['api_key' => 'old_value'])
            ->call('setConnectorType', 'qbk')
            ->assertSet('connectorType', 'qbk')
            ->assertSet('credentials', [])
            ->assertSet('repoError', null)
            ->assertSet('repoStatus', null)
            ->assertSet('editingId', null);
    }

    public function test_set_connector_type_rejects_invalid_type(): void
    {
        Livewire::test(Settings::class)
            ->call('setConnectorType', 'nonexistent')
            ->assertSet('connectorType', 'kuaforia');
    }

    public function test_save_repository_validates_auth_fields(): void
    {
        Livewire::test(Settings::class)
            ->call('saveRepository')
            ->assertSet('repoError', 'Ingresá el campo: API key.');
    }

    public function test_current_connector_returns_correct_ficha(): void
    {
        Livewire::test(Settings::class)
            ->set('connectorType', 'qbk')
            ->assertSet('currentConnector.display_name', 'QuBeKa');
    }
}
