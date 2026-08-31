<div class="max-w-2xl mx-auto space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-text tracking-tight">Configuración</h1>
        <p class="text-sm text-text-muted mt-1">Datos personales, contraseña y preferencias de notificación</p>
    </div>

    {{-- Datos personales --}}
    <section class="bg-surface rounded-2xl shadow-sm border border-border p-6">
        <h2 class="text-base font-semibold text-text mb-4 flex items-center gap-2">
            <i data-lucide="user" class="w-4 h-4 text-primary"></i>
            Datos personales
        </h2>

        @if ($profileStatus)
            <div class="flex items-center gap-2.5 text-sm text-success bg-success/5 rounded-xl px-4 py-3 border border-success/10 mb-4" role="status">
                <i data-lucide="check-circle" class="w-4 h-4 shrink-0"></i>
                <span>{{ $profileStatus }}</span>
            </div>
        @endif

        <form wire:submit="updateProfile" class="space-y-4">
            <x-input label="Nombre" id="name" type="text" wire:model="name" required autocomplete="name" />
            @error('name') <p class="text-sm text-danger -mt-3">{{ $message }}</p> @enderror

            <x-input label="Email" id="email" type="email" wire:model="email" required autocomplete="email" />
            @error('email') <p class="text-sm text-danger -mt-3">{{ $message }}</p> @enderror

            <div class="flex justify-end">
                <button type="submit"
                    class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl font-medium text-sm transition-all duration-200 bg-accent text-white hover:bg-orange-600 hover:shadow-lg hover:shadow-orange-500/20 cursor-pointer active:scale-[0.98]">
                    Guardar cambios
                </button>
            </div>
        </form>
    </section>

    {{-- Contraseña --}}
    <section class="bg-surface rounded-2xl shadow-sm border border-border p-6">
        <h2 class="text-base font-semibold text-text mb-4 flex items-center gap-2">
            <i data-lucide="lock" class="w-4 h-4 text-primary"></i>
            Cambiar contraseña
        </h2>

        @if ($passwordStatus)
            <div class="flex items-center gap-2.5 text-sm text-success bg-success/5 rounded-xl px-4 py-3 border border-success/10 mb-4" role="status">
                <i data-lucide="check-circle" class="w-4 h-4 shrink-0"></i>
                <span>{{ $passwordStatus }}</span>
            </div>
        @endif

        @if ($passwordError)
            <div class="flex items-center gap-2.5 text-sm text-danger bg-danger/5 rounded-xl px-4 py-3 border border-danger/10 mb-4" role="alert">
                <i data-lucide="alert-circle" class="w-4 h-4 shrink-0"></i>
                <span>{{ $passwordError }}</span>
            </div>
        @endif

        <form wire:submit="updatePassword" class="space-y-4">
            <x-input label="Contraseña actual" id="currentPassword" type="password" wire:model="currentPassword" required autocomplete="current-password" />

            <x-input label="Nueva contraseña" id="newPassword" type="password" wire:model="newPassword" required autocomplete="new-password" />
            @error('newPassword') <p class="text-sm text-danger -mt-3">{{ $message }}</p> @enderror

            <x-input label="Confirmar nueva contraseña" id="newPassword_confirmation" type="password" wire:model="newPassword_confirmation" required autocomplete="new-password" />

            <div class="flex justify-end">
                <button type="submit"
                    class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl font-medium text-sm transition-all duration-200 bg-accent text-white hover:bg-orange-600 hover:shadow-lg hover:shadow-orange-500/20 cursor-pointer active:scale-[0.98]">
                    Actualizar contraseña
                </button>
            </div>
        </form>
    </section>

    {{-- Conexión con repositorios (genérico para cualquier conector) --}}
    <section class="bg-surface rounded-2xl shadow-sm border border-border p-6">
        <h2 class="text-base font-semibold text-text mb-4 flex items-center gap-2">
            <i data-lucide="plug" class="w-4 h-4 text-primary"></i>
            Conexión con fuente de conocimiento
        </h2>

        @if ($repoStatus)
            <div class="flex items-center gap-2.5 text-sm text-success bg-success/5 rounded-xl px-4 py-3 border border-success/10 mb-4" role="status">
                <i data-lucide="check-circle" class="w-4 h-4 shrink-0"></i>
                <span>{{ $repoStatus }}</span>
            </div>
        @endif

        @if ($repoError)
            <div class="flex items-center gap-2.5 text-sm text-danger bg-danger/5 rounded-xl px-4 py-3 border border-danger/10 mb-4" role="alert">
                <i data-lucide="alert-circle" class="w-4 h-4 shrink-0"></i>
                <span>{{ $repoError }}</span>
            </div>
        @endif

        {{-- Selector de conector (solo si hay más de 1) --}}
        @if (count($this->connectors) > 1)
            <div class="flex gap-2 mb-4">
                @foreach ($this->connectors as $type => $config)
                    <button type="button"
                        wire:click="setConnectorType('{{ $type }}')"
                        @class([
                            'px-3 py-1.5 rounded-lg text-xs font-semibold border transition-colors duration-150 cursor-pointer',
                            'bg-accent text-white border-accent' => $connectorType === $type,
                            'border-border text-text-muted hover:text-text hover:border-text-muted/40' => $connectorType !== $type,
                        ])>
                        {{ $config['display_name'] }}
                    </button>
                @endforeach
            </div>
        @endif

        @php
            $connectorConfig = $this->currentConnector;
            $singleActive = $this->repositories->count() === 1 && $this->repositories->first()->status === 'active'
                && $this->repositories->first()->connector_type === $connectorType;
            $connectorRepos = $this->repositories->filter(fn($r) => $r->connector_type === $connectorType);
        @endphp

        @if ($connectorRepos->isEmpty())
            {{-- Primera conexión: formulario plano --}}
            <p class="text-sm text-text-muted mb-4">
                Conectá tu fuente de conocimiento de {{ $connectorConfig['display_name'] ?? 'esta plataforma' }} para empezar a vigilar preguntas.
            </p>

            <form wire:submit="saveRepository" class="space-y-4">
                @foreach ($connectorConfig['auth_fields'] ?? [] as $field)
                    <x-input
                        label="{{ $field['label'] }}"
                        id="cred-{{ $field['key'] }}"
                        type="password"
                        wire:model="credentials.{{ $field['key'] }}"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="{{ $field['key'] === 'api_key' ? 'kfr_...' : ($field['key'] === 'api_token' ? '1|...' : '') }}"
                    />
                @endforeach

                @if ($connectorConfig['help_url'] ?? null)
                    <a href="{{ $connectorConfig['help_url'] }}" target="_blank" rel="noopener"
                        class="text-xs text-primary hover:underline">
                        ¿Cómo obtengo mis credenciales?
                    </a>
                @endif

                <div class="flex justify-end">
                    <button type="submit"
                        class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl font-medium text-sm transition-all duration-200 bg-accent text-white hover:bg-orange-600 hover:shadow-lg hover:shadow-orange-500/20 cursor-pointer active:scale-[0.98]">
                        Conectar
                    </button>
                </div>
            </form>
        @elseif ($singleActive)
            {{-- Un solo repo activo de este tipo: formulario de actualización --}}
            <p class="text-sm text-text-muted mb-4">Si la credencial fue revocada o cambió, pegá la nueva acá para reconectar.</p>

            <form wire:submit="saveRepository" class="space-y-4">
                @foreach ($connectorConfig['auth_fields'] ?? [] as $field)
                    <x-input
                        label="Nueva {{ strtolower($field['label']) }}"
                        id="cred-update-{{ $field['key'] }}"
                        type="password"
                        wire:model="credentials.{{ $field['key'] }}"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="{{ $field['key'] === 'api_key' ? 'kfr_...' : ($field['key'] === 'api_token' ? '1|...' : '') }}"
                    />
                @endforeach

                <div class="flex justify-end">
                    <button type="submit"
                        class="inline-flex items-center justify-content gap-2 px-4 py-2 rounded-xl font-medium text-sm transition-all duration-200 bg-accent text-white hover:bg-orange-600 hover:shadow-lg hover:shadow-orange-500/20 cursor-pointer active:scale-[0.98]">
                        Actualizar credencial
                    </button>
                </div>
            </form>
        @else
            {{-- Varios repos de este tipo: lista --}}
            <div class="space-y-3">
                @foreach ($connectorRepos as $repo)
                    <div @class([
                        'rounded-xl border p-4 transition-all duration-200',
                        'border-accent ring-2 ring-accent/30' => $repo->id === $highlightId,
                        'border-border' => $repo->id !== $highlightId,
                    ])>
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                @if ($connectorRepos->count() > 1)
                                    <p class="text-sm font-semibold text-text truncate">{{ $repo->name }}</p>
                                @endif
                                <p class="text-xs text-text-muted">
                                    {{ $repo->resolved_tenant_name ?? $repo->resolved_tenant_slug }}
                                    @if ($repo->resolved_tenant_slug)
                                        <span class="text-text-muted/70">({{ $repo->resolved_tenant_slug }})</span>
                                    @endif
                                </p>
                            </div>
                            <div class="shrink-0">
                                @if ($repo->status === 'active')
                                    <x-badge variant="success">Activa</x-badge>
                                @elseif ($repo->status === 'invalid')
                                    <x-badge variant="warning">Inactiva</x-badge>
                                @else
                                    <x-badge variant="neutral">Desconectada</x-badge>
                                @endif
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 mt-3">
                            <button type="button" wire:click="toggleEdit('{{ $repo->id }}')"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold border border-border text-text-muted hover:text-text hover:border-text-muted/40 transition-colors duration-150 cursor-pointer">
                                <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i>
                                Actualizar credencial
                            </button>

                            @if ($disconnectId === $repo->id)
                                <span class="text-xs text-text-muted">¿Desconectar esta conexión?</span>
                                <button type="button" wire:click="disconnectRepository"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-danger text-white hover:bg-red-600 transition-colors duration-150 cursor-pointer">
                                    Sí, desconectar
                                </button>
                                <button type="button" wire:click="cancelDisconnect"
                                    class="text-xs text-text-muted hover:text-text transition-colors duration-150 cursor-pointer">
                                    Cancelar
                                </button>
                            @else
                                <button type="button" wire:click="startDisconnect('{{ $repo->id }}')"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-danger border border-danger/20 hover:bg-danger/5 transition-colors duration-150 cursor-pointer">
                                    <i data-lucide="unplug" class="w-3.5 h-3.5"></i>
                                    Desconectar
                                </button>
                            @endif
                        </div>

                        @if ($editingId === $repo->id)
                            <form wire:submit="saveRepository" class="mt-3 pt-3 border-t border-border space-y-3">
                                @foreach ($connectorConfig['auth_fields'] ?? [] as $field)
                                    <x-input
                                        label="Nueva {{ strtolower($field['label']) }}"
                                        id="key-{{ $repo->id }}-{{ $field['key'] }}"
                                        type="password"
                                        wire:model="credentials.{{ $field['key'] }}"
                                        autocomplete="off"
                                        spellcheck="false"
                                        placeholder="{{ $field['key'] === 'api_key' ? 'kfr_...' : ($field['key'] === 'api_token' ? '1|...' : '') }}"
                                    />
                                @endforeach

                                <div class="flex justify-end">
                                    <button type="submit"
                                        class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl font-medium text-sm transition-all duration-200 bg-accent text-white hover:bg-orange-600 hover:shadow-lg hover:shadow-orange-500/20 cursor-pointer active:scale-[0.98]">
                                        Actualizar
                                    </button>
                                </div>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Notificaciones --}}
    <section class="bg-surface rounded-2xl shadow-sm border border-border p-6">
        <h2 class="text-base font-semibold text-text mb-4 flex items-center gap-2">
            <i data-lucide="bell" class="w-4 h-4 text-primary"></i>
            Notificaciones
        </h2>

        <label class="flex items-start gap-3 cursor-pointer">
            <input type="checkbox" wire:model="emailNotifications" wire:change="toggleEmailNotifications"
                class="mt-1 w-4 h-4 rounded border-border text-accent focus:ring-primary/30 cursor-pointer">
            <span class="text-sm leading-relaxed">
                <span class="font-medium text-text block">Recibir correos cuando una respuesta cambia</span>
                <span class="text-text-muted">Te avisamos por email cada vez que una pregunta vigilada detecta un cambio, con un enlace directo para revisarlo.</span>
            </span>
        </label>
    </section>
</div>
