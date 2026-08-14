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
use Illuminate\Support\Str;

// ponytail: single job checks all due questions. No per-question scheduling.
// Upgrade to individual delayed jobs if question count exceeds ~1000.
class CheckQuestionUpdatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public array $backoff = [60, 300, 900];

    public function handle(KuaforiaService $kuaforia): void
    {
        Question::where('status', 'active')->with('user')->chunk(100, function ($questions) use ($kuaforia) {
            foreach ($questions as $question) {
                if (! $this->isDue($question)) {
                    continue;
                }

                $tenantSlug = $question->user?->tenant_slug;

                if (! $tenantSlug) {
                    Log::warning('CheckQuestionUpdatesJob: question sin tenant', [
                        'question_id' => $question->id,
                    ]);

                    continue;
                }

                try {
                    $response = $kuaforia->consult($question->question_text, tenantSlug: $tenantSlug);
                } catch (\Throwable $e) {
                    Log::warning('CheckQuestionUpdatesJob: Kuaforia error', [
                        'question_id' => $question->id,
                        'tenant' => $tenantSlug,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }

                // Respuesta vacía (1.8): no se versiona ni se detecta; se notifica el error.
                if (trim($response->answerText) === '') {
                    $this->handleEmptyResponse($question, $tenantSlug);

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
                    // Lock de fila: serializa la numeración de versiones entre workers.
                    $locked = Question::whereKey($question->id)->lockForUpdate()->first();

                    $nextVersion = ($locked->versions()->max('version_number') ?? 0) + 1;

                    $locked->versions()->where('is_current', true)->update(['is_current' => false]);

                    $locked->versions()->create([
                        'version_number' => $nextVersion,
                        'answer_text' => $response->answerText,
                        'confidence' => $response->confidence,
                        'sources' => $response->sources,
                        'response_hash' => $detector->hash($response->answerText),
                        'is_current' => true,
                        'status' => $result['type'] === 'minor' ? 'minor_change' : 'new_version',
                    ]);

                    $locked->update([
                        'answer_text' => $response->answerText,
                        'last_consulted_at' => now(),
                        'last_change_detected_at' => now(),
                        'has_unreviewed_changes' => true,
                    ]);

                    // Notificación dentro de la transacción — si falla, todo se revierte y retryea limpio
                    DB::table('notifications')->insert([
                        'id' => (string) Str::uuid(),
                        'user_id' => $locked->user_id,
                        'type' => 'answer_changed',
                        'data' => json_encode([
                            'question_id' => $locked->id,
                            'question_text' => str($locked->question_text)->limit(80)->value(),
                            'version_number' => $nextVersion,
                            'change_type' => $result['type'],
                            'similarity' => $result['similarity'],
                        ]),
                        'read_at' => null,
                        'created_at' => now(),
                    ]);
                });
            }
        });
    }

    /**
     * Respuesta vacía de Kuaforia (1.8): no se crea versión; se notifica el error una sola
     * vez por error no leído (anti-spam) y se actualiza last_consulted_at para no re-consultar
     * en cada corrida del job.
     */
    private function handleEmptyResponse(Question $question, string $tenantSlug): void
    {
        DB::transaction(function () use ($question) {
            $locked = Question::whereKey($question->id)->lockForUpdate()->first();

            $hasUnreadError = DB::table('notifications')
                ->where('user_id', $locked->user_id)
                ->whereNull('read_at')
                ->where('type', 'query_error')
                ->where('data->question_id', $locked->id)
                ->exists();

            if (! $hasUnreadError) {
                DB::table('notifications')->insert([
                    'id' => (string) Str::uuid(),
                    'user_id' => $locked->user_id,
                    'type' => 'query_error',
                    'data' => json_encode([
                        'question_id' => $locked->id,
                        'question_text' => str($locked->question_text)->limit(80)->value(),
                        'motivo' => 'Kuaforia devolvió una respuesta vacía.',
                    ]),
                    'read_at' => null,
                    'created_at' => now(),
                ]);
            }

            $locked->update(['last_consulted_at' => now()]);
        });

        Log::warning('CheckQuestionUpdatesJob: respuesta vacía de Kuaforia', [
            'question_id' => $question->id,
            'tenant' => $tenantSlug,
        ]);
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
