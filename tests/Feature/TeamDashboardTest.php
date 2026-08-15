<?php

namespace Tests\Feature;

use App\Livewire\TeamDashboard;
use App\Models\DailyMetric;
use App\Models\Question;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TeamDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['team_dashboard_access' => 'readonly']);
        $this->actingAs($this->user);

        // Repo default del usuario (E3/P13): define el tenant que vigila el dashboard.
        Repository::factory()->create([
            'user_id' => $this->user->uuid,
            'is_default' => true,
            'resolved_tenant_slug' => 'ispend',
        ]);
    }

    public function test_dashboard_requires_readonly_access(): void
    {
        $user = User::factory()->create(['team_dashboard_access' => 'none']);
        $this->actingAs($user);

        Livewire::test(TeamDashboard::class)->assertForbidden();
    }

    public function test_dashboard_aggregates_across_users_of_same_tenant(): void
    {
        // 3 preguntas del usuario logueado (1 con cambios) + 2 de otro usuario cuyo repo
        // resuelve al mismo tenant (1 con cambios) — P13: se comparte el tenant resuelto.
        Question::factory()->create(['user_id' => $this->user->uuid, 'status' => 'active', 'has_unreviewed_changes' => true]);
        Question::factory()->create(['user_id' => $this->user->uuid, 'status' => 'active', 'has_unreviewed_changes' => false]);
        Question::factory()->create(['user_id' => $this->user->uuid, 'status' => 'active', 'has_unreviewed_changes' => false]);

        $teammate = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $teammate->uuid,
            'is_default' => true,
            'resolved_tenant_slug' => 'ispend',
        ]);
        Question::factory()->create(['user_id' => $teammate->uuid, 'status' => 'active', 'has_unreviewed_changes' => true]);
        Question::factory()->create(['user_id' => $teammate->uuid, 'status' => 'active', 'has_unreviewed_changes' => false]);

        Livewire::test(TeamDashboard::class)
            ->assertSee('5') // total activas del tenant
            ->assertSee('40%') // 2 de 5 con cambios
            ->assertSee('2 preguntas');
    }

    public function test_dashboard_excludes_other_tenants(): void
    {
        Question::factory()->create(['user_id' => $this->user->uuid, 'status' => 'active']);

        $otherTenant = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $otherTenant->uuid,
            'is_default' => true,
            'resolved_tenant_slug' => 'otro',
        ]);
        Question::factory()->create(['user_id' => $otherTenant->uuid, 'status' => 'active']);

        Livewire::test(TeamDashboard::class)
            ->assertSee('1') // solo la del propio tenant
            ->assertSee('Miembros del tenant');
    }

    public function test_dashboard_does_not_mix_tenants_when_user_has_multiple_repositories(): void
    {
        // P13 corregida: usuario con repos de tenants DISTINTOS → el dashboard muestra
        // solo el del repo `is_default`, nunca mezcla métricas de organizaciones.
        Question::factory()->create(['user_id' => $this->user->uuid, 'status' => 'active']);

        Repository::factory()->create([
            'user_id' => $this->user->uuid,
            'is_default' => false,
            'resolved_tenant_slug' => 'qubeka',
        ]);

        // Otro usuario del tenant NO default — sus preguntas NO deben contarse.
        $qubekaUser = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $qubekaUser->uuid,
            'is_default' => true,
            'resolved_tenant_slug' => 'qubeka',
        ]);
        Question::factory()->create(['user_id' => $qubekaUser->uuid, 'status' => 'active', 'has_unreviewed_changes' => true]);

        Livewire::test(TeamDashboard::class)
            ->assertSee('1') // solo la del tenant ispend (default), sin mezclar qubeka
            ->assertSee('0%');
    }

    public function test_dashboard_degrades_with_connect_message_without_default_repository(): void
    {
        // Sin repo default (0 repos activos): degradación con el mensaje de conexión (§6.5).
        Repository::where('user_id', $this->user->uuid)->delete();

        Livewire::test(TeamDashboard::class)
            ->assertSee('No hay una fuente de conocimiento conectada')
            ->assertSee('Ir a configuraciones')
            ->assertSee('0'); // total degradado a 0, sin fallar
    }

    public function test_dashboard_counts_only_active_questions(): void
    {
        Question::factory()->create(['user_id' => $this->user->uuid, 'status' => 'active', 'has_unreviewed_changes' => true]);
        Question::factory()->create(['user_id' => $this->user->uuid, 'status' => 'archived', 'has_unreviewed_changes' => true]);

        // Solo la activa cuenta (resolución §6.4): total 1 y 100% con cambios.
        Livewire::test(TeamDashboard::class)
            ->assertSee('100%');
    }

    public function test_dashboard_shows_top_tags(): void
    {
        Question::factory()->create(['user_id' => $this->user->uuid, 'status' => 'active', 'tags' => ['laravel']]);
        Question::factory()->create(['user_id' => $this->user->uuid, 'status' => 'active', 'tags' => ['laravel']]);
        Question::factory()->create(['user_id' => $this->user->uuid, 'status' => 'active', 'tags' => ['rag']]);

        Livewire::test(TeamDashboard::class)
            ->assertSee('Tags más vigilados')
            ->assertSee('laravel')
            ->assertSee('2 preguntas');
    }

    public function test_dashboard_hides_trends_when_no_metrics(): void
    {
        // Degradación con gracia: sin daily_metrics la sección se oculta, no falla.
        Livewire::test(TeamDashboard::class)
            ->assertDontSee('Tendencias');
    }

    public function test_dashboard_shows_weekly_trends_from_daily_metrics(): void
    {
        DailyMetric::create([
            'metric_date' => now()->subWeek()->toDateString(),
            'preguntas_creadas' => 5,
            'cambios_detectados' => 2,
        ]);

        Livewire::test(TeamDashboard::class)
            ->assertSee('Tendencias')
            ->assertSee('5 creadas')
            ->assertSee('2 cambios');
    }
}
