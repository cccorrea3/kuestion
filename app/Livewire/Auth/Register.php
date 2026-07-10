<?php

namespace App\Livewire\Auth;

use App\Models\User;
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
    public string $tenantSlug = '';
    public ?string $registerError = null;

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'tenantSlug' => 'required|string|max:100',
        ];
    }

    public function mount(): void
    {
        $tenants = config('services.kuaforia.tenants', []);
        if (count($tenants) === 1) {
            $this->tenantSlug = $tenants[0]['slug'];
        }
    }

    public function register(): void
    {
        $this->validate();

        $tenantExists = collect(config('services.kuaforia.tenants', []))
            ->contains(fn ($t) => $t['slug'] === $this->tenantSlug);

        if (!$tenantExists) {
            $this->registerError = 'La organización seleccionada no es válida.';
            return;
        }

        $user = User::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
            'tenant_slug' => $this->tenantSlug,
        ]);

        Auth::login($user);
        request()->session()->regenerate();
        $this->redirect(route('onboarding'), navigate: true);
    }

    public function getTenantsProperty(): array
    {
        return config('services.kuaforia.tenants', []);
    }

    public function render()
    {
        return view('livewire.auth.register');
    }
}
