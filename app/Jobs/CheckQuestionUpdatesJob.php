<?php

namespace App\Jobs;

use App\Contracts\RagProviderInterface;
use App\Models\Question;
use App\Notifications\AnswerChangedNotification;
use App\Notifications\QueryErrorNotification;
use App\Services\ChangeDetector;
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

    public function handle(RagProviderInterface $kuaforia): void
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

                    // Notificación dentro de la transacción — si falla, todo se revierte y retryea limpio.
                    // Bloque 1: notificaciones nativas de Laravel (canal database + mail si el usuario
                    // tiene email_notifications activo). El payload conserva las mismas claves de antes.
                    $locked->user->notify(new AnswerChangedNotification(
                        questionId: $locked->id,
                        questionText: str($locked->question_text)->limit(80)->value(),
                        versionNumber: $nextVersion,
                        changeType: $result['type'],
                        similarity: $result['similarity'],
                    ));
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

            // Anti-spam: una sola notificación de error no leída por pregunta.
            $hasUnreadError = $locked->user->notifications()
                ->whereNull('read_at')
                ->where('type', QueryErrorNotification::class)
                ->where('data->question_id', $locked->id)
                ->exists();

            if (! $hasUnreadError) {
                $locked->user->notify(new QueryErrorNotification(
                    questionId: $locked->id,
                    questionText: str($locked->question_text)->limit(80)->value(),
                    reason: 'Kuaforia devolvió una respuesta vacía.',
                ));
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
