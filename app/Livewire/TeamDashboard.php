<?php

namespace App\Livewire;

use App\Models\DailyMetric;
use App\Models\Question;
use App\Models\Repository;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * 12.2/12.3 — Panorama de equipo: salud del tenant en vivo.
 *
 * ÚNICA vista de la app que cruza usuarios: agrega por `repositories.resolved_tenant_slug`
 * (E3 — Sistema de Conectores, decisión P13 corregida): el dashboard se restringe al
 * tenant del repositorio `is_default` del usuario actual — NO mezcla métricas de
 * tenants distintos aunque el usuario tenga repos de varias organizaciones. Protegida
 * por `team_dashboard_access === 'readonly'`. Solo lectura: no expone métodos de escritura.
 *
 * 12.5 — Nota (maestro): `team_dashboard_access` es una solución TEMPORAL, a
 * reemplazar por un sistema de roles. Privacidad: se asume que el tenant es un
 * equipo de confianza sin subgrupos; la granularidad futura se aborda con roles.
 */
#[Layout('layouts::app')]
class TeamDashboard extends Component
{
    public function mount(): void
    {
        // Gate de acceso (12.2): solo usuarios con acceso readonly.
        abort_unless(auth()->user()->team_dashboard_access === 'readonly', 403);
    }

    /**
     * Repositorio `is_default` del usuario (E3/P13): define el tenant que vigila el
     * dashboard. Null → 0 repos activos: el dashboard degrada con el mensaje de conexión.
     */
    public function getDefaultRepositoryProperty(): ?Repository
    {
        return auth()->user()->repositories()->where('is_default', true)->first();
    }

    /**
     * Agregados en vivo por tenant (12.3): solo preguntas activas (resolución de
     * revisión §6.4 — un panel de "salud del equipo" mira el estado vigente).
     *
     * E3/P13 — los usuarios del tenant salen de los repositorios con el mismo
     * `resolved_tenant_slug` que el repo `is_default` del usuario actual. Sin repo
     * default → todos los agregados en 0 (la vista muestra el mensaje de conexión).
     *
     * @return array{total: int, unreviewed: int, unreviewed_percent: int, top_tags: array, team_size: int}
     */
    public function getSummaryProperty(): array
    {
        $defaultRepo = $this->defaultRepository;

        if (! $defaultRepo?->resolved_tenant_slug) {
            return [
                'total' => 0,
                'unreviewed' => 0,
                'unreviewed_percent' => 0,
                'top_tags' => [],
                'team_size' => 0,
            ];
        }

        // Repositorios de TODOS los usuarios que resuelven al mismo tenant que el default.
        $userIds = Repository::where('resolved_tenant_slug', $defaultRepo->resolved_tenant_slug)->pluck('user_id');

        $active = Question::whereIn('user_id', $userIds)->where('status', 'active');
        $total = (clone $active)->count();
        $unreviewed = (clone $active)->where('has_unreviewed_changes', true)->count();

        $tagCounts = [];
        foreach ((clone $active)->get(['tags']) as $question) {
            if ($question->tags && is_array($question->tags)) {
                foreach ($question->tags as $tag) {
                    $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
                }
            }
        }

        arsort($tagCounts);

        return [
            'total' => $total,
            'unreviewed' => $unreviewed,
            'unreviewed_percent' => $total > 0 ? round($unreviewed / $total * 100) : 0,
            'top_tags' => array_slice($tagCounts, 0, 5, true),
            'team_size' => $userIds->count(),
        ];
    }

    /**
     * 12.4 — Tendencias semanales desde `daily_metrics` (últimas 8 semanas).
     * Degradación con gracia: si no hay filas la sección se oculta en la vista.
     * Las métricas son globales de la app; con un solo tenant (piloto) equivalen
     * a las del tenant (coherente con la nota de privacidad).
     */
    public function getWeeklyTrendsProperty(): Collection
    {
        $metrics = DailyMetric::query()
            ->where('metric_date', '>=', now()->subWeeks(8)->toDateString())
            ->orderBy('metric_date')
            ->get();

        return $metrics
            ->groupBy(fn (DailyMetric $m) => $m->metric_date->isoWeekYear().'-W'.str_pad((string) $m->metric_date->isoWeek(), 2, '0', STR_PAD_LEFT))
            ->map(fn ($group) => [
                'week' => $group->first()->metric_date->isoFormat('DD MMM'),
                'creadas' => $group->sum('preguntas_creadas'),
                'cambios' => $group->sum('cambios_detectados'),
            ])
            ->values();
    }

    public function render()
    {
        return view('livewire.team-dashboard', [
            'summary' => $this->summary,
            'weeklyTrends' => $this->weeklyTrends,
            'defaultRepository' => $this->defaultRepository,
        ]);
    }
}
