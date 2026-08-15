<?php

namespace App\Livewire;

use Livewire\Component;

/**
 * F2 (UX §6.4) — Indicador de estado de la conexión en el header.
 *
 * Cuando el usuario tiene un repositorio `invalid` (key revocada/inválida, el
 * job lo marca en D4), muestra un badge de advertencia junto al menú de usuario;
 * el clic lleva a /settings con el repo afectado resaltado (?highlight=, P12).
 * Oculto si no hay repos inválidos.
 */
class RepositoryStatusIndicator extends Component
{
    public ?string $invalidRepositoryId = null;

    public function mount(): void
    {
        // Solo el primero alcanza: el clic lleva a /settings donde se listan todos.
        $this->invalidRepositoryId = auth()->user()->repositories()
            ->where('status', 'invalid')
            ->value('id');
    }

    public function render()
    {
        return view('livewire.repository-status-indicator');
    }
}
