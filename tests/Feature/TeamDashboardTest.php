<?php

namespace Tests\Feature;

use App\Livewire\TeamDashboard;
use App\Models\DailyMetric;
use App\Models\Question;
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
    }

    public function test_dashboard_requires_readonly_access(): void
    {
        $user = User::factory()->create(['team_dashboard_access' => 'none']);
        $this->actingAs($user);

        Livewire::test(TeamDashboard::class)->assertForbidden();
    }

    public function test_dashboard_aggregates_across_users_of_same_tenant(): void
    {
        // 3 preguntas del usuario logueado (1 con cambios) + 2 de otro usuario del mismo tenant (1 con cambios).
        Question::factory()->create(['user_id' => $this->user->uuid, 'status' => 'active', 'has_unreviewed_changes' => true]);
        Question::factory()->create(['user_id' => $this->user->uuid, 'status' => 'active', 'has_unreviewed_changes' => false]);
        Question::factory()->create(['user_id' => $this->user->uuid, 'status' => 'active', 'has_unreviewed_changes' => false]);

        $teammate = User::factory()->create(['tenant_slug' => 'ispend']);
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

        $otherTenant = User::factory()->create(['tenant_slug' => 'otro']);
        Question::factory()->create(['user_id' => $otherTenant->uuid, 'status' => 'active']);

        Livewire::test(TeamDashboard::class)
            ->assertSee('1') // solo la del propio tenant
            ->assertSee('Miembros del tenant');
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
