<?php

namespace App\Console\Commands;

use App\Models\DailyMetric;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ShowMetrics extends Command
{
    protected $signature = 'metrics:show
        {--date= : Fecha exacta (Y-m-d)}
        {--range= : Cantidad de días a mostrar (por defecto 7)}';

    protected $description = 'Muestra las métricas diarias agregadas';

    public function handle(): int
    {
        if ($date = $this->option('date')) {
            return $this->showDate($date);
        }

        $range = max(1, (int) ($this->option('range') ?? 7));

        $rows = DailyMetric::query()
            ->orderByDesc('metric_date')
            ->limit($range)
            ->get();

        if ($rows->isEmpty()) {
            $this->components->warn('Todavía no hay métricas. Ejecutá "php artisan metrics:collect" primero.');

            return self::SUCCESS;
        }

        $this->components->info('Métricas diarias (últimos '.$rows->count().' días con datos):');
        $this->renderTable($rows);
        $this->renderSummary($rows->first());

        return self::SUCCESS;
    }

    private function showDate(string $date): int
    {
        $row = DailyMetric::where('metric_date', $date)->first();

        if ($row === null) {
            $this->components->error("No hay métricas para {$date}. Verificá la fecha (Y-m-d) o ejecutá metrics:collect.");

            return self::FAILURE;
        }

        $this->renderTable(collect([$row]));
        $this->renderSummary($row);

        return self::SUCCESS;
    }

    private function renderTable(Collection $rows): void
    {
        $this->table(
            ['Fecha', 'Activas', 'Creadas', 'Detectados', 'Revisados', 'Sin revisar', 'Tiempo revisión (h)'],
            $rows->map(fn (DailyMetric $metric) => [
                $metric->metric_date->toDateString(),
                $metric->preguntas_activas,
                $metric->preguntas_creadas,
                $metric->cambios_detectados,
                $metric->cambios_revisados,
                $metric->cambios_sin_revisar,
                $metric->tiempo_revision_promedio_horas !== null
                    ? number_format((float) $metric->tiempo_revision_promedio_horas, 2)
                    : '—',
            ]),
        );
    }

    private function renderSummary(DailyMetric $latest): void
    {
        $total = $latest->cambios_revisados + $latest->cambios_sin_revisar;
        $pctReviewed = $total > 0 ? round($latest->cambios_revisados / $total * 100) : null;

        $parts = [$latest->preguntas_activas.' preguntas activas'];

        $parts[] = $pctReviewed !== null
            ? "{$pctReviewed}% cambios revisados"
            : 'sin cambios para revisar';

        if ($latest->tiempo_revision_promedio_horas !== null) {
            $parts[] = number_format((float) $latest->tiempo_revision_promedio_horas, 2).' h promedio de revisión';
        }

        $this->components->info('Resumen ('.$latest->metric_date->toDateString().'): '.implode(' · ', $parts));
    }
}
