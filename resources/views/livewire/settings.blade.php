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

    {{-- Conexión con Kuaforia (Bloque 6) --}}
    <section class="bg-surface rounded-2xl shadow-sm border border-border p-6">
        <h2 class="text-base font-semibold text-text mb-4 flex items-center gap-2">
            <i data-lucide="plug" class="w-4 h-4 text-primary"></i>
            Conexión con Kuaforia
        </h2>

        @if ($kuaforiaStatus)
            <div class="flex items-center gap-2.5 text-sm text-success bg-success/5 rounded-xl px-4 py-3 border border-success/10 mb-4" role="status">
                <i data-lucide="check-circle" class="w-4 h-4 shrink-0"></i>
                <span>{{ $kuaforiaStatus }}</span>
            </div>
        @endif

        @if ($kuaforiaError)
            <div class="flex items-center gap-2.5 text-sm text-danger bg-danger/5 rounded-xl px-4 py-3 border border-danger/10 mb-4" role="alert">
                <i data-lucide="alert-circle" class="w-4 h-4 shrink-0"></i>
                <span>{{ $kuaforiaError }}</span>
            </div>
        @endif

        <form wire:submit="updateKuaforiaApiKey" class="space-y-4">
            <x-input label="API key de Kuaforia" id="kuaforiaApiKey" type="password" wire:model="kuaforiaApiKey"
                autocomplete="off" spellcheck="false" placeholder="kfr_..." />
            <p class="text-xs text-text-muted -mt-2">Si la key fue revocada o cambió, pegá la nueva acá para reconectar.</p>

            <div class="flex justify-end">
                <button type="submit"
                    class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl font-medium text-sm transition-all duration-200 bg-accent text-white hover:bg-orange-600 hover:shadow-lg hover:shadow-orange-500/20 cursor-pointer active:scale-[0.98]">
                    Actualizar API key
                </button>
            </div>
        </form>
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
