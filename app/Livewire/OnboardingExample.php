<?php

namespace App\Livewire;

use App\Services\DiffGenerator;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * 11.1 — Ejemplo interactivo de onboarding con datos HARDCODEADOS.
 *
 * No consulta modelos ni persiste nada salvo el flag has_seen_example al omitir.
 * Reutiliza DiffGenerator (el mismo motor del diff real) sobre strings ficticios,
 * de modo que el usuario ve exactamente el formato visual que verá con sus
 * preguntas reales. Estados: idle → diff → accepted | dismissed.
 */
#[Layout('layouts::app')]
class OnboardingExample extends Component
{
    public string $status = 'idle';

    public bool $hidden = false;

    public array $diffLines = [];

    public string $questionText = '¿Cuál es la política de reembolsos?';

    public string $oldAnswer = "Los reembolsos se procesan dentro de los 10 días hábiles posteriores a la solicitud.\nPara solicitarlo, envía un correo a soporte@empresa.com.";

    public string $newAnswer = "Los reembolsos se procesan dentro de los 5 días hábiles posteriores a la solicitud.\nPara solicitarlo, envía un correo a soporte@empresa.com.\nLos reembolsos de membresías anuales se procesan de forma prioritaria.";

    public function simulateChange(): void
    {
        $this->diffLines = (new DiffGenerator)->diff($this->oldAnswer, $this->newAnswer)['lines'];
        $this->status = 'diff';
    }

    public function acceptChange(): void
    {
        $this->status = 'accepted';
    }

    public function dismissChange(): void
    {
        $this->status = 'dismissed';
    }

    /**
     * 11.3 — "Omitir": solo marca el flag y oculta el ejemplo; no borra nada.
     */
    public function skip(): void
    {
        if ($user = auth()->user()) {
            $user->update(['has_seen_example' => true]);
        }

        $this->hidden = true;
    }

    public function render()
    {
        return view('livewire.onboarding-example');
    }
}
