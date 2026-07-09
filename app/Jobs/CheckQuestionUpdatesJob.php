<?php

namespace App\Jobs;

use App\Models\Question;
use App\Services\ChangeDetector;
use App\Services\KuaforiaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// ponytail: single job checks all due questions. No per-question scheduling.
// Upgrade to individual delayed jobs if question count exceeds ~1000.
class CheckQuestionUpdatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public array $backoff = [60, 300, 900];

    public function handle(KuaforiaService $kuaforia): void
    {
        $questions = Question::where('user_id', config('app.user_id'))
            ->where('status', 'active')
            ->get();

        foreach ($questions as $question) {
            if (!$this->isDue($question)) {
                continue;
            }

            try {
                $response = $kuaforia->consult($question->question_text);
            } catch (\Throwable $e) {
                Log::warning('CheckQuestionUpdatesJob: Kuaforia error', [
                    'question_id' => $question->id,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $detector = new ChangeDetector;
            $oldText = $question->currentVersion?->answer_text ?? '';
            $result = $detector->detect($oldText, $response->answerText);

            if ($result['type'] === 'unchanged') {
                $question->update(['last_consulted_at' => now()]);
                continue;
            }

            DB::transaction(function () use ($question, $response, $result, $detector) {
                // ponytail: max() + 1 asume un solo worker. Upgrade a lockForUpdate si escalas a multi-worker.
                $nextVersion = ($question->versions()->max('version_number') ?? 0) + 1;

                $question->versions()->where('is_current', true)->update(['is_current' => false]);

                $question->versions()->create([
                    'version_number' => $nextVersion,
                    'answer_text' => $response->answerText,
                    'confidence' => $response->confidence,
                    'sources' => $response->sources,
                    'response_hash' => $detector->hash($response->answerText),
                    'is_current' => true,
                    'status' => $result['type'] === 'minor' ? 'minor_change' : 'new_version',
                ]);

                $question->update([
                    'answer_text' => $response->answerText,
                    'last_consulted_at' => now(),
                    'last_change_detected_at' => now(),
                    'has_unreviewed_changes' => true,
                ]);

                // Notificación dentro de la transacción — si falla, todo se revierte y retryea limpio
                DB::table('notifications')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'user_id' => config('app.user_id'),
                    'type' => 'answer_changed',
                    'data' => json_encode([
                        'question_id' => $question->id,
                        'question_text' => str($question->question_text)->limit(80)->value(),
                        'version_number' => $nextVersion,
                        'change_type' => $result['type'],
                        'similarity' => $result['similarity'],
                    ]),
                    'read_at' => null,
                    'created_at' => now(),
                ]);
            });
        }
    }

    private function isDue(Question $question): bool
    {
        if (!$question->last_consulted_at) {
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
