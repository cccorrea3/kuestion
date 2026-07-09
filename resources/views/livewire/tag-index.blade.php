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
                <a href="{{ route('questions.index', ['tag' => $tag['tag']]) }}" wire:navigate
                    class="bg-surface rounded-xl shadow-sm border border-border p-5 hover:border-primary/30 transition-colors duration-150 cursor-pointer block">
                    <p class="text-sm font-semibold text-text">{{ $tag['tag'] }}</p>
                    <p class="text-xs text-text-muted mt-1">{{ $tag['count'] }} {{ $tag['count'] === 1 ? 'pregunta' : 'preguntas' }}</p>
                </a>
            @endforeach
        </div>
    @endif
</div>
