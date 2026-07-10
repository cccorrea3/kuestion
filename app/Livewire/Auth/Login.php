<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
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

        if (Auth::attempt(['email' => $this->email, 'password' => $this->password])) {
            $this->redirect(route('questions.index'), navigate: true);
        }

        $this->loginError = 'Email o contraseña incorrectos.';
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
