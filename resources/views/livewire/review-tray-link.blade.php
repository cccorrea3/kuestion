<a
    href="{{ route('reviews.index') }}"
    wire:navigate
    wire:poll.120s="refreshCount"
    class="inline-flex items-center gap-1 text-sm text-text-muted hover:text-text transition-colors duration-150"
    title="Aportes pendientes de confirmar"
>
    <i data-lucide="clipboard-list" class="w-4 h-4"></i>
    <span class="hidden sm:inline">Revisar</span>
    @if ($pendingCount !== null && $pendingCount > 0)
        <span class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-amber-500 text-white text-[10px] font-bold leading-none ring-2 ring-surface">
            {{ $pendingCount > 99 ? '99+' : $pendingCount }}
        </span>
    @endif
</a>