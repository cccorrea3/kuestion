@props(['label' => null, 'error' => null, 'id' => null])

@php $inputId = $id ?? 'input-' . Illuminate\Support\Str::random(8); @endphp

<div class="w-full">
    @if ($label)
        <label for="{{ $inputId }}" class="block text-sm font-medium text-text mb-1">{{ $label }}</label>
    @endif
    <input id="{{ $inputId }}" {{ $attributes->merge(['class' => 'w-full border border-border rounded-lg px-3 py-2 text-sm text-text placeholder-text-muted/50 focus:ring-2 focus:ring-primary/30 focus:border-primary outline-none transition-all duration-150 bg-surface' . ($error ? ' border-danger' : '')]) }} />
    @if ($error)
        <p class="mt-1 text-sm text-danger">{{ $error }}</p>
    @endif
</div>
