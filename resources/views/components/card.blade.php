@props(['padding' => true])

<div {{ $attributes->merge(['class' => 'bg-surface rounded-xl shadow-sm border border-border' . ($padding ? ' p-5' : '')]) }}>
    {{ $slot }}
</div>
