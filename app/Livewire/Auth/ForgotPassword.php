<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts::app')]
class ForgotPassword extends Component
{
    public string $email = '';

    public ?string $status = null;

    public ?string $error = null;

    protected function rules(): array
    {
        return [
            'email' => 'required|email',
        ];
    }

    public function sendResetLink(): void
    {
        $this->validate();
        $this->status = null;
        $this->error = null;

        $status = Password::broker()->sendResetLink(['email' => $this->email]);

        if ($status === Password::RESET_LINK_SENT) {
            // No revelar si el email existe: mensaje genérico en ambos casos.
            $this->status = 'Si el email existe, te enviamos un enlace para restablecer tu contraseña.';
            $this->email = '';

            return;
        }

        $this->error = 'No pudimos procesar la solicitud. Verificá el email e intentá de nuevo.';
    }

    public function render()
    {
        return view('livewire.auth.forgot-password');
    }
}
