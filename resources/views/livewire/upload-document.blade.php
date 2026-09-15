<div>
    <div class="mb-6">
        <a href="{{ route('questions.index') }}" class="inline-flex items-center gap-1.5 text-sm text-text-muted hover:text-text transition-colors duration-150">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>
            Volver a preguntas
        </a>
    </div>

    <h1 class="text-xl font-bold text-text mb-6">Subir documento</h1>

    @if ($status === 'listo')
        {{-- B.5: estado final de éxito — copy del spec §1.1.6. --}}
        <div class="bg-surface rounded-xl shadow-sm border border-border p-6 text-center">
            <i data-lucide="check-circle" class="w-12 h-12 text-success mx-auto mb-3"></i>
            <h2 class="text-lg font-bold text-text mb-2">¡Listo!</h2>
            <p class="text-text-muted text-sm">
                Se propusieron {{ $nodosPropuestos }} nodos a partir de este documento.
            </p>
            <div class="flex items-center justify-center gap-3 mt-6">
                <button wire:click="resetForm"
                    class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm border border-border text-text hover:bg-page transition-colors duration-150 cursor-pointer">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                    Subir otro documento
                </button>
                <button wire:click="irARevisar"
                    class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm bg-accent text-white hover:bg-orange-600 transition-colors duration-150 cursor-pointer">
                    <i data-lucide="clipboard-check" class="w-4 h-4"></i>
                    Revisar
                </button>
            </div>
        </div>

    @elseif ($status === 'procesando')
        {{-- B.5: estado de progreso con polling — nunca "cargando" sin información. --}}
        <div class="bg-surface rounded-xl shadow-sm border border-border p-6 text-center">
            <svg class="h-8 w-8 animate-spin text-primary mx-auto mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <h2 class="text-lg font-bold text-text mb-1">Procesando documento...</h2>
            <p class="text-text-muted text-sm mb-4">Esto puede tomar unos minutos.</p>

            @if ($chunksTotales > 0)
                <p class="text-sm text-text-muted">
                    {{ max($chunksProcesados, 0) }} de {{ $chunksTotales }} bloques analizados.
                </p>
            @endif

            <div class="flex items-center justify-center gap-3 mt-6">
                <a href="{{ route('questions.index') }}"
                    class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm border border-border text-text hover:bg-page transition-colors duration-150 cursor-pointer">
                    Volver a preguntas
                </a>
            </div>
            <p class="mt-3 text-xs text-text-muted">Podés cerrar esta pantalla: el análisis continúa y lo retomamos al volver.</p>
        </div>

    @elseif ($status === 'subiendo')
        {{-- B.1: validación/extracción/envío en curso. --}}
        <div class="bg-surface rounded-xl shadow-sm border border-border p-6 text-center">
            <svg class="h-8 w-8 animate-spin text-primary mx-auto mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <h2 class="text-lg font-bold text-text mb-1">Preparando el documento...</h2>
            <p class="text-text-muted text-sm">Extrayendo el texto y enviándolo a análisis.</p>
        </div>

    @elseif ($status === 'error')
        {{-- B.7: fallo visible con cómo proceder (reintentar / bandeja). --}}
        <div class="bg-surface rounded-xl shadow-sm border border-border p-6">
            <div class="flex items-start gap-3">
                <i data-lucide="alert-triangle" class="w-6 h-6 text-danger flex-shrink-0"></i>
                <div class="flex-1">
                    <h2 class="text-lg font-bold text-text mb-1">No se pudo procesar el documento</h2>
                    <p class="text-text-muted text-sm">{{ $error }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3 mt-5">
                <button wire:click="reintentar"
                    class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm bg-accent text-white hover:bg-orange-600 transition-colors duration-150 cursor-pointer">
                    <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                    Reintentar
                </button>
                <button wire:click="resetForm"
                    class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm border border-border text-text hover:bg-page transition-colors duration-150 cursor-pointer">
                    Subir otro documento
                </button>
                <a href="{{ route('reviews.index') }}"
                    class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm text-primary hover:underline cursor-pointer">
                    Ir a la bandeja
                </a>
            </div>
        </div>

    @else
        {{-- Estado: idle — selector + contexto opcional (B.1). --}}
        <form wire:submit="submit" class="bg-surface rounded-xl shadow-sm border border-border p-5 space-y-5">
            @if ($error)
                <div class="rounded-lg border border-danger/30 bg-danger/5 p-3 text-sm text-danger">{{ $error }}</div>
            @endif

            @if ($duplicadoUploadId !== null)
                {{-- B.2/FB-8: aviso de duplicado antes de procesar (no bloquea). --}}
                <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">
                    <p class="font-medium">Este documento ya fue subido anteriormente.</p>
                    <p class="mt-0.5">¿Querés subirlo de todos modos?</p>
                    <div class="flex items-center gap-2 mt-2">
                        <button type="button" wire:click="confirmarDuplicado"
                            class="px-3 py-1.5 rounded-md text-sm font-medium bg-amber-600 text-white hover:bg-amber-700 transition-colors duration-150 cursor-pointer">
                            Subir de todos modos
                        </button>
                        <button type="button" wire:click="cancelarDuplicado"
                            class="px-3 py-1.5 rounded-md text-sm font-medium border border-amber-300 text-amber-800 hover:bg-amber-100 transition-colors duration-150 cursor-pointer">
                            Cancelar
                        </button>
                    </div>
                </div>
            @endif

            <div>
                <label class="block text-sm font-medium text-text mb-1.5" for="documento">Documento</label>
                <input id="documento" type="file" wire:model="documento" accept=".txt,.md,.pdf,.docx"
                    class="w-full text-sm text-text border border-border rounded-lg cursor-pointer bg-page
                        file:mr-3 file:px-4 file:py-2 file:rounded-md file:border-0 file:text-sm file:font-medium
                        file:bg-primary/10 file:text-primary hover:file:bg-primary/20 transition-colors duration-150">
                <p class="mt-1.5 text-xs text-text-muted">Formatos: TXT, MD, PDF, DOCX. Máximo 20 MB, hasta 100 páginas.</p>
                <div wire:loading wire:target="documento" class="mt-2 text-sm text-text-muted">Cargando archivo...</div>
                @error('documento') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-text mb-1.5" for="contexto">¿De qué trata este documento? <span class="font-normal text-text-muted">(opcional)</span></label>
                <textarea id="contexto" wire:model="contexto" rows="2" maxlength="500"
                    class="w-full border border-border rounded-lg px-3 py-2 text-sm text-text placeholder-text-muted/50 focus:ring-2 focus:ring-primary/30 focus:border-primary outline-none transition-all duration-150 bg-page"
                    placeholder="Ej: Análisis trimestral del equipo de producto"></textarea>
            </div>

            <div class="flex items-center justify-end gap-3">
                <button type="submit"
                    wire:loading.attr="disabled" wire:target="submit"
                    class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm bg-accent text-white hover:bg-orange-600 transition-colors duration-150 cursor-pointer disabled:opacity-50">
                    <i data-lucide="upload" class="w-4 h-4"></i>
                    Subir documento
                </button>
            </div>
        </form>
    @endif
</div>
