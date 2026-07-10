<div class="min-h-[80vh] flex items-center justify-center py-12 px-4">
    <div class="w-full max-w-sm">
        {{-- Brand --}}
        <div class="text-center mb-8">
            <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-primary/20 to-accent/20 flex items-center justify-center mx-auto mb-5 ring-1 ring-primary/10 shadow-lg shadow-primary/5">
                <i data-lucide="brain" class="w-7 h-7 text-primary"></i>
            </div>
            <h1 class="text-2xl font-bold text-text tracking-tight">Crea tu cuenta</h1>
            <p class="text-sm text-text-muted mt-1.5">Conecta con tu base de conocimiento</p>
        </div>

        <form wire:submit="register" class="relative bg-surface rounded-2xl shadow-sm border border-border p-8 space-y-5">
            @if ($registerError)
                <div class="flex items-center gap-2.5 text-sm text-danger bg-danger/5 rounded-xl px-4 py-3 border border-danger/10" role="alert">
                    <i data-lucide="alert-circle" class="w-4 h-4 shrink-0 mt-0.5"></i>
                    <span>{{ $registerError }}</span>
                </div>
            @endif

            <x-input label="Nombre completo" id="name" type="text" wire:model="name" required autocomplete="name" />
            @error('name') <p class="text-sm text-danger -mt-3">{{ $message }}</p> @enderror

            <x-input label="Email" id="email" type="email" wire:model="email" required autocomplete="email" />
            @error('email') <p class="text-sm text-danger -mt-3">{{ $message }}</p> @enderror

            <x-input label="Contraseña" id="password" type="password" wire:model="password" required autocomplete="new-password" />
            @error('password') <p class="text-sm text-danger -mt-3">{{ $message }}</p> @enderror

            <x-input label="Confirmar contraseña" id="password_confirmation" type="password" wire:model="password_confirmation" required autocomplete="new-password" />

            <div class="w-full">
                <label for="tenantSlug" class="block text-sm font-medium text-text mb-1.5">Organización</label>
                <select id="tenantSlug" wire:model="tenantSlug" required
                    class="w-full border border-border rounded-xl px-3 py-2.5 text-sm text-text bg-surface focus:ring-2 focus:ring-primary/30 focus:border-primary outline-none transition-all duration-150">
                    <option value="">Selecciona tu organización</option>
                    @foreach ($this->tenants as $tenant)
                        <option value="{{ $tenant['slug'] }}">{{ $tenant['name'] }}</option>
                    @endforeach
                </select>
                @error('tenantSlug') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
            </div>

            <button type="submit" wire:loading.attr="disabled"
                class="relative w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl font-medium text-sm transition-all duration-200 bg-accent text-white hover:bg-orange-600 hover:shadow-lg hover:shadow-orange-500/20 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:shadow-none cursor-pointer active:scale-[0.98]">
                <span wire:loading.remove>Crear cuenta</span>
                <span wire:loading class="inline-flex items-center gap-2">
                    <svg class="animate-spin w-4 h-4" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                    </svg>
                    Creando cuenta...
                </span>
            </button>

            <p class="text-center text-sm text-text-muted pt-1">
                ¿Ya tienes cuenta?
                <a href="{{ route('login') }}" wire:navigate class="text-accent hover:text-orange-600 font-semibold transition-colors duration-150">Inicia sesión</a>
            </p>
        </form>
    </div>
</div>
