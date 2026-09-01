<?php

namespace App\Jobs;

use App\Models\ContributionDraft;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Limpieza de borradores de aportes viejos (Ola 1, Punto 3 — Fase 4).
 *
 * Elimina contribution_drafts con más de 7 días en estado pending_retry o failed.
 * Se ejecuta diariamente via scheduler.
 */
class CleanupContributionDraftsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        //
    }

    public function handle(): void
    {
        $deleted = ContributionDraft::stale(7)->delete();

        if ($deleted > 0) {
            Log::info("Cleaned up {$deleted} old contribution drafts.");
        }
    }
}
