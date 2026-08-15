<?php

namespace App\Livewire\Auth;

use App\Contracts\IdentityResolverInterface;
use App\Exceptions\KuaforiaMcpException;
use App\Models\Repository;
use App\Models\User;
use App\Services\ConnectorRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    public ?string $resolvedTenantName = null;

    public ?string $keyStatus = null;

    public ?string $keyError = null;

    public ?string $registerError = null;

    private IdentityResolverInterface $resolver;

    private ConnectorRegistry $registry;

    public function boot(IdentityResolverInterface $resolver, ConnectorRegistry $registry): void
    {
        $this->resolver = $resolver;
        $this->registry = $registry;
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
     * Validación en vivo de la API key (debounced desde la vista): resuelve la
     * identidad vía MCP (Fase B) y muestra la organización conectada (§6.2);
     * bloquea el submit hasta que la key sea válida. La validación en vivo ya
     * existía (Bloque 6) — se adapta a IdentityResolver + tenant_name (nota B4).
     */
    public function updatedKuaforiaApiKey(): void
    {
        $this->keyStatus = null;
        $this->keyError = null;
        $this->resolvedTenantSlug = null;
        $this->resolvedTenantName = null;

        $key = trim($this->kuaforiaApiKey);

        if ($key === '') {
            return;
        }

        try {
            $identity = $this->resolver->resolveIdentity(['api_key' => $key]);

            $this->resolvedTenantSlug = $identity->tenantSlug;
            $this->resolvedTenantName = $identity->tenantName ?? $identity->tenantSlug;
            $this->keyStatus = 'Conectado a '.$this->resolvedTenantName.' ('.$identity->tenantSlug.').';
        } catch (KuaforiaMcpException $e) {
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

        // C1: usuario + primer repositorio en la misma transacción (garantiza que un
        // usuario sin repositorio no exista). Las columnas de users ya no se escriben.
        $user = DB::transaction(function () {
            $user = User::create([
                'name' => $this->name,
                'email' => $this->email,
                'password' => $this->password,
            ]);

            $user->repositories()->create([
                'connector_type' => 'kuaforia',
                'name' => $this->repositoryName(),
                'credential' => ['api_key' => trim($this->kuaforiaApiKey)],
                'resolved_tenant_slug' => $this->resolvedTenantSlug,
                'resolved_tenant_name' => $this->resolvedTenantName ?? $this->resolvedTenantSlug,
                'status' => 'active',
                'is_default' => true,
            ]);

            return $user;
        });

        Auth::login($user);
        session()->regenerate();
        $this->redirect(route('onboarding'), navigate: true);
    }

    /**
     * Nombre autogenerado del primer repositorio (P7): "{display_name} - {tenant_name}",
     * truncado a 100 caracteres. El display_name sale del registro de conectores.
     */
    private function repositoryName(): string
    {
        $displayName = $this->registry->connector('kuaforia')['display_name'];

        return Repository::defaultName($displayName, $this->resolvedTenantName ?? $this->resolvedTenantSlug);
    }

    public function render()
    {
        return view('livewire.auth.register');
    }
}
