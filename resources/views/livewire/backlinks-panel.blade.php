<div>
    <button wire:click="$toggle('expanded')"
        class="flex items-center justify-between w-full cursor-pointer">
        <div class="flex items-center gap-2">
            <i data-lucide="corner-up-left" class="w-5 h-5 text-text-muted"></i>
            <h3 class="text-sm font-semibold text-text">Backlinks</h3>
            <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-teal-100 text-primary text-xs font-medium">{{ $this->backlinks->count() }}</span>
        </div>
        <i data-lucide="{{ $this->expanded ? 'chevron-up' : 'chevron-down' }}" class="w-4 h-4 text-text-muted transition-transform duration-150"></i>
    </button>

    @if ($this->expanded)
        <div class="mt-4 space-y-2">
            @forelse ($this->backlinks as $backlink)
                <div class="flex items-center justify-between p-3 rounded-lg bg-page border border-border">
                    <div class="flex-1 min-w-0">
                        <a href="{{ route('questions.show', $backlink->source_question_id) }}" wire:navigate
                            class="inline-flex items-center gap-1.5 text-sm text-text-muted hover:text-text transition-colors duration-150">
                            <span class="truncate">{{ $backlink->source->question_text }}</span>
                        </a>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="text-xs text-text-muted">{{ $backlink->label }}</span>
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-text-muted">Ninguna otra pregunta apunta a esta</p>
            @endforelse
        </div>
    @endif
</div>
