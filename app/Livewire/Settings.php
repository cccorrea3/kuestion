<?php

namespace App\Livewire;

use App\Contracts\IdentityResolverInterface;
use App\Exceptions\KuaforiaMcpException;
use App\Models\Repository;
use App\Services\ConnectorRegistry;
use App\Services\ResolvedIdentity;
use Illuminate\Support\Collection;
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

    // Conexión con Kuaforia (Fase C — repositorios).
    public string $kuaforiaApiKey = '';

    public ?string $kuaforiaStatus = null;

    public ?string $kuaforiaError = null;

    public ?string $disconnectId = null;

    public ?string $editingId = null;

    public ?string $highlightId = null;

    private IdentityResolverInterface $resolver;

    private ConnectorRegistry $registry;

    public function boot(IdentityResolverInterface $resolver, ConnectorRegistry $registry): void
    {
        $this->resolver = $resolver;
        $this->registry = $registry;
    }

    public function mount(): void
    {
        $user = auth()->user();

        $this->name = $user->name;
        $this->email = $user->email;
        $this->emailNotifications = (bool) $user->email_notifications;
        // La key cifrada no se vuelve a mostrar en el form (no exponerla en el DOM).
        $this->kuaforiaApiKey = '';
        // P12: resaltado del repositorio afectado desde el indicador del header.
        $this->highlightId = request()->query('highlight');
    }

    /**
     * Repositorios del usuario (computed cacheado por request — se invalida tras
     * cada mutación con forgetComputed).
     */
    public function getRepositoriesProperty(): Collection
    {
        return auth()->user()->repositories()
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->get();
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
     * C2 — Guarda la credencial de un repositorio: crea el primero si no hay ninguno
     * (flujo 5.1, formulario plano sin nombre), o actualiza el seleccionado (o el
     * default si hay uno solo). Re-valida con resolveIdentity (§6.6); 401 → key
     * inválida, otros fallos → servicio no disponible (§6.11).
     */
    public function saveRepository(): void
    {
        $this->kuaforiaStatus = null;
        $this->kuaforiaError = null;

        $key = trim($this->kuaforiaApiKey);

        if ($key === '') {
            $this->kuaforiaError = 'Ingresá tu API key de Kuaforia.';

            return;
        }

        try {
            $identity = $this->resolver->resolveIdentity(['api_key' => $key]);
        } catch (KuaforiaMcpException $e) {
            $this->kuaforiaError = $e->getMessage();

            return;
        }

        $repositories = $this->repositories;

        if ($repositories->isEmpty()) {
            auth()->user()->repositories()->create([
                'connector_type' => 'kuaforia',
                'name' => $this->repositoryName($identity),
                'credential' => ['api_key' => $key],
                'resolved_tenant_slug' => $identity->tenantSlug,
                'resolved_tenant_name' => $identity->tenantName ?? $identity->tenantSlug,
                'status' => 'active',
                'is_default' => true,
            ]);

            $this->kuaforiaStatus = 'Conectado a '.$this->tenantLabel($identity).'.';
        } else {
            $repo = $repositories->firstWhere('id', $this->editingId) ?? $repositories->first();

            $repo->update([
                'credential' => ['api_key' => $key],
                'resolved_tenant_slug' => $identity->tenantSlug,
                'resolved_tenant_name' => $identity->tenantName ?? $identity->tenantSlug,
                'status' => 'active', // reconectar revive un repo invalid/revoked
                'last_validated_at' => now(),
            ]);

            $this->kuaforiaStatus = 'API key actualizada. Organización: '.$this->tenantLabel($identity).'.';
            $this->editingId = null;
        }

        $this->kuaforiaApiKey = '';
        // Livewire 4: unset() de la propiedad computada invalida su caché.
        unset($this->repositories);
    }

    /**
     * C2 — Confirma la desconexión de un repositorio. P5: se permite desconectar el
     * único repositorio activo; el sistema cae en el estado "0 repos activos"
     * (bloqueo en creación + onboarding del feed vacío), ya diseñado en §5.4.
     */
    public function startDisconnect(string $repoId): void
    {
        $this->disconnectId = $repoId;
    }

    public function cancelDisconnect(): void
    {
        $this->disconnectId = null;
    }

    public function disconnectRepository(): void
    {
        $repo = $this->repositories->firstWhere('id', $this->disconnectId);

        if (! $repo) {
            return;
        }

        $repo->update([
            'status' => 'revoked',
            'last_validated_at' => now(),
        ]);

        $this->disconnectId = null;
        $this->kuaforiaStatus = 'Conexión desconectada.';
        unset($this->repositories);
    }

    public function toggleEdit(string $repoId): void
    {
        $this->editingId = $this->editingId === $repoId ? null : $repoId;
        $this->kuaforiaApiKey = '';
        $this->kuaforiaError = null;
        $this->kuaforiaStatus = null;
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

    private function repositoryName(ResolvedIdentity $identity): string
    {
        $displayName = $this->registry->connector('kuaforia')['display_name'];

        return Repository::defaultName($displayName, $identity->tenantName ?? $identity->tenantSlug);
    }

    private function tenantLabel(ResolvedIdentity $identity): string
    {
        return ($identity->tenantName ?? $identity->tenantSlug).' ('.$identity->tenantSlug.')';
    }

    public function render()
    {
        return view('livewire.settings');
    }
}
