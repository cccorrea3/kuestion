<div>
    <div class="mb-6 flex items-center justify-between">
        <a href="{{ route('questions.index') }}" class="inline-flex items-center gap-1.5 text-sm text-text-muted hover:text-text transition-colors duration-150">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>
            Volver
        </a>
        <div class="flex items-center gap-2">
            <button wire:click="toggleStar" class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-text-muted hover:text-text hover:bg-page transition-colors duration-150 cursor-pointer" title="{{ $question->is_starred ? 'Quitar destacada' : 'Destacar' }}">
                <i data-lucide="star" class="w-5 h-5 {{ $question->is_starred ? 'text-accent fill-accent' : '' }}"></i>
            </button>
            <button wire:click="$toggle('confirmDelete')" class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-text-muted hover:text-danger hover:bg-page transition-colors duration-150 cursor-pointer" title="Archivar">
                <i data-lucide="archive" class="w-5 h-5"></i>
            </button>
        </div>
    </div>

    @if ($confirmDelete)
        <div class="bg-surface rounded-xl shadow-sm border border-border p-5 mb-6">
            <div class="flex items-start gap-3">
                <i data-lucide="alert-triangle" class="w-5 h-5 text-warning shrink-0 mt-0.5"></i>
                <div class="flex-1">
                    <p class="text-sm font-medium text-text">¿Archivar esta pregunta?</p>
                    <p class="text-sm text-text-muted mt-1">Se archivará y dejará de consultarse. Puedes recuperarla después.</p>
                    <div class="flex gap-2 mt-3">
                        <button wire:click="archive" class="inline-flex items-center justify-center gap-2 px-3 py-1.5 rounded-lg font-medium text-sm bg-danger text-white hover:bg-red-700 transition-colors duration-150 cursor-pointer">
                            Archivar
                        </button>
                        <button wire:click="$set('confirmDelete', false)" class="inline-flex items-center justify-center gap-2 px-3 py-1.5 rounded-lg font-medium text-sm border border-border text-text hover:bg-page transition-colors duration-150 cursor-pointer">
                            Cancelar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="space-y-6">
        <div class="bg-surface rounded-xl shadow-sm border border-border p-5">
            <h1 class="text-lg font-bold text-text mb-4">{{ $question->question_text }}</h1>
            <div class="flex flex-wrap items-center gap-2 text-xs text-text-muted">
                @if ($question->tags && count($question->tags) > 0)
                    @foreach ($question->tags as $tag)
                        <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-teal-100 text-primary text-xs font-medium">{{ $tag }}</span>
                    @endforeach
                @endif
                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-teal-100 text-primary">{{ $question->review_frequency }}</span>
                <span>{{ $question->created_at->isoFormat('D [de] MMMM [de] YYYY') }}</span>
                <span>· {{ $versionCount }} {{ str('versión')->plural($versionCount) }}</span>
            </div>
        </div>

        @if ($currentVersion)
            <div class="bg-surface rounded-xl shadow-sm border border-border p-5">
                <h2 class="text-sm font-bold text-text mb-3 flex items-center gap-2">
                    <i data-lucide="message-square" class="w-4 h-4 text-primary"></i>
                    Respuesta actual
                </h2>
                <div class="prose prose-sm max-w-none text-text">
                    {{ $currentVersion->answer_text }}
                </div>
                <div class="flex flex-wrap items-center gap-4 mt-4 pt-4 border-t border-border text-xs text-text-muted">
                    <span class="flex items-center gap-1">
                        Confianza:
                        <span class="font-medium text-text">{{ $currentVersion->confidence }}%</span>
                    </span>
                    @if ($currentVersion->sources && count($currentVersion->sources) > 0)
                        <span class="flex items-center gap-1">
                            <i data-lucide="book-open" class="w-3.5 h-3.5"></i>
                            {{ count($currentVersion->sources) }} {{ str('fuente')->plural(count($currentVersion->sources)) }}
                        </span>
                    @endif
                    <span>v{{ $currentVersion->version_number }}</span>
                    @if ($currentVersion->created_at)
                        <span>{{ $currentVersion->created_at->diffForHumans() }}</span>
                    @endif
                </div>
            </div>

            <div class="bg-surface rounded-xl shadow-sm border border-border p-5">
                <h2 class="text-sm font-bold text-text mb-3">¿Te fue útil esta respuesta?</h2>
                <div class="flex items-center gap-3">
                    <button wire:click="setFeedback('helpful')" @class([
                        'inline-flex items-center gap-2 px-4 py-2 rounded-lg font-medium text-sm transition-colors duration-150 cursor-pointer',
                        'bg-green-100 text-green-700' => $feedback === 'helpful',
                        'border border-border text-text-muted hover:text-text hover:bg-page' => $feedback !== 'helpful',
                    ])>
                        <i data-lucide="thumbs-up" class="w-4 h-4"></i>
                        Útil
                    </button>
                    <button wire:click="setFeedback('not_helpful')" @class([
                        'inline-flex items-center gap-2 px-4 py-2 rounded-lg font-medium text-sm transition-colors duration-150 cursor-pointer',
                        'bg-red-100 text-red-700' => $feedback === 'not_helpful',
                        'border border-border text-text-muted hover:text-text hover:bg-page' => $feedback !== 'not_helpful',
                    ])>
                        <i data-lucide="thumbs-down" class="w-4 h-4"></i>
                        No útil
                    </button>
                </div>
            </div>
        @else
            <div class="bg-surface rounded-xl shadow-sm border border-border p-5 text-center">
                <i data-lucide="clock" class="w-8 h-8 text-text-muted mx-auto mb-2"></i>
                <p class="text-sm text-text-muted">Esperando la primera consulta...</p>
            </div>
        @endif
    </div>
</div>
