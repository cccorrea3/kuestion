<?php

namespace App\Console\Commands;

use App\Models\AnswerVersion;
use App\Models\DailyMetric;
use App\Models\Question;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

// ponytail: agregación diaria única (una fila por día, idempotente via updateOrCreate).
// "cambios_sin_revisar" es un snapshot del momento en que corre el comando, no del día objetivo.
class CollectDailyMetrics extends Command
{
    protected $signature = 'metrics:collect {--date= : Fecha a agregar (Y-m-d). Por defecto: ayer}';

    protected $description = 'Agrega las métricas diarias a la tabla daily_metrics';

    public function handle(): int
    {
        $target = $this->resolveTargetDate();

        if ($target === null) {
            return self::FAILURE;
        }

        $start = $target->startOfDay();
        $end = $target->endOfDay();

        $metrics = [
            'metric_date' => $target->toDateString(),
            // Activas: estado activo y sin soft-delete al momento de la corrida.
            'preguntas_activas' => Question::where('status', 'active')
                ->whereNull('deleted_at')
                ->count(),
            // Creadas: todas las preguntas creadas ese día (incluye las luego archivadas).
            'preguntas_creadas' => Question::whereBetween('created_at', [$start, $end])->count(),
            // Detectados: versiones nuevas (v2+) creadas ese día.
            'cambios_detectados' => AnswerVersion::where('version_number', '>', 1)
                ->whereBetween('created_at', [$start, $end])
                ->count(),
            // Revisados: notificaciones de cambio leídas ese día (aceptar/descartar marca read_at).
            'cambios_revisados' => $this->reviewedNotifications($start, $end)->count(),
            // Sin revisar: snapshot del estado actual (has_unreviewed_changes).
            'cambios_sin_revisar' => Question::where('has_unreviewed_changes', true)->count(),
        ];

        $metrics['tiempo_revision_promedio_horas'] = $this->averageReviewTimeHours($start, $end);

        DailyMetric::updateOrCreate(['metric_date' => $metrics['metric_date']], $metrics);

        $this->components->info("Métricas agregadas para {$metrics['metric_date']}.");

        return self::SUCCESS;
    }

    private function resolveTargetDate(): ?CarbonImmutable
    {
        $date = $this->option('date');

        if ($date === null) {
            return CarbonImmutable::yesterday();
        }

        try {
            return CarbonImmutable::parse($date);
        } catch (\Throwable) {
            $this->components->error("Fecha inválida: {$date}. Usá el formato Y-m-d (ej. 2026-08-13).");

            return null;
        }
    }

    /**
     * Notificaciones answer_changed leídas en el rango.
     *
     * Nota: cuando se implemente el Bloque 1 (notificaciones nativas de Laravel), el valor de
     * `type` pasa de 'answer_changed' a la clase App\Notifications\AnswerChangedNotification.
     * El filtro cubre ambos valores para que este comando no requiera cambios en esa fase.
     */
    private function reviewedNotifications(CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return DB::table('notifications')
            ->whereIn('type', ['answer_changed', 'App\Notifications\AnswerChangedNotification'])
            ->whereBetween('read_at', [$start, $end]);
    }

    /**
     * Proxy de tiempo de revisión: promedio de (read_at - created_at) de las notificaciones
     * leídas ese día. Se calcula en PHP (no TIMESTAMPDIFF) para ser agnóstico del motor de BD
     * (MySQL hoy, SQLite en tests); el volumen diario es bajo y no justifica agregación en BD.
     */
    private function averageReviewTimeHours(CarbonImmutable $start, CarbonImmutable $end): ?float
    {
        $pairs = $this->reviewedNotifications($start, $end)
            ->whereNotNull('created_at')
            ->get(['created_at', 'read_at']);

        if ($pairs->isEmpty()) {
            return null;
        }

        $totalSeconds = $pairs->sum(function ($row) {
            $read = Carbon::parse($row->read_at);
            $created = Carbon::parse($row->created_at);

            // Duración absoluta: el signo de diffInSeconds depende del orden de argumentos
            // según la versión de Carbon, y un promedio negativo no tiene sentido aquí.
            return abs($read->diffInSeconds($created));
        });

        return round($totalSeconds / $pairs->count() / 3600, 2);
    }
}
