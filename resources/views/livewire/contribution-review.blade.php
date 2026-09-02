<div>
    <div class="mb-6">
        <a href="{{ route('questions.index') }}" class="inline-flex items-center gap-1.5 text-sm text-text-muted hover:text-text transition-colors duration-150">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>
            Volver a preguntas
        </a>
    </div>

    <h1 class="text-xl font-bold text-text mb-2">Revisar aporte</h1>

    @if ($status === 'loading')
        <div class="bg-surface rounded-xl shadow-sm border border-border p-10 text-center">
            <svg class="animate-spin w-8 h-8 text-primary mx-auto mb-3" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            <p class="text-sm text-text-muted">Cargando sesión de análisis...</p>
        </div>

    @elseif ($status === 'error')
        <div class="bg-surface rounded-xl shadow-sm border border-border p-6">
            <div class="flex items-start gap-3">
                <i data-lucide="alert-circle" class="w-5 h-5 text-danger shrink-0 mt-0.5"></i>
                <div>
                    <p class="text-sm font-medium text-red-800">No se pudo cargar la sesión</p>
                    <p class="text-sm text-red-700 mt-1">{{ $error }}</p>
                    <a href="{{ route('questions.index') }}" class="inline-flex items-center gap-1.5 mt-3 text-sm font-medium text-primary hover:text-primary/80 transition-colors duration-150">
                        <i data-lucide="arrow-left" class="w-4 h-4"></i>
                        Volver al feed
                    </a>
                </div>
            </div>
        </div>

    @elseif ($status === 'approved')
        <div class="bg-surface rounded-xl shadow-sm border border-border p-6 text-center">
            <i data-lucide="check-circle" class="w-12 h-12 text-success mx-auto mb-3"></i>
            <h2 class="text-lg font-bold text-text mb-2">¡Aporte aprobado!</h2>
            <p class="text-text-muted text-sm mb-6">{{ $resumen }}</p>
            <a href="{{ route('questions.index') }}" class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm bg-accent text-white hover:bg-orange-600 transition-colors duration-150 cursor-pointer">
                Ver preguntas
            </a>
        </div>

    @elseif ($status === 'rejected')
        <div class="bg-surface rounded-xl shadow-sm border border-border p-6 text-center">
            <i data-lucide="x-circle" class="w-12 h-12 text-text-muted mx-auto mb-3"></i>
            <h2 class="text-lg font-bold text-text mb-2">Aporte descartado</h2>
            <p class="text-text-muted text-sm mb-6">Tu aporte fue descartado y no se guardó en la base de conocimiento.</p>
            <a href="{{ route('questions.index') }}" class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm bg-accent text-white hover:bg-orange-600 transition-colors duration-150 cursor-pointer">
                Ver preguntas
            </a>
        </div>

    @elseif ($status === 'loaded' || $status === 'editing' || $status === 'processing')
        {{-- Contexto: pregunta previa --}}
        @if ($preguntaPrevia)
            <div class="bg-page rounded-lg p-3 border border-border mb-4">
                <p class="text-xs text-text-muted mb-1">Tu pregunta original:</p>
                <p class="text-sm text-text font-medium">{{ $preguntaPrevia }}</p>
            </div>
        @endif

        {{-- Resumen de la clasificación --}}
        @if ($resumen)
            <p class="text-sm text-text-muted mb-4">{{ $resumen }}</p>
        @endif

        {{-- Metadata --}}
        <div class="flex items-center gap-3 text-xs text-text-muted mb-5">
            @if ($workspaceNombre)
                <span class="flex items-center gap-1">
                    <i data-lucide="folder" class="w-3.5 h-3.5"></i>
                    {{ $workspaceNombre }}
                </span>
            @endif
            @if ($createdAt)
                <span>{{ \Carbon\Carbon::parse($createdAt)->diffForHumans() }}</span>
            @endif
        </div>

        {{-- Error banner --}}
        @if ($error)
            <div class="flex items-start gap-3 bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
                <i data-lucide="alert-circle" class="w-5 h-5 text-danger shrink-0 mt-0.5"></i>
                <div>
                    <p class="text-sm font-medium text-red-800">Error</p>
                    <p class="text-sm text-red-700 mt-1">{{ $error }}</p>
                </div>
            </div>
        @endif

        {{-- Nodos propuestos --}}
        <div class="space-y-3 mb-6">
            <h2 class="text-sm font-semibold text-text">Contenido propuesto</h2>

            @forelse ($nodes as $index => $node)
                <div class="bg-surface rounded-xl shadow-sm border border-border p-4">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $this->tipoColor($node['tipo']) }}">
                            {{ $this->tipoLabel($node['tipo']) }}
                        </span>
                    </div>

                    @if ($editing)
                        <textarea
                            wire:model="nodes.{{ $index }}.editedText"
                            wire:change="updateNodeText({{ $index }}, $event.target.value)"
                            rows="3"
                            class="w-full border border-border rounded-lg px-3 py-2 text-sm text-text placeholder-text-muted/50 focus:ring-2 focus:ring-primary/30 focus:border-primary outline-none transition-all duration-150 bg-surface resize-none"
                            @if ($status === 'processing') disabled @endif
                        >{{ $node['editedText'] }}</textarea>
                    @else
                        <p class="text-sm text-text leading-relaxed">{{ $node['texto'] }}</p>
                    @endif
                </div>
            @empty
                <div class="bg-surface rounded-xl shadow-sm border border-border p-6 text-center">
                    <p class="text-sm text-text-muted">No se detectaron nodos en esta sesión.</p>
                </div>
            @endforelse
        </div>

        {{-- Botones de acción --}}
        <div class="flex items-center justify-between gap-3 pt-2">
            <div>
                @if ($editing)
                    <button type="button" wire:click="toggleEdit"
                        class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm border border-border text-text hover:bg-page transition-colors duration-150 cursor-pointer">
                        <i data-lucide="x" class="w-4 h-4"></i>
                        Cancelar edición
                    </button>
                @else
                    <button type="button" wire:click="toggleEdit" @if ($status === 'processing') disabled @endif
                        class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm border border-border text-text hover:bg-page transition-colors duration-150 cursor-pointer @if ($status === 'processing') opacity-50 cursor-not-allowed @endif">
                        <i data-lucide="pencil" class="w-4 h-4"></i>
                        Editar texto
                    </button>
                @endif
            </div>

            <div class="flex items-center gap-3">
                <button type="button" wire:click="reject" @if ($status === 'processing') disabled @endif
                    class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm border border-red-300 text-red-700 hover:bg-red-50 transition-colors duration-150 cursor-pointer @if ($status === 'processing') opacity-50 cursor-not-allowed @endif">
                    @if ($status === 'processing')
                        <svg class="animate-spin w-4 h-4" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                        </svg>
                        Procesando...
                    @else
                        <i data-lucide="x" class="w-4 h-4"></i>
                        Descartar
                    @endif
                </button>

                <button type="button" wire:click="approve" @if ($status === 'processing') disabled @endif
                    class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm bg-emerald-600 text-white hover:bg-emerald-700 transition-colors duration-150 cursor-pointer @if ($status === 'processing') opacity-50 cursor-not-allowed @endif">
                    @if ($status === 'processing')
                        <svg class="animate-spin w-4 h-4" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                        </svg>
                        Procesando...
                    @else
                        <i data-lucide="check" class="w-4 h-4"></i>
                        Aprobar
                    @endif
                </button>
            </div>
        </div>

    @endif
</div>
