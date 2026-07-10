<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts::app')]
class Login extends Component
{
    public string $email = '';
    public string $password = '';
    public ?string $loginError = null;

    protected function rules(): array
    {
        return [
            'email' => 'required|email',
            'password' => 'required|string',
        ];
    }

    public function authenticate(): void
    {
        $this->validate();

        $key = 'login:' . request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->loginError = 'Demasiados intentos. Espera ' . RateLimiter::availableIn($key) . ' segundos.';
            return;
        }

        if (Auth::attempt(['email' => $this->email, 'password' => $this->password])) {
            request()->session()->regenerate();
            RateLimiter::clear($key);
            $this->redirect(route('questions.index'), navigate: true);
            return;
        }

        RateLimiter::hit($key, 60);
        $this->loginError = 'Email o contraseña incorrectos.';
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
