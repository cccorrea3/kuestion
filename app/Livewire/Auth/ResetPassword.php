<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts::app')]
class ResetPassword extends Component
{
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public ?string $error = null;

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    protected function rules(): array
    {
        return [
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ];
    }

    public function resetPassword(): void
    {
        $this->validate();
        $this->error = null;

        $status = Password::broker()->reset(
            [
                'token' => $this->token,
                'email' => $this->email,
                'password' => $this->password,
                'password_confirmation' => $this->password_confirmation,
            ],
            function ($user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            $user = User::where('email', $this->email)->first();

            if ($user) {
                Auth::login($user);
                session()->regenerate();

                $this->redirect(route('questions.index'), navigate: true);

                return;
            }
        }

        $this->error = 'El enlace es inválido o expiró. Solicitá uno nuevo.';
    }

    public function render()
    {
        return view('livewire.auth.reset-password');
    }
}
