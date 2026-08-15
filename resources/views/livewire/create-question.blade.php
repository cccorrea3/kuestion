<div>
    <div class="mb-6">
        <a href="{{ route('questions.index') }}" class="inline-flex items-center gap-1.5 text-sm text-text-muted hover:text-text transition-colors duration-150">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>
            Volver a preguntas
        </a>
    </div>

    <h1 class="text-xl font-bold text-text mb-6">Nueva pregunta</h1>

    @if ($status === 'saved')
        <div class="bg-surface rounded-xl shadow-sm border border-border p-6 text-center">
            <i data-lucide="check-circle" class="w-12 h-12 text-success mx-auto mb-3"></i>
            <h2 class="text-lg font-bold text-text mb-2">Pregunta guardada</h2>
            <p class="text-text-muted text-sm mb-4">Kuestion ya está vigilando la respuesta de Kuaforia.</p>
            <div class="bg-page rounded-lg p-4 mb-6 text-left">
                <p class="font-medium text-text text-sm mb-1">{{ $questionText }}</p>
                <p class="text-text-muted text-sm">{{ str($answerText)->limit(200) }}</p>
            </div>
            <div class="flex items-center justify-center gap-3">
                <a href="{{ route('questions.create') }}" class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm border border-border text-text hover:bg-page transition-colors duration-150 cursor-pointer">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                    Otra pregunta
                </a>
                <a href="{{ route('questions.index') }}" class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm bg-accent text-white hover:bg-orange-600 transition-colors duration-150 cursor-pointer">
                    Ver todas
                </a>
            </div>
        </div>
    @elseif ($this->repositories->isEmpty())
        {{-- D2 — 0 repositorios activos: bloqueo de creación (§6.5/6.12) --}}
        <div class="bg-surface rounded-xl shadow-sm border border-border p-10 text-center">
            <i data-lucide="plug" class="w-12 h-12 text-accent mx-auto mb-3"></i>
            <h2 class="text-lg font-bold text-text mb-2">Necesitás una conexión activa</h2>
            <p class="text-sm text-text-muted mb-5">Conectá tu base de conocimiento de Kuaforia para crear preguntas y vigilar respuestas.</p>
            <a href="{{ route('settings') }}" wire:navigate
                class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm bg-accent text-white hover:bg-orange-600 transition-colors duration-150 cursor-pointer">
                <i data-lucide="settings" class="w-4 h-4"></i>
                Ir a Configuración
            </a>
        </div>
    @else
        <form wire:submit="save" class="bg-surface rounded-xl shadow-sm border border-border p-5 space-y-5">
            @if ($this->repositories->count() > 1)
                <div>
                    <label for="repositoryId" class="block text-sm font-medium text-text mb-1.5">Conexión a usar</label>
                    <select id="repositoryId" wire:model="repositoryId"
                        class="w-full border border-border rounded-lg px-3 py-2 text-sm text-text focus:ring-2 focus:ring-primary/30 focus:border-primary outline-none transition-all duration-150 bg-surface">
                        @foreach ($this->repositories as $repo)
                            <option value="{{ $repo->id }}">{{ $repo->name ?? ($repo->resolved_tenant_name ?? $repo->resolved_tenant_slug) }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div>
                <label for="questionText" class="block text-sm font-medium text-text mb-1.5">Tu pregunta</label>
                <textarea id="questionText" wire:model.live.debounce.300ms="questionText" rows="4"
                    class="w-full border rounded-lg px-3 py-2 text-sm text-text placeholder-text-muted/50 focus:ring-2 focus:ring-primary/30 focus:border-primary outline-none transition-all duration-150 bg-surface resize-none @error('questionText') border-danger @else border-border @enderror"
                    placeholder="Ej: ¿Cuál es la capital de Francia?" maxlength="2000"></textarea>
                @error('questionText')
                    <p class="mt-1 text-sm text-danger">{{ $message }}</p>
                @enderror
                <p class="mt-1 text-xs text-text-muted text-right">{{ strlen($questionText) }}/2000</p>
            </div>

            <div>
                <label for="tagInput" class="block text-sm font-medium text-text mb-1.5">Tags</label>
                <div class="flex gap-2">
                    <input id="tagInput" wire:model.live.debounce.300ms="tagInput" wire:keydown.enter.prevent="addTag"
                        class="flex-1 border border-border rounded-lg px-3 py-2 text-sm text-text placeholder-text-muted/50 focus:ring-2 focus:ring-primary/30 focus:border-primary outline-none transition-all duration-150 bg-surface"
                        placeholder="Ej: embeddings, rag, openai...">
                    <button type="button" wire:click="addTag"
                        class="inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg font-medium text-sm border border-border text-text hover:bg-page transition-colors duration-150 cursor-pointer">
                        <i data-lucide="plus" class="w-4 h-4"></i>
                    </button>
                </div>
                @error('tags')
                    <p class="mt-1 text-sm text-danger">{{ $message }}</p>
                @enderror
                @if (count($tags) > 0)
                    <div class="flex flex-wrap gap-1.5 mt-2">
                        @foreach ($tags as $index => $tag)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-teal-100 text-primary text-xs font-medium">
                                {{ $tag }}
                                <button type="button" wire:click="removeTag({{ $index }})" class="hover:text-danger transition-colors duration-150 cursor-pointer">&times;</button>
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>

            <div>
                <label for="reviewFrequency" class="block text-sm font-medium text-text mb-1.5">Frecuencia de revisión</label>
                <select id="reviewFrequency" wire:model="reviewFrequency"
                    class="w-full border border-border rounded-lg px-3 py-2 text-sm text-text focus:ring-2 focus:ring-primary/30 focus:border-primary outline-none transition-all duration-150 bg-surface">
                    <option value="weekly">Semanal</option>
                    <option value="monthly">Mensual</option>
                    <option value="quarterly">Trimestral</option>
                </select>
            </div>

            @if (count($suggestions) > 0)
                <div class="border border-teal-200 rounded-xl bg-teal-50 p-4 space-y-3">
                    <div class="flex items-center gap-2 text-sm font-medium text-primary">
                        <i data-lucide="link-2" class="w-4 h-4"></i>
                        Relaciones sugeridas
                    </div>

                    <div class="space-y-2">
                        @foreach ($suggestions as $suggestion)
                            <div class="flex items-center justify-between gap-3 bg-white rounded-lg px-3 py-2 border border-teal-100">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm text-text truncate">{{ $suggestion['question_text'] }}</p>
                                    <div class="flex items-center gap-2 mt-0.5">
                                        @foreach ($suggestion['matched_tags'] as $tag)
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-teal-100 text-primary">{{ $tag }}</span>
                                        @endforeach
                                        @if (count($suggestion['matched_keywords']) > 0)
                                            <span class="text-xs text-text-muted">
                                                {{ count($suggestion['matched_keywords']) }} palabra{{ count($suggestion['matched_keywords']) !== 1 ? 's' : '' }} en común
                                            </span>
                                        @endif
                                    </div>
                                </div>
                                <button type="button" wire:click="toggleRelation('{{ $suggestion['id'] }}')"
                                    class="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors duration-150 cursor-pointer
                                    @if (in_array($suggestion['id'], $confirmedRelations))
                                        bg-primary text-white
                                    @else
                                        border border-primary text-primary hover:bg-primary hover:text-white
                                    @endif">
                                    @if (in_array($suggestion['id'], $confirmedRelations))
                                        <i data-lucide="check" class="w-3.5 h-3.5"></i>
                                        Conectada
                                    @else
                                        <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                                        Conectar
                                    @endif
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($error)
                <div class="flex items-start gap-3 bg-red-50 border border-red-200 rounded-lg p-4">
                    <i data-lucide="alert-circle" class="w-5 h-5 text-danger shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-medium text-red-800">Error al consultar Kuaforia</p>
                        <p class="text-sm text-red-700 mt-1">{{ $error }}</p>
                    </div>
                </div>
            @endif

            <div class="flex items-center justify-end gap-3 pt-2">
                <a href="{{ route('questions.index') }}" class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm border border-border text-text hover:bg-page transition-colors duration-150 cursor-pointer">
                    Cancelar
                </a>
                <button type="submit" @if ($status === 'consulting') disabled @endif
                    class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm transition-colors duration-150 cursor-pointer bg-accent text-white hover:bg-orange-600 @if ($status === 'consulting') opacity-50 cursor-not-allowed @endif">
                    @if ($status === 'consulting')
                        <svg class="animate-spin w-4 h-4" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                        </svg>
                        Consultando Kuaforia...
                    @else
                        <i data-lucide="send" class="w-4 h-4"></i>
                        Consultar y guardar
                    @endif
                </button>
            </div>
        </form>
    @endif
</div>
