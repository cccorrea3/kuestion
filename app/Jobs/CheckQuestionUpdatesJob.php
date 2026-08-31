<?php

namespace App\Jobs;

use App\Models\Question;
use App\Services\ConnectorRegistry;
use App\Services\QuestionChecker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

// ponytail: single job checks all due questions. No per-question scheduling.
// Upgrade to individual delayed jobs if question count exceeds ~1000.
//
// La lógica de detección por pregunta vive en App\Services\QuestionChecker (única
// fuente de verdad): la reutilizan el job horario y el botón "Comprobar ahora"
// del detalle. Este job solo itera las preguntas que vencen según su frecuencia.
//
// Ola 1 Punto 1 — Fase 2: el checker resuelve el servicio RAG por connector_type
// via ConnectorRegistry; este job ya no inyecta RagProviderInterface directamente.
class CheckQuestionUpdatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public array $backoff = [60, 300, 900];

    public function handle(ConnectorRegistry $registry): void
    {
        $checker = app(QuestionChecker::class, [
            'registry' => $registry,
        ]);

        Question::where('status', 'active')->with('user', 'repository')->chunk(100, function ($questions) use ($checker) {
            foreach ($questions as $question) {
                if (! $this->isDue($question)) {
                    continue;
                }

                $checker->check($question);
            }
        });
    }

    private function isDue(Question $question): bool
    {
        if (! $question->last_consulted_at) {
            return true;
        }

        $interval = match ($question->review_frequency) {
            'weekly' => now()->subWeek(),
            'monthly' => now()->subMonth(),
            'quarterly' => now()->subQuarter(),
            default => now()->subWeek(),
        };

        return $question->last_consulted_at <= $interval;
    }
}
