<div>
    <h2 class="text-sm font-bold text-text mb-3">¿Te fue útil esta respuesta?</h2>
    <div class="flex items-center gap-3">
        <button wire:click="setFeedback('helpful')" wire:loading.attr="disabled"
            class="feedback-btn inline-flex items-center gap-2 px-4 py-2 rounded-lg font-medium text-sm transition-all duration-150 cursor-pointer
            @if ($feedback === 'helpful') bg-green-100 text-green-700 ring-2 ring-green-300 @else border border-border text-text-muted hover:text-text hover:bg-page @endif">
            <i data-lucide="thumbs-up" class="w-4 h-4"></i>
            Útil
        </button>
        <button wire:click="setFeedback('not_helpful')" wire:loading.attr="disabled"
            class="feedback-btn inline-flex items-center gap-2 px-4 py-2 rounded-lg font-medium text-sm transition-all duration-150 cursor-pointer
            @if ($feedback === 'not_helpful') bg-red-100 text-red-700 ring-2 ring-red-300 @else border border-border text-text-muted hover:text-text hover:bg-page @endif">
            <i data-lucide="thumbs-down" class="w-4 h-4"></i>
            No útil
        </button>
    </div>
</div>
