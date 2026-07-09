@props(['variant' => 'default'])

@php
$classes = match ($variant) {
    'success' => 'bg-green-100 text-green-700',
    'warning' => 'bg-orange-100 text-orange-700',
    'danger' => 'bg-red-100 text-red-700',
    'info' => 'bg-teal-100 text-primary',
    'neutral' => 'bg-gray-100 text-text-muted',
    default => 'bg-teal-100 text-primary',
};
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium $classes"]) }}>
    {{ $slot }}
</span>
