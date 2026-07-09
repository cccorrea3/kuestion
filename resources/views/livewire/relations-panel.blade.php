<div>
    <div class="flex items-center gap-2 mb-4">
        <i data-lucide="link" class="w-5 h-5 text-text-muted"></i>
        <h3 class="text-sm font-semibold text-text">Relaciones</h3>
    </div>

    @if ($this->relations->count() > 0)
        <div class="space-y-2 mb-4">
            @foreach ($this->relations as $relation)
                <div class="flex items-center justify-between p-3 rounded-lg bg-page border border-border">
                    <div class="flex-1 min-w-0">
                        <p class="text-sm text-text truncate">{{ str($relation->target->question_text)->limit(80) }}</p>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="text-xs text-text-muted">{{ $relation->label }}</span>
                            @if ($relation->relation_type === 'manual')
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-teal-100 text-primary text-xs font-medium">manual</span>
                            @endif
                        </div>
                    </div>
                    <button wire:click="removeRelation('{{ $relation->id }}')"
                        class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-text-muted hover:text-danger hover:bg-page transition-colors duration-150 cursor-pointer shrink-0"
                        aria-label="Eliminar relación">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </div>
            @endforeach
        </div>
    @else
        <p class="text-sm text-text-muted mb-4">Sin relaciones todavía</p>
    @endif

    <div class="relative mb-3">
        <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-text-muted pointer-events-none"></i>
        <input type="text" wire:model.live.debounce.300ms="search"
            placeholder="Buscar preguntas para relacionar..."
            class="w-full border border-border rounded-lg px-3 py-2 pl-9 text-sm text-text placeholder-text-muted/50 focus:ring-2 focus:ring-primary/30 focus:border-primary outline-none transition-all duration-150 bg-surface">
    </div>

    @if (count($this->searchResults) > 0)
        <div class="space-y-1 mb-4">
            @foreach ($this->searchResults as $result)
                <div class="flex items-center justify-between px-3 py-2 rounded-lg hover:bg-page transition-colors duration-150">
                    <p class="text-sm text-text truncate">{{ $result['question_text'] }}</p>
                    <button wire:click="addRelation('{{ $result['id'] }}')"
                        class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm bg-accent text-white hover:bg-orange-600 transition-colors duration-150 cursor-pointer shrink-0">
                        Conectar
                    </button>
                </div>
            @endforeach
        </div>
    @endif

    <div class="flex flex-wrap gap-2">
        @foreach (['depende de', 'contradice', 'ejemplo de', 'relacionado con'] as $chip)
            <button wire:click="$set('label', '{{ $chip }}')"
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium transition-colors duration-150 cursor-pointer
                @if ($this->label === $chip) bg-primary text-white @else bg-teal-100 text-primary hover:bg-teal-200 @endif">
                {{ $chip }}
            </button>
        @endforeach
    </div>
</div>
