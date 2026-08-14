<div class="mt-8 w-full max-w-2xl text-left">
    @if (! $hidden)
        <div class="bg-surface rounded-xl shadow-sm border border-border p-5">
            <div class="flex items-center justify-between gap-3 mb-4">
                <h2 class="text-sm font-bold text-text flex items-center gap-2">
                    <i data-lucide="sparkles" class="w-4 h-4 text-primary"></i>
                    Así funciona Kuestion
                </h2>
                <button wire:click="skip" class="text-xs font-medium text-text-muted hover:text-text transition-colors duration-150 cursor-pointer">
                    Omitir
                </button>
            </div>

            @if ($status === 'idle')
                <p class="text-sm text-text-muted mb-3">
                    Kuestion vigila respuestas y te avisa cuando cambian. Con tu primera pregunta real vas a ver algo así:
                </p>
                <p class="text-sm font-semibold text-text mb-2">“{{ $questionText }}”</p>
                <div class="bg-white rounded-lg border border-border p-3 text-sm font-mono leading-relaxed text-text">
                    {{ $oldAnswer }}
                </div>
                <p class="text-xs text-text-muted mt-3">
                    Cuando la respuesta cambia, Kuestion te muestra un diff:
                </p>
                <button wire:click="simulateChange" class="mt-3 inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm bg-accent text-white hover:bg-orange-600 transition-colors duration-150 cursor-pointer">
                    <i data-lucide="git-compare" class="w-4 h-4"></i>
                    Simular cambio
                </button>
            @elseif ($status === 'diff')
                <div class="flex items-center gap-2 mb-3">
                    <x-badge variant="warning">Cambio detectado</x-badge>
                    <span class="text-xs text-text-muted">v1 → v2</span>
                </div>
                <div class="bg-white rounded-lg border border-amber-100 p-3 mb-4 text-sm font-mono leading-relaxed max-h-60 overflow-y-auto">
                    @foreach ($diffLines as $line)
                        @if ($line['type'] === 'unchanged')
                            <div class="text-text-muted">{{ $line['text'] }}</div>
                        @elseif ($line['type'] === 'added')
                            <div class="bg-green-50 text-green-800 px-2 py-0.5 rounded -mx-2">+ {{ $line['text'] }}</div>
                        @elseif ($line['type'] === 'removed')
                            <div class="bg-red-50 text-red-700 px-2 py-0.5 rounded -mx-2">- {{ $line['text'] }}</div>
                        @elseif ($line['type'] === 'changed')
                            <div class="bg-red-50 text-red-700 px-2 py-0.5 rounded -mx-2 line-through">- {{ $line['old'] }}</div>
                            <div class="bg-green-50 text-green-800 px-2 py-0.5 rounded -mx-2">+ {{ $line['new'] }}</div>
                        @endif
                    @endforeach
                </div>
                <p class="text-xs text-text-muted mb-3">
                    Revisás el cambio y decidís si aceptarlo o descartarlo. Acá podés probar ambas opciones:
                </p>
                <div class="flex flex-col sm:flex-row gap-3">
                    <button wire:click="acceptChange" class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg font-medium text-sm bg-teal-600 text-white hover:bg-teal-700 transition-colors duration-150 cursor-pointer">
                        <i data-lucide="check" class="w-4 h-4"></i>
                        Aceptar cambio
                    </button>
                    <button wire:click="dismissChange" class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg font-medium text-sm border border-border text-text hover:bg-page transition-colors duration-150 cursor-pointer">
                        <i data-lucide="x" class="w-4 h-4"></i>
                        Descartar cambio
                    </button>
                </div>
            @elseif ($status === 'accepted')
                <div class="flex items-start gap-3 bg-teal-50 border border-teal-200 rounded-lg p-4">
                    <i data-lucide="check-circle" class="w-5 h-5 text-teal-600 mt-0.5 shrink-0"></i>
                    <div>
                        <p class="text-sm font-medium text-teal-800">¡Así se ve un cambio aceptado!</p>
                        <p class="text-sm text-teal-700 mt-1">La nueva respuesta queda como actual y el aviso desaparece. Con preguntas reales, también podés ver el feedback de utilidad por versión.</p>
                    </div>
                </div>
                <div class="bg-white rounded-lg border border-border p-3 mt-3 text-sm font-mono leading-relaxed text-text">
                    {{ $newAnswer }}
                </div>
            @elseif ($status === 'dismissed')
                <div class="flex items-start gap-3 bg-orange-50 border border-orange-200 rounded-lg p-4">
                    <i data-lucide="x-circle" class="w-5 h-5 text-orange-600 mt-0.5 shrink-0"></i>
                    <div>
                        <p class="text-sm font-medium text-orange-800">Cambio descartado</p>
                        <p class="text-sm text-orange-700 mt-1">Se mantiene la respuesta anterior y el aviso desaparece. Vos decidís siempre qué queda como respuesta actual.</p>
                    </div>
                </div>
                <div class="bg-white rounded-lg border border-border p-3 mt-3 text-sm font-mono leading-relaxed text-text">
                    {{ $oldAnswer }}
                </div>
            @endif
        </div>
    @endif
</div>
