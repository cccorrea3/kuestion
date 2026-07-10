<div class="flex items-center justify-center py-12">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <div class="w-12 h-12 rounded-2xl bg-primary/10 flex items-center justify-center mx-auto mb-4">
                <i data-lucide="brain" class="w-6 h-6 text-primary"></i>
            </div>
            <h1 class="text-2xl font-bold text-text">Crea tu cuenta en Kuestion</h1>
            <p class="text-sm text-text-muted mt-1">Conecta con tu base de conocimiento en Kuaforia</p>
        </div>

        <form wire:submit="register" class="bg-surface rounded-xl shadow-sm border border-border p-6 space-y-4">
            @if ($registerError)
                <div class="flex items-center gap-2 text-sm text-danger bg-danger/5 rounded-lg px-4 py-3" role="alert">
                    <i data-lucide="alert-circle" class="w-4 h-4 shrink-0"></i>
                    <span>{{ $registerError }}</span>
                </div>
            @endif

            <x-input label="Nombre completo" id="name" type="text" wire:model="name" required autocomplete="name" />
            @error('name') <p class="text-sm text-danger -mt-2">{{ $message }}</p> @enderror

            <x-input label="Email" id="email" type="email" wire:model="email" required autocomplete="email" />
            @error('email') <p class="text-sm text-danger -mt-2">{{ $message }}</p> @enderror

            <x-input label="Contraseña" id="password" type="password" wire:model="password" required autocomplete="new-password" />
            @error('password') <p class="text-sm text-danger -mt-2">{{ $message }}</p> @enderror

            <x-input label="Confirmar contraseña" id="password_confirmation" type="password" wire:model="password_confirmation" required autocomplete="new-password" />

            <div class="w-full">
                <label for="tenantSlug" class="block text-sm font-medium text-text mb-1">Organización</label>
                <select id="tenantSlug" wire:model="tenantSlug" required
                    class="w-full border border-border rounded-lg px-3 py-2 text-sm text-text bg-surface focus:ring-2 focus:ring-primary/30 focus:border-primary outline-none transition-all duration-150">
                    <option value="">Selecciona tu organización</option>
                    @foreach ($this->tenants as $tenant)
                        <option value="{{ $tenant['slug'] }}">{{ $tenant['name'] }}</option>
                    @endforeach
                </select>
                @error('tenantSlug') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
            </div>

            <button type="submit" wire:loading.attr="disabled"
                class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg font-medium text-sm transition-colors duration-150 bg-accent text-white hover:bg-orange-600 disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer">
                <span wire:loading.remove>Crear cuenta</span>
                <span wire:loading>
                    <svg class="animate-spin w-4 h-4" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                    </svg>
                    Creando cuenta...
                </span>
            </button>

            <p class="text-center text-sm text-text-muted">
                ¿Ya tienes cuenta?
                <a href="{{ route('login') }}" wire:navigate class="text-accent hover:text-orange-600 font-medium">Inicia sesión</a>
            </p>
        </form>
    </div>
</div>
