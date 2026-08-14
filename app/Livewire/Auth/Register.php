<?php

namespace App\Livewire\Auth;

use App\Exceptions\KuaforiaException;
use App\Models\User;
use App\Services\KuaforiaService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts::app')]
class Register extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $kuaforiaApiKey = '';

    public ?string $resolvedTenantSlug = null;

    public ?string $keyStatus = null;

    public ?string $keyError = null;

    public ?string $registerError = null;

    private KuaforiaService $kuaforia;

    public function boot(KuaforiaService $kuaforia): void
    {
        $this->kuaforia = $kuaforia;
    }

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'kuaforiaApiKey' => 'required|string|min:10',
        ];
    }

    /**
     * Validación en vivo de la API key (debounced desde la vista): resuelve el tenant
     * y muestra la organización conectada; bloquea el submit hasta que la key sea válida.
     */
    public function updatedKuaforiaApiKey(): void
    {
        $this->keyStatus = null;
        $this->keyError = null;
        $this->resolvedTenantSlug = null;

        $key = trim($this->kuaforiaApiKey);

        if ($key === '') {
            return;
        }

        try {
            $resolved = $this->kuaforia->resolveTenantFromApiKey($key);

            $this->resolvedTenantSlug = $resolved['tenant_slug'];
            $this->keyStatus = 'Conectado a Kuaforia.';
        } catch (KuaforiaException $e) {
            $this->keyError = $e->getMessage();
        }
    }

    public function register(): void
    {
        $this->registerError = null;
        $this->validate();

        // Re-valida la key en el submit (el usuario pudo modificarla sin el debounce).
        if (! $this->resolvedTenantSlug) {
            $this->updatedKuaforiaApiKey();
        }

        if (! $this->resolvedTenantSlug) {
            $this->registerError = $this->keyError ?? 'Validá la API key de Kuaforia antes de crear la cuenta.';

            return;
        }

        $user = User::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
            'kuaforia_api_key' => trim($this->kuaforiaApiKey),
            'tenant_slug' => $this->resolvedTenantSlug,
        ]);

        Auth::login($user);
        session()->regenerate();
        $this->redirect(route('onboarding'), navigate: true);
    }

    public function getResolvedTenantNameProperty(): string
    {
        return data_get(
            collect(config('services.kuaforia.tenants'))->firstWhere('slug', $this->resolvedTenantSlug),
            'name',
            $this->resolvedTenantSlug
        );
    }

    public function render()
    {
        return view('livewire.auth.register');
    }
}
