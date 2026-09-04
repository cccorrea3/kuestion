@props(['question', 'showSource' => false])

<div
    x-data="{ swiped: false, startX: 0, currentX: 0 }"
    @touchstart="startX = $event.touches[0].clientX; currentX = $event.touches[0].clientX"
    @touchmove="currentX = $event.touches[0].clientX; swiped = (startX - currentX) > 80"
    @touchend="if (swiped) { $wire.archive('{{ $question->id }}'); swiped = false }"
    class="relative overflow-hidden">
    <div class="absolute inset-y-0 right-0 flex items-center bg-danger text-white px-4 text-sm font-medium rounded-xl"
        x-show="swiped" x-cloak>
        Archivar
    </div>
    <a href="{{ route('questions.show', $question) }}" @class([
        'block bg-surface rounded-xl shadow-sm border p-5 transition-all duration-150 hover:shadow-md relative',
        'border-border' => !$question->has_unreviewed_changes,
        'border-accent/50' => $question->has_unreviewed_changes,
    ])>
        <div class="flex items-start justify-between gap-4">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-2">
                    @if ($question->has_unreviewed_changes)
                        @php
                            $isFresh = $question->last_change_detected_at && $question->last_change_detected_at->gt(now()->subHours(24));
                            // Ola 1 P5/6 — F3 (3.7): transición sin→con → copy especial.
                            $wasEmptyPrev = $question->currentVersion?->was_empty_prev ?? false;
                        @endphp
                        @if ($wasEmptyPrev)
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-teal-100 text-primary">
                                <span class="w-1.5 h-1.5 rounded-full bg-primary"></span>
                                Ahora hay información sobre algo que preguntaste
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-700">
                                <span class="w-1.5 h-1.5 rounded-full bg-orange-500 {{ $isFresh ? 'animate-pulse' : '' }}"></span>
                                Cambio sin revisar
                            </span>
                        @endif
                    @endif
                    @if ($question->is_starred)
                        <i data-lucide="star" class="w-4 h-4 text-accent fill-accent"></i>
                    @endif
                    {{-- F1 — estado del repositorio (UX §6.9): invalid/revoked visibles en el feed. --}}
                    <x-repository-status-badge :repository="$question->repository" />
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-teal-100 text-primary">
                        {{ $question->review_frequency }}
                    </span>
                </div>
                <p class="font-medium text-text truncate">{{ $question->question_text }}</p>
                @if ($question->answer_text)
                    <p class="text-sm text-text-muted mt-1 line-clamp-2">{{ strip_tags($question->answer_text) }}</p>
                @endif
                <div class="flex items-center gap-2 mt-3 text-xs text-text-muted">
                    {{-- Ola 1 P5/6 — F1: copy de vigencia honesto para QBK (sin fecha_ultima_confirmacion). --}}
                    @if ($question->repository?->connector_type === 'qbk')
                        <span>Agregado hace {{ $question->created_at->longAbsoluteDiffForHumans() }} — sin reconfirmaciones registradas</span>
                    @else
                        <span>{{ $question->created_at->diffForHumans() }}</span>
                    @endif
                    {{-- Ola 1 P5/6 — F2: tag de fuente solo con >1 repo activo (§2.3). --}}
                    @if ($showSource && $question->repository)
                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-gray-100 text-text-muted font-medium">
                            {{ config("kuestion.connectors.{$question->repository->connector_type}.display_name", $question->repository->connector_type) }}
                        </span>
                    @endif
                    @if ($question->tags && count($question->tags) > 0)
                        <span>·</span>
                        <div class="flex gap-1">
                            @foreach (array_slice($question->tags, 0, 3) as $tag)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-teal-100 text-primary text-xs font-medium">{{ $tag }}</span>
                            @endforeach
                            @if (count($question->tags) > 3)
                                <span class="text-text-muted">+{{ count($question->tags) - 3 }}</span>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
            <button wire:click.prevent="toggleStar('{{ $question->id }}')"
                class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-text-muted hover:text-accent transition-colors duration-150 cursor-pointer shrink-0"
                title="{{ $question->is_starred ? 'Quitar destacada' : 'Destacar' }}"
                aria-label="{{ $question->is_starred ? 'Quitar destacada' : 'Destacar' }}">
                <i data-lucide="star" class="w-4 h-4 {{ $question->is_starred ? 'text-accent fill-accent' : '' }}"></i>
            </button>
        </div>
    </a>
</div>
