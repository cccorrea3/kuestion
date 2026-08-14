<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-bold text-text">Tags</h1>
    </div>

    <div class="relative mb-6">
        <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-text-muted pointer-events-none"></i>
        <input type="text" wire:model.live.debounce.300ms="search"
            placeholder="Buscar tags..."
            class="w-full border border-border rounded-lg px-3 py-2 pl-9 text-sm text-text placeholder-text-muted/50 focus:ring-2 focus:ring-primary/30 focus:border-primary outline-none transition-all duration-150 bg-surface">
    </div>

    @if (count($tags) === 0)
        <div class="flex flex-col items-center justify-center py-16">
            <i data-lucide="tags" class="w-12 h-12 text-text-muted/30 mb-3"></i>
            <p class="text-sm text-text-muted">Todavía no hay tags</p>
        </div>
    @else
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            @foreach ($tags as $tag)
                <div class="relative bg-surface rounded-xl shadow-sm border border-border p-5 hover:border-primary/30 transition-colors duration-150">
                    {{-- El card completo mantiene el enlace por tag simple (comportamiento actual). --}}
                    <a href="{{ route('questions.index', ['tag' => $tag['tag']]) }}" wire:navigate class="block">
                        <p class="text-sm font-semibold text-text pr-16">{{ $tag['tag'] }}</p>
                        <p class="text-xs text-text-muted mt-1">{{ $tag['count'] }} {{ $tag['count'] === 1 ? 'pregunta' : 'preguntas' }}</p>
                    </a>

                    {{-- 13.2/13.4 — Badge "sin revisar" (oculto si 0), mismo estilo que el feed;
                         al hacer clic filtra el feed por tag + "con cambios". --}}
                    @if ($tag['unreviewed'] > 0)
                        <a href="{{ route('questions.index', ['filter' => 'changes', 'tag' => $tag['tag']]) }}" wire:navigate
                            class="absolute top-4 right-4 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-700 hover:bg-orange-200 transition-colors duration-150 cursor-pointer"
                            title="Ver cambios sin revisar en {{ $tag['tag'] }}">
                            <span class="w-1.5 h-1.5 rounded-full bg-orange-500"></span>
                            {{ $tag['unreviewed'] }} sin revisar
                        </a>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
