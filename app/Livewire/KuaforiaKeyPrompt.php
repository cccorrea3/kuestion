<?php

namespace App\Livewire;

use Livewire\Component;

// 6.7 — Prompt opcional no bloqueante: usuarios sin repositorios conectados ven una
// invitación a conectar su fuente de conocimiento. Descartable por sesión.
// Fase C (decisión B1): evalúa $user->repositories->isEmpty() en lugar de la key en users.
class KuaforiaKeyPrompt extends Component
{
    public bool $visible = false;

    public function mount(): void
    {
        $this->visible = auth()->check()
            && auth()->user()->repositories()->doesntExist()
            && ! session()->get('kuaforia_key_prompt_dismissed', false);
    }

    public function dismiss(): void
    {
        session()->put('kuaforia_key_prompt_dismissed', true);
        $this->visible = false;
    }

    public function render()
    {
        return view('livewire.kuaforia-key-prompt');
    }
}
