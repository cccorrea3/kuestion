<?php

namespace App\Jobs;

use App\Mail\ContributionDecisionMail;
use App\Models\ContributionDraft;
use App\Services\EmailDispatcher;
use App\Services\QbkContributionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Ola 2, Punto 5 — Fase C.1: detecta transiciones de estado de sesiones de
 * aportes hechos desde Kuestion y envía el correo al autor (C.2).
 *
 * Spec §6-3 (decisión pragmática): Kuestion consulta periódicamente el estado
 * en QuBeKa vía GET /sesiones-analisis/{id}. Los finales contractuales son
 * `promocionada` (aprobado) y `rechazada` — no `aprobada` (transitorio).
 *
 * Alcance declarado (C.3): solo aportes hechos desde Kuestion (contribution_drafts
 * con autor local). Los drafts en STATUS_REVIEWED ya tienen decisión conocida
 * (revisión local del propio autor) y no se notifican.
 * C.4 alimenta el copy con revisado_por_* (contrato v1.3).
 * El dedupe de A.2 garantiza un solo correo entre corridas.
 */
class CheckContributionStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public array $backoff = [60, 300, 900];

    public function handle(QbkContributionService $service, EmailDispatcher $dispatcher): void
    {
        $drafts = ContributionDraft::query()
            ->with('user', 'repository')
            ->where('status', ContributionDraft::STATUS_SENT)
            ->whereNotNull('qbk_session_id')
            ->whereHas('user')
            ->get();

        foreach ($drafts as $draft) {
            // Sin repositorio resuelto no hay credencial para consultar: registrar y seguir.
            if (! $draft->repository) {
                Log::warning('CheckContributionStatusJob: draft sin repositorio', [
                    'draft_id' => $draft->id,
                    'qbk_session_id' => $draft->qbk_session_id,
                ]);

                continue;
            }

            try {
                $session = $service->getSession((int) $draft->qbk_session_id, $draft->repository->credential ?? null);
            } catch (\Throwable $e) {
                // FC.4 — QuBeKa caído/timeout/404: registrar y seguir; el draft mantiene
                // su estado y la próxima corrida reintenta. Nunca se marca estado falso.
                Log::warning('CheckContributionStatusJob: no se pudo consultar la sesión', [
                    'draft_id' => $draft->id,
                    'qbk_session_id' => $draft->qbk_session_id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $status = $session['status'] ?? '';

            if (in_array($status, ['promocionada', 'rechazada'], true)) {
                $this->notifyDecision($draft, $status, $session, $dispatcher);
            }
        }
    }

    /** C.2 — correo al autor con el estado, una sola vez (dedupe A.2 + draft a REVIEWED). */
    private function notifyDecision(ContributionDraft $draft, string $status, array $session, EmailDispatcher $dispatcher): void
    {
        $aprobado = $status === 'promocionada';
        $eventType = $aprobado ? 'contribution_approved' : 'contribution_rejected';
        $referenceKey = (string) $draft->qbk_session_id;

        if (! $dispatcher->shouldSend($draft->user, $eventType, $referenceKey)) {
            return;
        }

        Mail::to($draft->user->email)->send(new ContributionDecisionMail(
            aprobado: $aprobado,
            textoAporte: $draft->texto,
            sessionId: (int) $draft->qbk_session_id,
            userId: $draft->user->id,
            // C.4 — "aprobado por [nombre]" cuando QuBeKa expuso la identidad del revisor.
            revisadoPorNombre: $session['revisado_por_nombre'] ?? null,
        ));

        $dispatcher->logSent($draft->user, $eventType, $referenceKey);

        // Decisión notificada: el draft pasa a REVIEWED y sale del polling.
        $draft->update(['status' => ContributionDraft::STATUS_REVIEWED]);

        Log::info('CheckContributionStatusJob: decisión notificada al autor', [
            'draft_id' => $draft->id,
            'qbk_session_id' => $draft->qbk_session_id,
            'status' => $status,
        ]);
    }
}
