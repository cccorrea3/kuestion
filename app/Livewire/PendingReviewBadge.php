<?php

namespace App\Livewire;

use App\Models\ContributionDraft;
use Livewire\Component;

/**
 * Badge en el header que muestra la cantidad de aportes pendientes de revisión.
 * (Ola 1, Punto 4 — Fase 3, task 3.3)
 *
 * Aparece junto a las notificaciones existentes en el header.
 * Al clickear, lleva al detalle de la sesión más reciente pendiente.
 */
class PendingReviewBadge extends Component
{
    public int $count = 0;

    public ?int $latestSessionId = null;

    public function mount(): void
    {
        $this->refreshCount();
    }

    public function refreshCount(): void
    {
        $userId = auth()->user()?->uuid;

        if (! $userId) {
            $this->count = 0;

            return;
        }

        $pending = ContributionDraft::pendingReview()
            ->forUser($userId)
            ->latest('id')
            ->get();

        $this->count = $pending->count();
        $this->latestSessionId = $pending->first()?->qbk_session_id;
    }

    public function render()
    {
        return view('livewire.pending-review-badge');
    }
}
