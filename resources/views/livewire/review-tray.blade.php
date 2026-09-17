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
                <div class="rounded-xl border border-border bg-surface p-4 shadow-sm" wire:key="tray-{{ $sessionId }}">
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

                        {{-- Ola 2 Punto 4 — D.2/FD.3: "¿Por qué?" por ítem, bajo demanda
                             (los metadatos viven en el detalle de la sesión). Fallo visible con reintento. --}}
                        <div class="mt-2">
                            @if ($detalleCargandoId === $sessionId)
                                <p class="text-sm text-text-muted">Cargando detalle...</p>
                            @elseif (isset($explicaciones[$sessionId]))
                                <x-classification-explanation :explicacion="$explicaciones[$sessionId]" />
                            @elseif ($detalleErrores[$sessionId] ?? null)
                                <div class="bg-red-50 border border-red-200 rounded-lg p-3">
                                    <p class="text-sm text-red-800">{{ $detalleErrores[$sessionId] }}</p>
                                    <button type="button" wire:click="cargarExplicacion({{ $sessionId }})"
                                        class="mt-1.5 text-xs font-medium text-primary hover:underline cursor-pointer">
                                        Reintentar
                                    </button>
                                </div>
                            @else
                                <button type="button" wire:click="cargarExplicacion({{ $sessionId }})"
                                    @disabled($isProcessing)
                                    class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:text-primary/80 cursor-pointer">
                                    ¿Por qué?
                                    <i data-lucide="chevron-down" class="w-4 h-4"></i>
                                </button>
                            @endif
                        </div>
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
                    @endif

                    {{-- Ola 3, Punto 1 — C.1/C.2/C.5: documento expandido (revisión por lote).
                         Panel independiente del editor: aparece para la sesión expandida. --}}
                    @if ($docExpandido && $docSessionId === $sessionId)
                        <div class="mt-4 space-y-3 rounded-lg border border-primary/20 bg-primary/5 p-3" wire:key="doc-{{ $sessionId }}">
                            @if ($docError)
                                {{-- FC-6: fallo visible; la selección no se pierde y se puede reintentar. --}}
                                <div class="rounded-lg border border-danger/30 bg-danger/5 p-3 text-sm">
                                    <p class="font-medium text-danger">No se pudo completar la operación</p>
                                    <p class="text-text-muted">{{ $docError }}</p>
                                    <button type="button" wire:click="aprobarSeleccionados"
                                        @disabled($isProcessing)
                                        class="mt-1.5 text-xs font-medium text-primary hover:underline cursor-pointer">
                                        Reintentar
                                    </button>
                                </div>
                            @endif

                            {{-- Ola 3, Punto 1.1 — D.1/D.2: advertencia de consecuencias (informativa,
                                 no bloquea — spec §1.5). La descripcion viene lista de QuBeKa (no se reescribe);
                                 los nodos afectados se citan por texto, igual que las contradicciones. --}}
                            @if ($advertenciaPendiente)
                                <div class="rounded-lg border border-amber-300 bg-amber-50 p-3" wire:key="adv-{{ $sessionId }}">
                                    <p class="text-sm font-medium text-amber-800">Revisa las consecuencias de esta selección</p>
                                    <p class="mt-0.5 text-xs text-amber-700">Estas consecuencias no bloquean la aprobación: son información para que decidas.</p>
                                    <div class="mt-2 space-y-2">
                                        @foreach ($advertenciaConsecuencias as $c)
                                            <div class="rounded-md bg-white/70 p-2">
                                                <p class="text-xs font-semibold text-amber-900">{{ \App\Livewire\ReviewTray::encabezadoConsecuencia($c['tipo'] ?? '') }}</p>
                                                @if (! empty($c['descripcion']))
                                                    <p class="mt-0.5 text-xs text-amber-900">{{ $c['descripcion'] }}</p>
                                                @endif
                                                @if (! empty($c['nodos_afectados']))
                                                    <p class="mt-1 text-xs text-amber-800">Nodos afectados: @foreach ($c['nodos_afectados'] as $i => $n){{ $i > 0 ? ' · ' : '' }}«{{ $n['texto'] }}»@endforeach</p>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="mt-3 flex items-center gap-2">
                                        <button
                                            wire:click="confirmarAprobacionConAdvertencia"
                                            @disabled($isProcessing)
                                            class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-sm text-white shadow-sm hover:bg-emerald-700 transition-colors duration-150 disabled:opacity-50"
                                        >
                                            <i data-lucide="check" class="w-4 h-4"></i>
                                            Confirmar aprobación
                                        </button>
                                        <button
                                            wire:click="volverASeleccion"
                                            @disabled($isProcessing)
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-surface px-3 py-1.5 text-sm text-text hover:bg-page transition-colors duration-150"
                                        >
                                            Volver a la selección
                                        </button>
                                    </div>
                                </div>
                            @endif

                            {{-- Ola 3, Punto 1.1 — D.3 (P2): fallo de la evaluación, distinto del aviso
                                 de consecuencias. No bloquea: aprobar de todas formas o reintentar. --}}
                            @if ($evaluacionFallida)
                                <div class="rounded-lg border border-amber-300 bg-amber-50 p-3" wire:key="adve-{{ $sessionId }}">
                                    <p class="text-sm font-medium text-amber-800">No pudimos verificar las consecuencias de esta selección</p>
                                    <p class="mt-0.5 text-xs text-amber-700">Podés aprobar de todas formas o intentar la verificación de nuevo.</p>
                                    <div class="mt-3 flex items-center gap-2">
                                        <button
                                            wire:click="confirmarAprobacionConAdvertencia"
                                            @disabled($isProcessing)
                                            class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-sm text-white shadow-sm hover:bg-emerald-700 transition-colors duration-150 disabled:opacity-50"
                                        >
                                            <i data-lucide="check" class="w-4 h-4"></i>
                                            Aprobar de todas formas
                                        </button>
                                        <button
                                            wire:click="reintentarEvaluacion"
                                            @disabled($isProcessing)
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-surface px-3 py-1.5 text-sm text-text hover:bg-page transition-colors duration-150"
                                        >
                                            <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                                            Reintentar verificación
                                        </button>
                                    </div>
                                </div>
                            @endif

                            @if ($docContradicciones !== null && $docContradicciones !== [])
                                {{-- C.5/FC-2: advertencia destacada (no estilo error), no bloquea (§1.8). --}}
                                <div class="rounded-lg border border-amber-300 bg-amber-50 p-3">
                                    <p class="text-sm font-medium text-amber-800">
                                        Este documento contradice {{ count($docContradicciones) }}
                                        {{ count($docContradicciones) === 1 ? 'nodo existente' : 'nodos existentes' }}.
                                    </p>
                                    <div class="mt-2 space-y-2">
                                        @foreach ($docContradicciones as $c)
                                            <div class="rounded-md bg-white/70 p-2 text-xs text-amber-900">
                                                <p><span class="font-medium">Propuesto:</span> {{ $c['nodo_nuevo_propuesto'] ?? '—' }}</p>
                                                <p><span class="font-medium">Existente:</span> {{ $c['nodo_existente'] ?? '—' }}</p>
                                                <p><span class="font-medium">Contradicción:</span> {{ $c['descripcion'] ?? '—' }}</p>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            <div class="flex items-center gap-3">
                                <p class="text-xs font-medium text-text uppercase tracking-wide">
                                    Nodos propuestos ({{ $this->cantidadSeleccionados }} de {{ count($docNodos) }} seleccionados)
                                </p>
                                <button type="button" wire:click="seleccionarTodos(true)" class="text-xs font-medium text-primary hover:underline cursor-pointer">Todos</button>
                                <button type="button" wire:click="seleccionarTodos(false)" class="text-xs font-medium text-primary hover:underline cursor-pointer">Ninguno</button>
                            </div>

                            {{-- C.2/FC-3: nodos agrupados por tipo con confianza (explicabilidad Ola 2 P4 reutilizada). --}}
                            @php
                                $grupos = collect($docNodos)->groupBy(fn ($n) => $n['tipo']);
                            @endphp
                            @foreach ($grupos as $tipo => $nodosDelTipo)
                                <div>
                                    <p class="mb-1 text-xs font-semibold text-text-muted">{{ $tipo }} ({{ count($nodosDelTipo) }})</p>
                                    @foreach ($nodosDelTipo as $nodo)
                                        @php
                                            $iGlobal = array_search($nodo, $docNodos, true);
                                        @endphp
                                        <label class="flex items-start gap-2 py-1.5 cursor-pointer">
                                            <input type="checkbox"
                                                wire:click="alternarNodo({{ $iGlobal }})"
                                                {{ $nodo['seleccionado'] ? 'checked' : '' }}
                                                @disabled($isProcessing)
                                                class="mt-0.5 h-4 w-4 rounded border-border text-primary focus:ring-primary/30 cursor-pointer">
                                            <span class="flex-1">
                                                <span class="text-sm text-text leading-relaxed">{{ $nodo['texto'] }}</span>
                                                @if (! empty($nodo['explicacion']))
                                                    <span class="block mt-0.5">
                                                        <x-classification-explanation :explicacion="$nodo['explicacion']" label="¿Por qué?" />
                                                    </span>
                                                @endif
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            @endforeach

                            {{-- D.1: mientras el panel de advertencia está visible, reemplaza
                                 el bloque de acciones de aprobación (los botones de confirmación
                                 viven dentro del panel). --}}
                            @if (! $advertenciaPendiente && ! $evaluacionFallida)
                            <div class="flex items-center gap-2 pt-1">
                                <button
                                    wire:click="aprobarSeleccionados"
                                    @disabled($isProcessing || $this->cantidadSeleccionados === 0)
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-sm text-white shadow-sm hover:bg-emerald-700 transition-colors duration-150 disabled:opacity-50"
                                >
                                    <i data-lucide="check" class="w-4 h-4"></i>
                                    Aprobar seleccionados ({{ $this->cantidadSeleccionados }})
                                </button>
                                <button
                                    wire:click="rechazarDocumento"
                                    @disabled($isProcessing)
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-danger/30 px-3 py-1.5 text-sm text-danger hover:bg-danger/5 transition-colors duration-150"
                                >
                                    <i data-lucide="x" class="w-4 h-4"></i>
                                    Rechazar todo
                                </button>
                                <button
                                    wire:click="colapsarDocumento"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-surface px-3 py-1.5 text-sm text-text hover:bg-page transition-colors duration-150"
                                >
                                    Cerrar
                                </button>
                            </div>
                            @endif
                        </div>
                    @endif

                    @if (! ($isEditingThis || ($docExpandido && $docSessionId === $sessionId)))
                        <div class="mt-4 flex items-center gap-2">
                            @if ($estado === 'pendientes')
                                @if (! ($item['is_simple'] ?? false))
                                    {{-- Ola 3, Punto 1 — C.1/C.2: sesión multi-nodo (documento) →
                                         revisión por lote expandida en la bandeja. --}}
                                    <button
                                        wire:click="expandirDocumento({{ $sessionId }})"
                                        @disabled($isProcessing || $docCargandoId !== null)
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-surface px-3 py-1.5 text-sm text-text hover:bg-page transition-colors duration-150"
                                    >
                                        <i data-lucide="file-text" class="w-4 h-4"></i>
                                        Revisar documento
                                    </button>
                                @else
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
                                @endif
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
