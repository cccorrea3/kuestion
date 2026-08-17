<?php

namespace App\Jobs;

use App\Contracts\IdentityResolverInterface;
use App\Contracts\RagProviderInterface;
use App\Contracts\StructuredSignalProviderInterface;
use App\Exceptions\KuaforiaException;
use App\Exceptions\KuaforiaMcpException;
use App\Models\Question;
use App\Models\Repository;
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

    public function handle(RagProviderInterface $kuaforia, ?StructuredSignalProviderInterface $signals = null): void
    {
        Question::where('status', 'active')->with('user', 'repository')->chunk(100, function ($questions) use ($kuaforia, $signals) {
            foreach ($questions as $question) {
                if (! $this->isDue($question)) {
                    continue;
                }

                // D4 — el tenant sale del repositorio de la pregunta (no de users.tenant_slug).
                $repo = $question->repository;
                $tenantSlug = $repo?->resolved_tenant_slug;

                if (! $repo || ! $tenantSlug) {
                    Log::warning('CheckQuestionUpdatesJob: question sin repositorio resuelto', [
                        'question_id' => $question->id,
                    ]);

                    continue;
                }

                try {
                    $response = $kuaforia->consult($question->question_text, tenantSlug: $tenantSlug);
                } catch (KuaforiaException $e) {
                    // D4 — 401 (key revocada/inválida) → repositorio invalid (P9/P10). Otros
                    // códigos (503/timeout) se registran y el job reintenta con el backoff actual.
                    if ($e->getCode() === 401) {
                        $repo->update([
                            'status' => 'invalid',
                            'last_validated_at' => now(),
                            'last_used_at' => now(),
                        ]);

                        Log::warning('CheckQuestionUpdatesJob: repositorio marcado invalid (401)', [
                            'question_id' => $question->id,
                            'repository_id' => $repo->id,
                        ]);
                    } else {
                        Log::warning('CheckQuestionUpdatesJob: Kuaforia error', [
                            'question_id' => $question->id,
                            'tenant' => $tenantSlug,
                            'error' => $e->getMessage(),
                            'status' => $e->getCode(),
                        ]);
                    }

                    continue;
                } catch (\Throwable $e) {
                    Log::warning('CheckQuestionUpdatesJob: Kuaforia error', [
                        'question_id' => $question->id,
                        'tenant' => $tenantSlug,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }

                // P9 — last_used_at se actualiza en el job (no en cada follow-up).
                $repo->update(['last_used_at' => now()]);

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

                // 8.4 — Enriquecimiento best-effort con señales MCP, ANTES de la
                // transacción (no se mantiene el lock de fila durante una llamada HTTP
                // de hasta 15 s). Un fallo de señales NUNCA interrumpe ni reintenta el
                // job: se registra y se continúa con el flujo actual.
                // E2 — las señales usan la credencial y el workspace del repositorio
                // (cierra Hallazgo 2: la key compartida deja de viajar en las señales).
                // G7 — sin fallback workspace_map: el workspace sale del repo, con
                // lazy backfill vía get_client_context si falta (repos pre-G7).
                $signalsPayload = null;
                if ($signals !== null) {
                    try {
                        $signalsPayload = $this->collectSignals($signals, $repo);
                    } catch (\Throwable $e) {
                        Log::warning('CheckQuestionUpdatesJob: enriquecimiento de señales MCP falló', [
                            'question_id' => $question->id,
                            'tenant' => $tenantSlug,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                DB::transaction(function () use ($question, $response, $result, $detector, $signalsPayload) {
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
                        signals: $signalsPayload,
                    ));
                });
            }
        });
    }

    /**
     * 8.4 — Señales estructuradas para el payload de la notificación.
     *
     * E2 — la credencial y el workspace salen del repositorio de la pregunta.
     * G7 — sin fallback workspace_map: si el repo no tiene `resolved_workspace_id`
     * (creado antes de la extensión default_workspace de Kuaforia), se hace un
     * lazy backfill: get_client_context con la credencial del repo, se persiste el
     * workspace y se usa. Fallos → skip silencioso (null): el llamador captura
     * \Throwable para degradar con gracia. 401 → repo invalid (patrón D4).
     */
    private function collectSignals(StructuredSignalProviderInterface $signals, Repository $repo): ?array
    {
        $workspaceId = $repo->resolved_workspace_id;

        if (! $workspaceId) {
            $workspaceId = $this->backfillWorkspace($repo);
        }

        if (! $workspaceId) {
            return null;
        }

        $credential = $repo->credential ?? null;

        return [
            'generated_at' => now()->toIso8601String(),
            'workspace_health' => $signals->getWorkspaceHealth($workspaceId, $credential),
            'dependency_health_report' => $signals->getDependencyHealthReport($workspaceId, $credential),
        ];
    }

    /**
     * G7 — lazy backfill del workspace para repos creados antes de la extensión
     * default_workspace: re-resuelve la identidad con la credencial del repo y
     * persiste `resolved_workspace_id`. Devuelve el workspace o null.
     */
    private function backfillWorkspace(Repository $repo): ?string
    {
        try {
            $identity = app(IdentityResolverInterface::class)->resolveIdentity($repo->credential ?? []);
        } catch (KuaforiaMcpException $e) {
            // D4 — 401 (key revocada/inválida) → repositorio invalid, como en la consulta.
            if ($e->getCode() === 401) {
                $repo->update([
                    'status' => 'invalid',
                    'last_validated_at' => now(),
                ]);
            }

            Log::warning('CheckQuestionUpdatesJob: backfill de workspace falló', [
                'repository_id' => $repo->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('CheckQuestionUpdatesJob: backfill de workspace falló', [
                'repository_id' => $repo->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $identity->workspaceId) {
            return null;
        }

        $repo->update(['resolved_workspace_id' => $identity->workspaceId]);

        return $identity->workspaceId;
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
