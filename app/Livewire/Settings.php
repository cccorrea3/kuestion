<?php

namespace App\Livewire;

use App\Exceptions\KuaforiaException;
use App\Services\KuaforiaService;
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

    public string $kuaforiaApiKey = '';

    public ?string $kuaforiaStatus = null;

    public ?string $kuaforiaError = null;

    public function mount(): void
    {
        $user = auth()->user();

        $this->name = $user->name;
        $this->email = $user->email;
        $this->emailNotifications = (bool) $user->email_notifications;
        $this->kuaforiaApiKey = $user->kuaforia_api_key ?? '';
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

    /**
     * 6.6 — Re-validación de la API key de Kuaforia desde /settings: reutiliza el mismo
     * resolver de 6.1; valida la key nueva y actualiza la key cifrada (+ tenant_slug si cambia).
     */
    public function updateKuaforiaApiKey(): void
    {
        $this->kuaforiaStatus = null;
        $this->kuaforiaError = null;

        $key = trim($this->kuaforiaApiKey);

        if ($key === '') {
            $this->kuaforiaError = 'Ingresá tu API key de Kuaforia.';

            return;
        }

        try {
            $resolved = app(KuaforiaService::class)->resolveTenantFromApiKey($key);
        } catch (KuaforiaException $e) {
            $this->kuaforiaError = $e->getMessage();

            return;
        }

        auth()->user()->update([
            'kuaforia_api_key' => $key,
            'tenant_slug' => $resolved['tenant_slug'],
        ]);

        $this->kuaforiaStatus = 'API key actualizada. Organización: '.$resolved['tenant_slug'].'.';
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
