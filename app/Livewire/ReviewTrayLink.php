<?php

namespace App\Livewire;

use App\Services\QbkContributionService;
use Livewire\Component;

/**
 * Entrada en el header que muestra "Revisar" + contador de pendientes.
 * (Ola 2, Punto 1 — Fase B, tarea B.4).
 *
 * El contador consume QbkContributionService::listSessions contra QuBeKa
 * (fuente de verdad), no la tabla local contribution_drafts.
 */
class ReviewTrayLink extends Component
{
    public ?int $pendingCount = null;

    public function mount(): void
    {
        $this->refreshCount();
    }

    public function refreshCount(): void
    {
        if (! auth()->check()) {
            $this->pendingCount = null;

            return;
        }

        try {
            $repo = auth()->user()->repositories()
                ->where('status', 'active')
                ->orderByDesc('is_default')
                ->orderBy('created_at')
                ->first();

            if (! $repo) {
                $this->pendingCount = null;

                return;
            }

            $service = app(QbkContributionService::class);
            $result = $service->listSessions(
                page: 1,
                perPage: 1,
                estado: 'pendientes',
                credential: $repo->credential,
            );

            $this->pendingCount = (int) ($result['data']['total'] ?? 0);
        } catch (\Throwable) {
            // Sin conexión: dejar el contador sin actualizar (no romper la vista).
            $this->pendingCount = null;
        }
    }

    public function render()
    {
        return view('livewire.review-tray-link');
    }
}
