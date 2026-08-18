<?php

namespace App\Services;

use App\Contracts\IdentityResolverInterface;
use App\Contracts\RagProviderInterface;
use App\Contracts\StructuredSignalProviderInterface;
use App\Exceptions\KuaforiaException;
use App\Exceptions\KuaforiaMcpException;
use App\Models\Question;
use App\Models\Repository;
use App\Notifications\AnswerChangedNotification;
use App\Notifications\QueryErrorNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Detección de cambios por pregunta: consulta a Kuaforia, detecta el cambio,
 * versiona y notifica. Es la única fuente de verdad del flujo de re-consulta —
 * lo usan tanto el job horario (CheckQuestionUpdatesJob) como la acción manual
 * "Comprobar ahora" del detalle de la pregunta.
 *
 * check() devuelve un array con el resultado para que la UI muestre feedback:
 *   ['status' => 'changed'|'unchanged'|'empty'|'skipped'|'error', 'message' => string, ...]
 * El job ignora el retorno; el componente Livewire lo usa para el mensaje.
 */
class QuestionChecker
{
    public function __construct(
        private RagProviderInterface $kuaforia,
        private ?StructuredSignalProviderInterface $signals = null,
    ) {}

    public function check(Question $question): array
    {
        // D4 — el tenant sale del repositorio de la pregunta (no de users.tenant_slug).
        $repo = $question->repository;
        $tenantSlug = $repo?->resolved_tenant_slug;

        if (! $repo || ! $tenantSlug) {
            Log::warning('QuestionChecker: pregunta sin repositorio resuelto', [
                'question_id' => $question->id,
            ]);

            return [
                'status' => 'skipped',
                'message' => 'La conexión con tu fuente de conocimiento está inactiva. Actualizala en Configuración.',
            ];
        }

        try {
            $response = $this->kuaforia->consult($question->question_text, tenantSlug: $tenantSlug);
        } catch (KuaforiaException $e) {
            // D4 — 401 (key revocada/inválida) → repositorio invalid (P9/P10). Otros
            // códigos (503/timeout) se registran y el job reintenta con el backoff actual.
            if ($e->getCode() === 401) {
                $repo->update([
                    'status' => 'invalid',
                    'last_validated_at' => now(),
                    'last_used_at' => now(),
                ]);

                Log::warning('QuestionChecker: repositorio marcado invalid (401)', [
                    'question_id' => $question->id,
                    'repository_id' => $repo->id,
                ]);

                return [
                    'status' => 'error',
                    'message' => 'La API key fue revocada o es inválida. Reconectá tu repositorio en Configuración.',
                ];
            }

            Log::warning('QuestionChecker: Kuaforia error', [
                'question_id' => $question->id,
                'tenant' => $tenantSlug,
                'error' => $e->getMessage(),
                'status' => $e->getCode(),
            ]);

            return [
                'status' => 'error',
                'message' => 'No se pudo consultar a Kuaforia: '.$e->getMessage(),
            ];
        } catch (\Throwable $e) {
            Log::warning('QuestionChecker: Kuaforia error', [
                'question_id' => $question->id,
                'tenant' => $tenantSlug,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Error de conexión con Kuaforia. Intenta de nuevo.',
            ];
        }

        // P9 — last_used_at se actualiza en la comprobación (no en cada follow-up).
        $repo->update(['last_used_at' => now()]);

        // Respuesta vacía (1.8): no se versiona ni se detecta; se notifica el error.
        if (trim($response->answerText) === '') {
            $this->handleEmptyResponse($question, $tenantSlug);

            return [
                'status' => 'empty',
                'message' => 'Kuaforia devolvió una respuesta vacía. Se notificó el error.',
            ];
        }

        $detector = new ChangeDetector;
        $oldText = $question->currentVersion?->answer_text ?? '';
        $result = $detector->detect($oldText, $response->answerText);

        if ($result['type'] === 'unchanged') {
            $question->update(['last_consulted_at' => now()]);

            return [
                'status' => 'unchanged',
                'message' => 'Sin cambios: la respuesta de Kuaforia es idéntica a la actual.',
            ];
        }

        // 8.4 — Enriquecimiento best-effort con señales MCP, ANTES de la
        // transacción (no se mantiene el lock de fila durante una llamada HTTP
        // de hasta 15 s). Un fallo de señales NUNCA interrumpe el flujo: se
        // registra y se continúa con la notificación base.
        // E2 — las señales usan la credencial y el workspace del repositorio.
        // G7 — sin fallback workspace_map: el workspace sale del repo, con
        // lazy backfill vía get_client_context si falta (repos pre-G7).
        $signalsPayload = null;
        if ($this->signals !== null) {
            try {
                $signalsPayload = $this->collectSignals($repo);
            } catch (\Throwable $e) {
                Log::warning('QuestionChecker: enriquecimiento de señales MCP falló', [
                    'question_id' => $question->id,
                    'tenant' => $tenantSlug,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $nextVersion = null;

        DB::transaction(function () use ($question, $response, $result, $detector, $signalsPayload, &$nextVersion) {
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

        return [
            'status' => 'changed',
            'message' => 'Cambio detectado: se creó la versión '.$nextVersion.' con la respuesta actualizada.',
            'version_number' => $nextVersion,
            'similarity' => $result['similarity'],
        ];
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
    private function collectSignals(Repository $repo): ?array
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
            'workspace_health' => $this->signals->getWorkspaceHealth($workspaceId, $credential),
            'dependency_health_report' => $this->signals->getDependencyHealthReport($workspaceId, $credential),
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

            Log::warning('QuestionChecker: backfill de workspace falló', [
                'repository_id' => $repo->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('QuestionChecker: backfill de workspace falló', [
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

        Log::warning('QuestionChecker: respuesta vacía de Kuaforia', [
            'question_id' => $question->id,
            'tenant' => $tenantSlug,
        ]);
    }
}
