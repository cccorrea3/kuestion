@props([
    'explicacion',        // Salida de ExplicacionNormalizer (A.3): sin_detalle, decision_type, confidence, reasons, alternatives_considered, detected_patterns
    'label' => '¿Por qué?',   // C.2 usa "Ver detalles de la clasificación"
    'open' => false,          // Estado inicial del colapso (interacción CSS pura — sin APIs Livewire)
])

@php
    $presenter = new \App\Services\Explicacion\ExplicacionPresenter;
    $b = $presenter->presentar($explicacion);
    $uid = 'exp-'.uniqid();

    // Semáforo §2.4 — clases ya usadas por la app (hallazgo del plan: sin paleta nueva).
    $color = match ($b['semaforo']) {
        'verde' => ['cls' => 'bg-emerald-100 text-emerald-800', 'dot' => 'bg-emerald-500'],
        'amarillo' => ['cls' => 'bg-amber-100 text-amber-800', 'dot' => 'bg-amber-500'],
        'rojo' => ['cls' => 'bg-red-100 text-red-800', 'dot' => 'bg-red-500'],
        default => null,
    };
@endphp

<div class="mt-2">
    {{-- C.2 — enlace expandible en el mismo lugar (sin navegar) --}}
    <label for="{{ $uid }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:text-primary/80 cursor-pointer select-none">
        {{ $label }}
        <i data-lucide="chevron-down" class="w-4 h-4"></i>
    </label>

    @if ($b['sin_detalle'])
        {{-- A.4/D3 — degradación honesta: nunca inventar explicación --}}
        <input type="checkbox" id="{{ $uid }}" class="peer sr-only">
        <div class="hidden peer-checked:block mt-2 bg-page border border-border rounded-lg p-3">
            <p class="text-sm text-text-muted">Este aporte no tiene detalle de clasificación disponible.</p>
        </div>
    @else
        <input type="checkbox" id="{{ $uid }}" class="peer sr-only" @checked($open)>
        <div class="hidden peer-checked:block mt-2 bg-page border border-border rounded-lg p-3 space-y-2">
            {{-- Frase principal + confianza con semáforo --}}
            <p class="text-sm text-text leading-relaxed">
                {{ $b['frase'] }}
                @if ($b['porcentaje'] !== null && $color)
                    <span class="inline-flex items-center gap-1 ml-1 px-2 py-0.5 rounded-full text-xs font-medium {{ $color['cls'] }}">
                        <span class="w-1.5 h-1.5 rounded-full {{ $color['dot'] }}"></span>
                        Confianza: {{ $b['porcentaje'] }}%
                    </span>
                @endif
            </p>

            {{-- B.2/D5 — advertencia por confianza roja --}}
            @if ($b['advertencia'])
                <p class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-md px-2.5 py-1.5">
                    {{ $b['advertencia'] }}
                </p>
            @endif

            {{-- FB.2 — alternativas descartadas --}}
            @if ($b['alternativas'])
                <ul class="space-y-1">
                    @foreach ($b['alternativas'] as $alt)
                        <li class="text-sm text-text-muted">{{ $alt }}</li>
                    @endforeach
                </ul>
            @endif

            {{-- FB.5 — señales detectadas (solo las que envía QuBeKa) --}}
            @if ($b['patrones'])
                <p class="text-xs text-text-muted">
                    Señales detectadas: {{ implode(', ', $b['patrones']) }}.
                </p>
            @endif
        </div>
    @endif
</div>
