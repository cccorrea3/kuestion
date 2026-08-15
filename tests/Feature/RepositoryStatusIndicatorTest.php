<?php

namespace Tests\Feature;

use App\Livewire\RepositoryStatusIndicator;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RepositoryStatusIndicatorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_header_indicator_shows_warning_with_highlight_link_when_repository_invalid(): void
    {
        // F2 (UX §6.4): badge de advertencia junto al menú de usuario; clic → /settings?highlight=.
        $repo = Repository::factory()->create([
            'user_id' => $this->user->uuid,
            'status' => 'invalid',
        ]);

        Livewire::test(RepositoryStatusIndicator::class)
            ->assertSee('Conexión inactiva')
            ->assertSeeHtml('href="'.route('settings', ['highlight' => $repo->id]).'"')
            ->assertSet('invalidRepositoryId', $repo->id);
    }

    public function test_header_indicator_hidden_when_no_invalid_repository(): void
    {
        Repository::factory()->create([
            'user_id' => $this->user->uuid,
            'status' => 'active',
        ]);

        Livewire::test(RepositoryStatusIndicator::class)
            ->assertDontSee('Conexión inactiva')
            ->assertSet('invalidRepositoryId', null);
    }

    public function test_header_indicator_hidden_when_repository_revoked(): void
    {
        // revoked no es invalid: no es una key que se pueda reparar desde /settings,
        // el usuario la desconectó a propósito — no corresponde el indicador de advertencia.
        Repository::factory()->create([
            'user_id' => $this->user->uuid,
            'status' => 'revoked',
        ]);

        Livewire::test(RepositoryStatusIndicator::class)
            ->assertDontSee('Conexión inactiva');
    }
}
