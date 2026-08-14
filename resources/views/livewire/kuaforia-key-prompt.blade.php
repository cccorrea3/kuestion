@if ($visible)
    <div class="max-w-4xl mx-auto px-4 sm:px-6 pt-4" role="note">
        <div class="flex items-start gap-3 bg-accent/5 border border-accent/20 rounded-xl px-4 py-3 text-sm">
            <i data-lucide="plug" class="w-4 h-4 text-accent shrink-0 mt-0.5"></i>
            <div class="flex-1">
                <p class="text-text font-medium">Conectá tu API key de Kuaforia</p>
                <p class="text-text-muted text-xs mt-0.5">Vinculá tu cuenta con tu base de conocimiento. Lleva un minuto.</p>
            </div>
            <a href="{{ route('settings') }}" wire:navigate
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-accent text-white text-xs font-semibold hover:bg-orange-600 transition-colors duration-150">
                Conectar
            </a>
            <button wire:click="dismiss" title="Descartar"
                class="text-text-muted hover:text-text transition-colors duration-150 cursor-pointer" aria-label="Descartar">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
    </div>
@endif
