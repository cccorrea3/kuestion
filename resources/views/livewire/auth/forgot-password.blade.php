<div class="min-h-[80vh] flex items-center justify-center py-12 px-4">
    <div class="w-full max-w-sm">
        {{-- Brand --}}
        <div class="text-center mb-8">
            <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-primary/20 to-accent/20 flex items-center justify-center mx-auto mb-5 ring-1 ring-primary/10 shadow-lg shadow-primary/5">
                <i data-lucide="key-round" class="w-7 h-7 text-primary"></i>
            </div>
            <h1 class="text-2xl font-bold text-text tracking-tight">Recuperar contraseña</h1>
            <p class="text-sm text-text-muted mt-1.5">Te enviamos un enlace para restablecerla</p>
        </div>

        <form wire:submit="sendResetLink" class="relative bg-surface rounded-2xl shadow-sm border border-border p-8 space-y-5">
            @if ($status)
                <div class="flex items-center gap-2.5 text-sm text-success bg-success/5 rounded-xl px-4 py-3 border border-success/10" role="status">
                    <i data-lucide="check-circle" class="w-4 h-4 shrink-0 mt-0.5"></i>
                    <span>{{ $status }}</span>
                </div>
            @endif

            @if ($error)
                <div class="flex items-center gap-2.5 text-sm text-danger bg-danger/5 rounded-xl px-4 py-3 border border-danger/10" role="alert">
                    <i data-lucide="alert-circle" class="w-4 h-4 shrink-0 mt-0.5"></i>
                    <span>{{ $error }}</span>
                </div>
            @endif

            <x-input label="Email" id="email" type="email" wire:model="email" required autocomplete="email" />
            @error('email') <p class="text-sm text-danger -mt-3">{{ $message }}</p> @enderror

            <button type="submit" wire:loading.attr="disabled"
                class="relative w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl font-medium text-sm transition-all duration-200 bg-accent text-white hover:bg-orange-600 hover:shadow-lg hover:shadow-orange-500/20 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:shadow-none cursor-pointer active:scale-[0.98]">
                <span wire:loading.remove>Enviar enlace</span>
                <span wire:loading class="inline-flex items-center gap-2">
                    <svg class="animate-spin w-4 h-4" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                    </svg>
                    Enviando...
                </span>
            </button>

            <p class="text-center text-sm text-text-muted pt-1">
                <a href="{{ route('login') }}" wire:navigate class="text-accent hover:text-orange-600 font-semibold transition-colors duration-150">Volver a iniciar sesión</a>
            </p>
        </form>
    </div>
</div>
