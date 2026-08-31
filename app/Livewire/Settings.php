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

    // Conexión con repositorios (genérico para cualquier conector).
    public string $connectorType = 'kuaforia';

    /** @var array<string, string> Credenciales dinámicas: ['api_key' => '...'] */
    public array $credentials = [];

    public ?string $repoStatus = null;

    public ?string $repoError = null;

    public ?string $disconnectId = null;

    public ?string $editingId = null;

    public ?string $highlightId = null;

    private ConnectorRegistry $registry;

    public function boot(ConnectorRegistry $registry): void
    {
        $this->registry = $registry;
    }

    public function mount(): void
    {
        $user = auth()->user();

        $this->name = $user->name;
        $this->email = $user->email;
        $this->emailNotifications = (bool) $user->email_notifications;
        $this->credentials = [];
        // P12: resaltado del repositorio afectado desde el indicador del header.
        $this->highlightId = request()->query('highlight');

        // Si el usuario ya tiene un repo, preseleccionar su tipo.
        $firstRepo = $user->repositories()->first();
        if ($firstRepo) {
            $this->connectorType = $firstRepo->connector_type;
        }
    }

    /**
     * Todos los conectores registrados en config/kuestion.connectors.php.
     */
    public function getConnectorsProperty(): array
    {
        return config('kuestion.connectors', []);
    }

    /**
     * Ficha del conector actualmente seleccionado.
     */
    public function getCurrentConnectorProperty(): ?array
    {
        return $this->connectors[$this->connectorType] ?? null;
    }

    /**
     * Repositorios del usuario (computed cacheado por request).
     */
    public function getRepositoriesProperty(): Collection
    {
        return auth()->user()->repositories()
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Cambiar el tipo de conector seleccionado (resetea formulario).
     */
    public function setConnectorType(string $type): void
    {
        if (! isset($this->connectors[$type])) {
            return;
        }

        $this->connectorType = $type;
        $this->credentials = [];
        $this->repoError = null;
        $this->repoStatus = null;
        $this->editingId = null;
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
     * Guarda la credencial de un repositorio: crea el primero si no hay ninguno,
     * o actualiza el seleccionado. Resuelve identidad según el tipo de conector.
     */
    public function saveRepository(): void
    {
        $this->repoStatus = null;
        $this->repoError = null;

        $connectorConfig = $this->connectors[$this->connectorType] ?? null;
        if (! $connectorConfig) {
            $this->repoError = 'Conector no válido.';

            return;
        }

        // Validar que todas las auth_fields estén presentes.
        $credentialData = [];
        foreach ($connectorConfig['auth_fields'] as $field) {
            $value = trim($this->credentials[$field['key']] ?? '');
            if ($value === '') {
                $this->repoError = "Ingresá el campo: {$field['label']}.";

                return;
            }
            $credentialData[$field['key']] = $value;
        }

        // Resolver identidad con el identity resolver del conector seleccionado.
        $resolverClass = $connectorConfig['identity_resolver'];
        /** @var IdentityResolverInterface $resolver */
        $resolver = app($resolverClass);

        try {
            $identity = $resolver->resolveIdentity($credentialData);
        } catch (KuaforiaMcpException $e) {
            $this->repoError = $e->getMessage();

            return;
        } catch (\Exception $e) {
            $this->repoError = 'No se pudo conectar: '.$e->getMessage();

            return;
        }

        $repositories = $this->repositories;

        // Buscar un repo existente del mismo tipo para actualizar.
        $existingByType = $repositories->firstWhere('connector_type', $this->connectorType);
        // Si hay editingId explícito, usar ese (modo edición).
        $repoToUpdate = $this->editingId
            ? $repositories->firstWhere('id', $this->editingId)
            : $existingByType;

        if ($repoToUpdate) {
            // Actualizar repo existente del mismo tipo (o el que se está editando).
            $repoToUpdate->update([
                'credential' => $credentialData,
                'resolved_tenant_slug' => $identity->tenantSlug,
                'resolved_tenant_name' => $identity->tenantName ?? $identity->tenantSlug,
                'resolved_workspace_id' => $identity->workspaceId,
                'status' => 'active',
                'last_validated_at' => now(),
            ]);

            $this->repoStatus = 'Credencial actualizada. Organización: '.$this->tenantLabel($identity).'.';
            $this->editingId = null;
        } else {
            // Crear un repositorio nuevo (no existe otro del mismo tipo).
            $isFirst = $repositories->isEmpty();
            auth()->user()->repositories()->create([
                'connector_type' => $this->connectorType,
                'name' => $this->repositoryName($identity),
                'credential' => $credentialData,
                'resolved_tenant_slug' => $identity->tenantSlug,
                'resolved_tenant_name' => $identity->tenantName ?? $identity->tenantSlug,
                'resolved_workspace_id' => $identity->workspaceId,
                'status' => 'active',
                'is_default' => $isFirst,
            ]);

            $this->repoStatus = 'Conectado a '.$this->tenantLabel($identity).'.';
        }

        $this->credentials = [];
        unset($this->repositories);
    }

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
        $this->repoStatus = 'Conexión desconectada.';
        unset($this->repositories);
    }

    public function toggleEdit(string $repoId): void
    {
        $this->editingId = $this->editingId === $repoId ? null : $repoId;
        $this->credentials = [];
        $this->repoError = null;
        $this->repoStatus = null;

        // Preseleccionar el tipo del repo que se está editando.
        if ($this->editingId) {
            $repo = $this->repositories->firstWhere('id', $repoId);
            if ($repo) {
                $this->connectorType = $repo->connector_type;
            }
        }
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
        $displayName = $this->currentConnector['display_name'] ?? $this->connectorType;

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
