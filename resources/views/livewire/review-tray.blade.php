<div class="space-y-6" wire:poll.60s="refresh">
    {{-- Encabezado + pestañas --}}
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-xl font-semibold text-text">Revisar</h1>
        <div class="flex items-center gap-2 rounded-lg border border-border bg-surface p-1 shadow-sm">
            <button
                wire:click="switchEstado('pendientes')"
                class="px-3 py-1.5 text-sm transition-colors duration-150 rounded-md
                    {{ $estado === 'pendientes' ? 'bg-primary/10 text-primary ring-1 ring-primary/20' : 'text-text-muted hover:text-text hover:bg-page/50' }}"
            >
                Pendientes
            </button>
            <button
                wire:click="switchEstado('historial')"
                class="px-3 py-1.5 text-sm transition-colors duration-150 rounded-md
                    {{ $estado === 'historial' ? 'bg-primary/10 text-primary ring-1 ring-primary/20' : 'text-text-muted hover:text-text hover:bg-page/50' }}"
            >
                Historial
            </button>
            {{-- Ola 2 Punto 2 — D.1: pestaña de vencidos. --}}
            <button
                wire:click="switchEstado('reconfirmar')"
                class="px-3 py-1.5 text-sm transition-colors duration-150 rounded-md
                    {{ $estado === 'reconfirmar' ? 'bg-primary/10 text-primary ring-1 ring-primary/20' : 'text-text-muted hover:text-text hover:bg-page/50' }}"
            >
                Pendientes de reconfirmar
            </button>
        </div>
    </div>

    {{-- Estado de carga --}}
    @if ($status === 'loading')
        <div class="flex items-center justify-center rounded-xl border border-border bg-surface py-16">
            <svg class="h-5 w-5 animate-spin text-primary" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <span class="ml-3 text-sm text-text-muted">Cargando aportes pendientes…</span>
        </div>
    @endif

    {{-- Error de conexión --}}
    @if ($status === 'error' && $error !== null)
        <div class="flex flex-col gap-3 rounded-xl border border-danger/30 bg-danger/5 p-4 text-sm">
            <div class="flex items-start gap-3">
                <i data-lucide="alert-triangle" class="mt-0.5 w-5 h-5 text-danger flex-shrink-0"></i>
                <div class="flex-1">
                    <p class="font-medium text-danger">No se pudo cargar la bandeja</p>
                    <p class="text-text-muted">{{ $error }}</p>
                </div>
            </div>
            <button
                wire:click="loadPage"
                class="inline-flex items-center gap-2 rounded-lg border border-border bg-surface px-4 py-2 text-sm text-text hover:bg-page transition-colors duration-150"
            >
                <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                Reintentar
            </button>
        </div>
    @endif

    {{-- Lista (pendientes o historial según pestaña) --}}
    @if ($status === 'loaded' && count($items) > 0)
        <div class="space-y-3">
            @foreach ($items as $item)
                @php
                    $sessionId = (int) ($item['session_id'] ?? 0);
                    $createdLabel = match (true) {
                        ! empty($item['fecha_creacion']) => \Carbon\Carbon::parse($item['fecha_creacion'])->diffForHumans(),
                        ! empty($item['fecha_decision']) => \Carbon\Carbon::parse($item['fecha_decision'])->diffForHumans(),
                        default => 'Hace poco',
                    };
                    $isProcessing = $processingSessionId === $sessionId;
                    $isEditingThis = $editing && $editingSessionId === $sessionId;
                @endphp
                <div class="rounded-xl border border-border bg-surface p-4 shadow-sm">
                    <div class="mb-3 flex flex-col gap-1.5">
                        <div class="flex items-start justify-between gap-3 text-xs text-text-muted uppercase tracking-wide">
                            <span>
                                {{ $createdLabel }} · {{ \App\Livewire\ReviewTray::estadoLegible($item['status'] ?? '') }}
                                @if (! empty($item['autor_nombre']))
                                    · Aportado por {{ $item['autor_nombre'] }}
                                @endif
                            </span>
                            @if ($estado === 'historial')
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ ($item['status'] ?? '') === 'rechazada' ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700' }}">
                                    {{ \App\Livewire\ReviewTray::estadoLegible($item['status'] ?? '') }}
                                </span>
                            @endif
                        </div>
                        <p class="text-sm text-text leading-relaxed">{{ $item['texto_original_del_aporte'] ?? 'Sin texto original disponible' }}</p>
                        @if (! empty($item['resumen_clasificacion']))
                            <p class="text-sm text-text-muted mt-1">{{ $item['resumen_clasificacion'] }}</p>
                        @endif
                    </div>

                    {{-- Editor inline (C.3): solo para la sesión simple en edición --}}
                    @if ($isEditingThis)
                        <div class="space-y-3 rounded-lg border border-primary/20 bg-primary/5 p-3">
                            <p class="text-xs font-medium text-text uppercase tracking-wide">Ajustar texto propuesto</p>
                            @forelse ($editingNodes as $index => $node)
                                <div>
                                    <p class="mb-1 text-xs text-text-muted">{{ $node['tipo'] }}</p>
                                    <textarea
                                        wire:model.lazy="editingNodes.{{ $index }}.editedText"
                                        rows="3"
                                        @disabled($isProcessing)
                                        class="w-full border border-border rounded-lg px-3 py-2 text-sm text-text bg-surface focus:ring-2 focus:ring-primary/30 focus:border-primary outline-none resize-none"
                                    ></textarea>
                                </div>
                            @empty
                                <p class="text-sm text-text-muted">No se detectaron nodos editables en esta sesión.</p>
                            @endforelse
                            <div class="flex items-center gap-2">
                                <button
                                    wire:click="guardarAjustes"
                                    @disabled($isProcessing)
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-sm text-white shadow-sm hover:bg-emerald-700 transition-colors duration-150"
                                >
                                    <i data-lucide="check" class="w-4 h-4"></i>
                                    Guardar y aprobar
                                </button>
                                <button
                                    wire:click="cancelEdit"
                                    @disabled($isProcessing)
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-surface px-3 py-1.5 text-sm text-text hover:bg-page transition-colors duration-150"
                                >
                                    Cancelar
                                </button>
                            </div>
                        </div>
                    @else
                        <div class="mt-4 flex items-center gap-2">
                            @if ($estado === 'pendientes')
                                <button
                                    wire:click="toggleReview({{ $sessionId }})"
                                    @disabled($isProcessing)
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-surface px-3 py-1.5 text-sm text-text hover:bg-page transition-colors duration-150"
                                >
                                    <i data-lucide="eye" class="w-4 h-4"></i>
                                    Revisar
                                </button>
                                <button
                                    wire:click="edit({{ $sessionId }})"
                                    @disabled($isProcessing)
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-surface px-3 py-1.5 text-sm text-text hover:bg-page transition-colors duration-150"
                                >
                                    <i data-lucide="pencil" class="w-4 h-4"></i>
                                    Ajustar
                                </button>
                                <button
                                    wire:click="aprobar({{ $sessionId }})"
                                    @disabled($isProcessing)
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-sm text-white shadow-sm hover:bg-emerald-700 transition-colors duration-150"
                                >
                                    <i data-lucide="check" class="w-4 h-4"></i>
                                    Aprobar
                                </button>
                                <button
                                    wire:click="rechazar({{ $sessionId }})"
                                    @disabled($isProcessing)
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-danger/30 px-3 py-1.5 text-sm text-danger hover:bg-danger/5 transition-colors duration-150"
                                >
                                    <i data-lucide="x" class="w-4 h-4"></i>
                                    Rechazar
                                </button>
                            @else
                                <button
                                    wire:click="toggleReview({{ $sessionId }})"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-surface px-3 py-1.5 text-sm text-text hover:bg-page transition-colors duration-150"
                                >
                                    <i data-lucide="eye" class="w-4 h-4"></i>
                                    Ver detalle
                                </button>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach

            {{-- Paginación --}}
            @if ($total > $perPage)
                <div class="flex items-center justify-center gap-2 pt-2">
                    @php
                        $lastPage = max(1, (int) ceil($total / $perPage));
                    @endphp
                    @for ($p = 1; $p <= $lastPage; $p++)
                        <button
                            wire:click="goToPage({{ $p }})"
                            class="rounded-lg border {{ $p === $page ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-surface text-text-muted hover:text-text' }} px-3 py-1.5 text-sm transition-colors duration-150"
                        >
                            {{ $p }}
                        </button>
                    @endfor
                </div>
            @endif
        </div>
    @endif

    {{-- Ola 2 Punto 2 — D.1: pestaña "Pendientes de reconfirmar" (lista local, sin QuBeKa). --}}
    @if ($estado === 'reconfirmar' && $status === 'loaded')
        <div class="space-y-3">
            @forelse ($this->vencidas as $q)
                <div class="rounded-xl border border-border bg-surface p-4 shadow-sm" wire:key="reconf-{{ $q['id'] }}">
                    <p class="text-sm font-medium text-text">{{ $q['texto'] }}</p>
                    {{-- Ola 2 Punto 3 — D.2/FD.2: indicador completo con botón (vencida y
                         sin_dato, FB.4). Confirmador variable renderizado tal cual (B.3). --}}
                    <div class="mt-2">
                        <x-vigencia-indicator
                            :estado="$q['estado']"
                            :dias="$q['dias']"
                            :ultima-confirmacion="$q['ultima_confirmacion']"
                            :confirmador="$q['confirmador']"
                            action-method="reconfirmarPregunta"
                            :action-params="[$q['id']]"
                            :action-target="'reconfirmarPregunta(\''.$q['id'].'\')'"
                        />
                    </div>
                    <span class="ml-2 text-xs text-amber-700 hidden" wire:loading.class="!inline" wire:target="reconfirmarPregunta('{{ $q['id'] }}')">Confirmando…</span>
                </div>
            @empty
                <div class="flex flex-col gap-2 rounded-xl border border-border bg-surface p-6 text-center">
                    <i data-lucide="check-circle-2" class="w-5 h-5 text-emerald-500 mx-auto"></i>
                    <p class="text-sm text-text">Nada pendiente de reconfirmar.</p>
                    <p class="text-xs text-text-muted">Las respuestas QBK con más de {{ config('kuestion.reconfirmacion.umbral_dias', 90) }} días sin reconfirmar aparecerán aquí.</p>
                </div>
            @endforelse
        </div>
    @endif

    {{-- Estados vacíos --}}
    @if ($status === 'loaded' && count($items) === 0 && $estado === 'pendientes')
        <div class="flex flex-col gap-3 rounded-xl border border-border bg-surface p-6 text-center">
            <i data-lucide="check-circle-2" class="w-5 h-5 text-emerald-500 mx-auto"></i>
            <p class="text-sm text-text">No hay aportes pendientes de confirmar.</p>
            <p class="text-xs text-text-muted">Cuando alguien aporte conocimiento al workspace, aparecerá aquí para que lo revises.</p>
        </div>
    @endif

    @if ($status === 'loaded' && count($items) === 0 && $estado === 'historial')
        <div class="flex flex-col gap-3 rounded-xl border border-border bg-surface p-6 text-center">
            <i data-lucide="archive" class="w-5 h-5 text-text-muted mx-auto"></i>
            <p class="text-sm text-text">No hay elementos en el historial.</p>
            <p class="text-xs text-text-muted">Los aportes confirmados aparecerán aquí.</p>
        </div>
    @endif

    {{-- Toast de error acción --}}
    @if ($error !== null)
        <div class="fixed bottom-4 right-4 z-50 max-w-sm rounded-lg border border-danger/30 bg-danger/10 p-3 shadow-lg text-sm text-danger">
            <div class="flex items-start gap-2">
                <i data-lucide="alert-circle" class="mt-0.5 w-4 h-4 flex-shrink-0"></i>
                <span>{{ $error }}</span>
            </div>
        </div>
    @endif
</div>