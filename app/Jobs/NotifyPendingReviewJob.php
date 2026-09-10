<?php

namespace App\Jobs;

use App\Mail\PendingReviewMail;
use App\Models\Repository;
use App\Models\User;
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
 * Ola 2, Punto 5 — Fase D: correo "aporte pendiente de revisión" a los revisores
 * del workspace (spec §2.1, flujo 4.2).
 *
 * D.1 — detección: listado de sesiones pendientes de QuBeKa (§4.1, commit cd218e6).
 * D.2 — destinatarios: GET /workspaces/{id}/miembros (§8.2, v1.4) filtrando los
 *       roles de revisión. En QuBeKa pueden revisar propietario/editor/revisor
 *       (User::puedeRevisarEn) — el propietario se incluye.
 * Dedupe: un correo por (revisor, sesión) — la ventana de A.2 evita repeticiones.
 */
class NotifyPendingReviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public array $backoff = [60, 300, 900];

    public function handle(QbkContributionService $service, EmailDispatcher $dispatcher): void
    {
        // Un job por repositorio activo de tipo QBK (cada uno resuelve su workspace).
        $repos = Repository::query()
            ->with('user')
            ->where('connector_type', 'qbk')
            ->where('status', 'active')
            ->whereNotNull('resolved_workspace_id')
            ->get();

        foreach ($repos as $repo) {
            try {
                $this->processRepository($repo, $service, $dispatcher);
            } catch (\Throwable $e) {
                // FD — fallo de una conexión no aborta el barrido de las demás;
                // el error queda registrado y la próxima corrida reintenta.
                Log::warning('NotifyPendingReviewJob: error procesando repositorio', [
                    'repository_id' => $repo->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function processRepository($repo, QbkContributionService $service, EmailDispatcher $dispatcher): void
    {
        // D.1 — sesiones pendientes del workspace (solo la primera página: los nuevos).
        $listado = $service->listSessions(page: 1, perPage: 50, estado: 'pendientes', credential: $repo->credential);
        $pendientes = $listado['data']['items'] ?? [];

        if ($pendientes === []) {
            return;
        }

        // D.2 — revisores del workspace (endpoint ya entregado por QuBeKa).
        $miembros = $service->listarMiembros((string) $repo->resolved_workspace_id, $repo->credential);
        $revisores = array_values(array_filter(
            $miembros,
            fn (array $m): bool => $m['email'] !== '' && in_array($m['rol'], ['propietario', 'editor', 'revisor'], true),
        ));

        if ($revisores === []) {
            return;
        }

        foreach ($pendientes as $item) {
            $sessionId = (string) ($item['session_id'] ?? '');
            if ($sessionId === '' || $sessionId === '0') {
                continue;
            }

            $texto = (string) ($item['texto_original_del_aporte'] ?? '');
            $autorNombre = trim((string) ($item['autor_nombre'] ?? '')) ?: 'Alguien';

            foreach ($revisores as $revisor) {
                $user = User::query()->where('email', $revisor['email'])->first();
                if (! $user) {
                    // Miembro de QuBeKa sin cuenta en Kuestion: no tiene bandeja ni
                    // preferencia local; no se le puede enviar con control de baja.
                    continue;
                }

                if (! $dispatcher->shouldSend($user, 'pending_review', $sessionId)) {
                    continue;
                }

                Mail::to($user->email)->send(new PendingReviewMail(
                    autorNombre: $autorNombre,
                    textoAporte: $texto,
                    sessionId: (int) $sessionId,
                    userId: $user->id,
                ));

                $dispatcher->logSent($user, 'pending_review', $sessionId);
            }
        }
    }
}
