@props(['class' => null])

@php
    $ficha = config('kuestion.connectors.kuaforia');
    $hint = $ficha['auth_fields'][0]['hint'] ?? null;
    $helpUrl = $ficha['help_url'] ?? null;
@endphp

@if ($hint || $helpUrl)
    <p {{ $attributes->merge(['class' => 'text-xs text-text-muted '.$class]) }}>
        @if ($hint)
            {{ $hint }}
        @endif
        @if ($helpUrl)
            <a href="{{ $helpUrl }}" target="_blank" rel="noopener"
                class="text-accent hover:text-orange-600 font-medium transition-colors duration-150">
                ¿Cómo la obtengo?
            </a>
        @endif
    </p>
@endif
