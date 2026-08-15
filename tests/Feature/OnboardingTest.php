<?php

namespace Tests\Feature;

use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_shows_tenant_name_from_first_repository(): void
    {
        $user = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $user->uuid,
            'resolved_tenant_name' => 'Ispend',
            'resolved_tenant_slug' => 'ispend',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->get(route('onboarding'))
            ->assertOk()
            ->assertSee('Ispend')
            ->assertDontSee('Conectá tu fuente de conocimiento');
    }

    public function test_onboarding_shows_generic_message_without_repositories(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('onboarding'))
            ->assertOk()
            ->assertSee('Conectá tu fuente de conocimiento')
            ->assertSee('Conectar Kuaforia');
    }
}
