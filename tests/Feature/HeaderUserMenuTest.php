<?php

namespace Tests\Feature;

use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeaderUserMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_menu_shows_settings_and_logout(): void
    {
        $this->actingAs(User::factory()->create());

        $content = $this->get('/questions')->getContent();

        // El menú del header siempre ofrece Configuración y Cerrar sesión.
        $this->assertStringContainsString('Configuración', $content);
        $this->assertStringContainsString('Cerrar sesión', $content);
        $this->assertStringContainsString(route('settings'), $content);
    }

    public function test_user_menu_shows_team_panorama_when_readonly_access(): void
    {
        $user = User::factory()->create(['team_dashboard_access' => 'readonly']);
        Repository::factory()->create(['user_id' => $user->uuid]);

        $this->actingAs($user);

        $content = $this->get('/questions')->getContent();

        $this->assertStringContainsString('Panorama del equipo', $content);
        $this->assertStringContainsString(route('team.index'), $content);
    }

    public function test_user_menu_hides_team_panorama_without_readonly_access(): void
    {
        $this->actingAs(User::factory()->create(['team_dashboard_access' => 'none']));

        $content = $this->get('/questions')->getContent();

        $this->assertStringNotContainsString('Panorama del equipo', $content);
        $this->assertStringNotContainsString(route('team.index'), $content);
    }
}
