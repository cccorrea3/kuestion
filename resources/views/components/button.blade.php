@props(['variant' => 'primary', 'type' => 'button', 'disabled' => false, 'href' => null])

@php
$classes = match ($variant) {
    'primary' => 'bg-accent text-white hover:bg-orange-600',
    'secondary' => 'border border-border text-text hover:bg-page',
    'ghost' => 'text-text-muted hover:text-text hover:bg-page',
    default => 'bg-accent text-white hover:bg-orange-600',
};
$disabledClasses = $disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer';
$attrs = $attributes->merge(['class' => "inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm transition-colors duration-150 $classes $disabledClasses"]);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attrs }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $disabled ? 'disabled' : '' }} {{ $attrs }}>{{ $slot }}</button>
@endif
