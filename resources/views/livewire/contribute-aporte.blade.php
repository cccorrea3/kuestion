<div>
    <div class="mb-6">
        <a href="{{ route('questions.index') }}" class="inline-flex items-center gap-1.5 text-sm text-text-muted hover:text-text transition-colors duration-150">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>
            Volver a preguntas
        </a>
    </div>

    <h1 class="text-xl font-bold text-text mb-6">Aportar conocimiento</h1>

    @if ($status === 'saved')
        {{-- Estado: saved — confirmación liviana --}}
        <div class="bg-surface rounded-xl shadow-sm border border-border p-6 text-center">
            <i data-lucide="check-circle" class="w-12 h-12 text-success mx-auto mb-3"></i>
            <h2 class="text-lg font-bold text-text mb-2">¡Gracias por tu aporte!</h2>
            <p class="text-text-muted text-sm mb-6">{{ $resumen }}</p>
            <div class="flex items-center justify-center gap-3">
                <button wire:click="resetForm"
                    class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm border border-border text-text hover:bg-page transition-colors duration-150 cursor-pointer">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                    Hacer otro aporte
                </button>
                <a href="{{ route('questions.index') }}" class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm bg-accent text-white hover:bg-orange-600 transition-colors duration-150 cursor-pointer">
                    Ver preguntas
                </a>
            </div>
        </div>

    @elseif ($this->repositories->isEmpty())
        {{-- 0 repositorios activos --}}
        <div class="bg-surface rounded-xl shadow-sm border border-border p-10 text-center">
            <i data-lucide="plug" class="w-12 h-12 text-accent mx-auto mb-3"></i>
            <h2 class="text-lg font-bold text-text mb-2">Necesitás una conexión activa</h2>
            <p class="text-sm text-text-muted mb-5">Conectá una fuente de conocimiento (Kuaforia o QuBeKa) en Configuración para aportar.</p>
            <a href="{{ route('settings') }}" wire:navigate
                class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm bg-accent text-white hover:bg-orange-600 transition-colors duration-150 cursor-pointer">
                <i data-lucide="settings" class="w-4 h-4"></i>
                Ir a Configuración
            </a>
        </div>

    @else
        {{-- Estado: idle o analyzing --}}
        <form wire:submit="submit" class="bg-surface rounded-xl shadow-sm border border-border p-5 space-y-5">
            @if ($this->repositories->count() > 1)
                <div>
                    <label for="repositoryId" class="block text-sm font-medium text-text mb-1.5">Fuente de conocimiento</label>
                    <select id="repositoryId" wire:model="repositoryId"
                        class="w-full border border-border rounded-lg px-3 py-2 text-sm text-text focus:ring-2 focus:ring-primary/30 focus:border-primary outline-none transition-all duration-150 bg-surface">
                        @foreach ($this->repositories as $repo)
                            @php $cfg = config('kuestion.connectors.' . $repo->connector_type, []); @endphp
                            <option value="{{ $repo->id }}">
                                {{ $cfg['display_name'] ?? $repo->connector_type }} — {{ $repo->resolved_tenant_name ?? $repo->name ?? $repo->resolved_tenant_slug }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @elseif ($this->repositories->count() === 1)
                @php
                    $singleRepo = $this->repositories->first();
                    $singleCfg = config('kuestion.connectors.' . $singleRepo->connector_type, []);
                @endphp
                <div class="flex items-center gap-2 text-sm text-text-muted">
                    <i data-lucide="plug" class="w-4 h-4 text-primary"></i>
                    Enviando a <span class="font-medium text-text">{{ $singleCfg['display_name'] ?? $singleRepo->connector_type }}</span> — {{ $singleRepo->resolved_tenant_name ?? $singleRepo->name ?? $singleRepo->resolved_tenant_slug }}
                </div>
            @endif

            @if ($preguntaPrevia)
                <div class="bg-page rounded-lg p-3 border border-border">
                    <p class="text-xs text-text-muted mb-1">Tu pregunta anterior:</p>
                    <p class="text-sm text-text font-medium">{{ $preguntaPrevia }}</p>
                </div>
            @endif

            <div>
                <label for="texto" class="block text-sm font-medium text-text mb-1.5">Tu aporte</label>
                <textarea id="texto" wire:model.live.debounce.300ms="texto" rows="4"
                    class="w-full border rounded-lg px-3 py-2 text-sm text-text placeholder-text-muted/50 focus:ring-2 focus:ring-primary/30 focus:border-primary outline-none transition-all duration-150 bg-surface resize-none @error('texto') border-danger @else border-border @enderror"
                    placeholder="Ej: El job falla porque el batch del banco no llega antes de las 6am" maxlength="2000"
                    @if ($status === 'analyzing') disabled @endif></textarea>
                @error('texto')
                    <p class="mt-1 text-sm text-danger">{{ $message }}</p>
                @enderror
                <p class="mt-1 text-xs text-text-muted text-right">{{ strlen($texto) }}/2000</p>
            </div>

            @if ($error)
                <div class="flex items-start gap-3 bg-red-50 border border-red-200 rounded-lg p-4">
                    <i data-lucide="alert-circle" class="w-5 h-5 text-danger shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-medium text-red-800">No se pudo enviar el aporte</p>
                        <p class="text-sm text-red-700 mt-1">{{ $error }}</p>
                        @if ($hasDraft)
                            <p class="text-xs text-red-600 mt-1.5">Tu texto quedó guardado como borrador. Podés reintentar cuando quieras.</p>
                        @endif
                    </div>
                </div>
            @endif

            <div class="flex items-center justify-end gap-3 pt-2">
                <a href="{{ route('questions.index') }}" class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm border border-border text-text hover:bg-page transition-colors duration-150 cursor-pointer">
                    Cancelar
                </a>
                @if ($hasDraft && $status === 'error')
                    <button type="button" wire:click="retryFromDraft" wire:loading.attr="disabled"
                        class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm transition-colors duration-150 cursor-pointer bg-accent text-white hover:bg-orange-600">
                        <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                        Reintentar
                    </button>
                @else
                    <button type="submit" @if ($status === 'analyzing') disabled @endif
                        class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm transition-colors duration-150 cursor-pointer border border-primary text-primary hover:bg-primary hover:text-white @if ($status === 'analyzing') opacity-50 cursor-not-allowed @endif">
                        @if ($status === 'analyzing')
                            <svg class="animate-spin w-4 h-4" viewBox="0 0 24 24" fill="none">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                            </svg>
                            Analizando tu aporte...
                        @else
                            <i data-lucide="send" class="w-4 h-4"></i>
                            Aportar
                        @endif
                    </button>
                @endif
            </div>
        </form>
    @endif
</div>
