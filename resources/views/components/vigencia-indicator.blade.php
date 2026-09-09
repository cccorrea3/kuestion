@props([
    'estado',              // 'no_aplica' | 'sin_dato' | 'confirmada' | 'vencida' | 'posiblemente_obsoleto'
    'dias' => null,
    'ultimaConfirmacion' => null,
    'confirmador' => null, // Valor VARIABLE (contrato §5.3, D-Confirmador): nombre real del usuario de QuBeKa o "Kuestion (conector)". No condicionar lógica al string.
    'actionUrl' => null,   // Enlace del caso Kuaforia rojo ("Revisar en Kuaforia").
    'actionMethod' => null,   // Método Livewire del botón Reconfirmar (QBK vencida/sin_dato — B.4).
    'actionParams' => [],     // Parámetros del método (feed/bandeja pasan el id de pregunta).
    'actionTarget' => null,   // wire:target para deshabilitar solo este botón (FC.4: sin doble envío).
    'compact' => false,    // Mini-indicador del feed (D.1): estado + fecha, sin botón ni tooltip.
])

@php
    // Fase A — mapa de estados del plan (vigente/pendiente_reconfirmacion/
    // posiblemente_obsoleto/sin_dato) a los calculados y a los colores semánticos
    // ya usados en la app (hallazgo 5 del plan: sin paleta nueva).
    // Fase A.2 — regla Kuaforia (bloqueante B2): hoy no existe señal de vigencia
    // por respuesta (solo tools de workspace en notificaciones), por lo que una
    // pregunta Kuaforia cae en 'no_aplica' y no renderiza nada. 'posiblemente_obsoleto'
    // queda mapeado para cuando B2 cierre; el botón será el enlace a la UI de Kuaforia.
    $relativo = ($dias === 0) ? 'hoy' : 'hace '.$dias.' '.str('día')->plural($dias);

    $visual = match ($estado) {
        'confirmada' => [
            'icon' => 'check-circle-2',
            'text' => 'Vigente',
            'fecha' => 'Última confirmación: '.$relativo,
            'compact' => 'Última confirmación: '.$relativo,
            'cls' => 'bg-teal-50 text-teal-800 border-teal-200',
            'dot' => 'bg-teal-600',
        ],
        'vencida' => [
            'icon' => 'alert-triangle',
            'text' => 'Pendiente de reconfirmación',
            'fecha' => 'Última confirmación: '.$relativo,
            'compact' => 'Pendiente de reconfirmación · '.$relativo,
            'cls' => 'bg-amber-50 text-amber-800 border-amber-200',
            'dot' => 'bg-amber-500',
        ],
        'sin_dato' => [
            'icon' => 'clock',
            'text' => 'Pendiente de reconfirmación',
            // B.2 — absorbe el copy honesto de Ola 1 P5/6 (minúscula: AssertionExacta de QuestionVigenciaCopyTest).
            'fecha' => 'sin reconfirmaciones registradas',
            'compact' => 'sin reconfirmaciones registradas',
            'cls' => 'bg-amber-50 text-amber-800 border-amber-200',
            'dot' => 'bg-amber-500',
        ],
        'posiblemente_obsoleto' => [
            'icon' => 'alert-octagon',
            'text' => 'Posiblemente obsoleto',
            'fecha' => null,
            'compact' => 'Posiblemente obsoleto',
            'cls' => 'bg-red-50 text-red-800 border-red-200',
            'dot' => 'bg-danger',
        ],
        default => null, // 'no_aplica' (Kuaforia hoy, regla A.2) — no renderiza nada.
    };
@endphp

@if ($visual)
    <span class="relative inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium {{ $visual['cls'] }}">
        <span class="w-1.5 h-1.5 rounded-full {{ $visual['dot'] }}"></span>
        <i data-lucide="{{ $visual['icon'] }}" class="w-3.5 h-3.5"></i>

        @if ($compact)
            {{-- D.1 — mini-indicador del feed: solo estado + fecha, sin botón (§2.2: la acción se abre en el detalle). --}}
            <span>{{ $visual['compact'] }}</span>
        @else
            <span>{{ $visual['text'] }}</span>
            @if ($visual['fecha'])
                <span class="font-normal opacity-80">· {{ $visual['fecha'] }}</span>
            @endif

            {{-- B.3 — tooltip de trazabilidad (fecha exacta + quién reconfirmó).
                 El confirmador se renderiza TAL CUAL viene del contrato: variable
                 (D-Confirmador), sin asumir un literal ni condicionar lógica al string. --}}
            @if ($ultimaConfirmacion)
                <span class="relative group cursor-help">
                    <i data-lucide="info" class="w-3.5 h-3.5"></i>
                    <span class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 w-56 px-3 py-2 text-xs text-left text-text bg-surface border border-border rounded-lg shadow-lg opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-150 z-10 whitespace-normal">
                        Última confirmación: {{ $ultimaConfirmacion->isoFormat('D [de] MMMM [de] YYYY') }}@if ($confirmador) — por {{ $confirmador }}@endif
                    </span>
                </span>
            @endif

            {{-- B.4 — acción por estado/fuente: QBK (vencida/sin_dato) → Reconfirmar (C.1);
                 Kuaforia rojo → enlace a su UI (decisión abierta del origen, asumida "solo enlace");
                 vigente → sin acción (D3: sin fricción). El guard por estado acá es la
                 raíz: un caller puede pasar action-method pero 'confirmada' nunca muestra botón. --}}
            @if (in_array($estado, ['vencida', 'sin_dato'], true) && $actionMethod)
                <button
                    wire:click="{{ $actionMethod }}({{ collect($actionParams)->map(fn ($p) => "'".addslashes($p)."'")->implode(', ') }})"
                    @if ($actionTarget) wire:loading.attr="disabled" wire:target="{{ $actionTarget }}" @endif
                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-white/70 text-amber-700 hover:bg-amber-100 transition-colors duration-150 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                    title="Reconfirmar que esta información sigue siendo válida">
                    Reconfirmar
                </button>
            @elseif ($estado === 'posiblemente_obsoleto' && $actionUrl)
                <a href="{{ $actionUrl }}"
                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-white/70 text-red-700 hover:bg-red-100 transition-colors duration-150">
                    Revisar en Kuaforia
                </a>
            @endif
        @endif
    </span>
@endif
