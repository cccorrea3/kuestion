<button wire:poll.60s="refreshCount" wire:click="markReadAndGo"
   class="relative inline-flex items-center justify-center w-9 h-9 rounded-lg text-text-muted hover:text-text hover:bg-page transition-colors duration-150 cursor-pointer"
   title="Notificaciones">
    <i data-lucide="bell" class="w-5 h-5"></i>
    @if ($count > 0)
        <span class="absolute -top-0.5 -right-0.5 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-bold bg-danger text-white leading-none">
            {{ $count > 99 ? '99+' : $count }}
        </span>
    @endif
</button>
