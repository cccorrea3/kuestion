<div>
@if ($count > 0)
    <a href="{{ $latestSessionId ? route('contributions.review', ['sessionId' => $latestSessionId]) : '#' }}"
        wire:poll.60s="refreshCount"
        class="relative inline-flex items-center gap-1 text-sm text-amber-700 hover:text-amber-900 transition-colors duration-150"
        title="{{ $count }} aporte{{ $count !== 1 ? 's' : '' }} pendiente{{ $count !== 1 ? 's' : '' }} de confirmar">
        <i data-lucide="clock" class="w-4 h-4"></i>
        <span class="hidden sm:inline">Pendientes</span>
        <span class="absolute -top-1.5 -right-2.5 flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-amber-500 text-white text-[10px] font-bold leading-none ring-2 ring-surface">
            {{ $count > 99 ? '99+' : $count }}
        </span>
    </a>
@endif
</div>
