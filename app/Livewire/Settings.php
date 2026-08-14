<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts::app')]
class Settings extends Component
{
    public string $name = '';

    public string $email = '';

    public bool $emailNotifications = true;

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPassword_confirmation = '';

    public ?string $profileStatus = null;

    public ?string $profileError = null;

    public ?string $passwordStatus = null;

    public ?string $passwordError = null;

    public function mount(): void
    {
        $user = auth()->user();

        $this->name = $user->name;
        $this->email = $user->email;
        $this->emailNotifications = (bool) $user->email_notifications;
    }

    public function updateProfile(): void
    {
        $this->profileStatus = null;
        $this->profileError = null;

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore(auth()->id())],
        ]);

        auth()->user()->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ]);

        $this->profileStatus = 'Datos actualizados.';
    }

    public function updatePassword(): void
    {
        $this->passwordStatus = null;
        $this->passwordError = null;

        $this->validate([
            'currentPassword' => 'required|string',
            'newPassword' => 'required|string|min:8|confirmed',
        ]);

        if (! Hash::check($this->currentPassword, auth()->user()->password)) {
            $this->passwordError = 'La contraseña actual es incorrecta.';

            return;
        }

        auth()->user()->update([
            'password' => Hash::make($this->newPassword),
        ]);

        $this->currentPassword = '';
        $this->newPassword = '';
        $this->newPassword_confirmation = '';
        $this->passwordStatus = 'Contraseña actualizada.';
    }

    public function toggleEmailNotifications(): void
    {
        auth()->user()->update([
            'email_notifications' => $this->emailNotifications,
        ]);

        $this->profileStatus = $this->emailNotifications
            ? 'Notificaciones por correo activadas.'
            : 'Notificaciones por correo desactivadas.';
    }

    public function render()
    {
        return view('livewire.settings');
    }
}
