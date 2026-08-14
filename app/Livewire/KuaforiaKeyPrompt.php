<?php

namespace App\Livewire;

use Livewire\Component;

// 6.7 — Prompt opcional no bloqueante: usuarios creados antes del Bloque 6 (sin key kfr_)
// ven una invitación a conectar su key la próxima vez que entren. Descartable por sesión;
// no es obligatorio y no interfiere con el uso actual (tenant_slug ya persistido sigue
// funcionando para la consulta REST).
class KuaforiaKeyPrompt extends Component
{
    public bool $visible = false;

    public function mount(): void
    {
        $this->visible = auth()->check()
            && blank(auth()->user()->kuaforia_api_key)
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
