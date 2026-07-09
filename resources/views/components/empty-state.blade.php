@props(['icon' => 'search', 'action' => null])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center py-16 text-center']) }}>
    <i data-lucide="{{ $icon }}" class="w-16 h-16 text-border mb-4"></i>
    <p class="text-text-muted text-sm max-w-md">{{ $slot }}</p>
    @if ($action)
        <div class="mt-4">
            {{ $action }}
        </div>
    @endif
</div>
