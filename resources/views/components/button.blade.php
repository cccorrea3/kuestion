@props(['variant' => 'primary', 'type' => 'button', 'disabled' => false])

@php
$classes = match ($variant) {
    'primary' => 'bg-accent text-white hover:bg-orange-600',
    'secondary' => 'border border-border text-text hover:bg-page',
    'ghost' => 'text-text-muted hover:text-text hover:bg-page',
    default => 'bg-accent text-white hover:bg-orange-600',
};
$disabledClasses = $disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer';
@endphp

<button type="{{ $type }}" {{ $disabled ? 'disabled' : '' }} {{ $attributes->merge(['class' => "inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm transition-colors duration-150 $classes $disabledClasses"]) }}>
    {{ $slot }}
</button>
