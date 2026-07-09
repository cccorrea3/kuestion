@props(['question'])

<a href="{{ route('questions.show', $question) }}" @class([
    'block bg-surface rounded-xl shadow-sm border p-5 transition-all duration-150 hover:shadow-md',
    'border-border' => !$question->has_unreviewed_changes,
    'border-accent/50' => $question->has_unreviewed_changes,
])>
    <div class="flex items-start justify-between gap-4">
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-2">
                @if ($question->has_unreviewed_changes)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-700">
                        <span class="w-1.5 h-1.5 rounded-full bg-orange-500 animate-pulse"></span>
                        Cambio sin revisar
                    </span>
                @endif
                @if ($question->is_starred)
                    <i data-lucide="star" class="w-4 h-4 text-accent fill-accent"></i>
                @endif
                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-teal-100 text-primary">
                    {{ $question->review_frequency }}
                </span>
            </div>
            <p class="font-medium text-text truncate">{{ $question->question_text }}</p>
            @if ($question->answer_text)
                <p class="text-sm text-text-muted mt-1 line-clamp-2">{{ strip_tags($question->answer_text) }}</p>
            @endif
            <div class="flex items-center gap-2 mt-3 text-xs text-text-muted">
                <span>{{ $question->created_at->diffForHumans() }}</span>
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
        <i data-lucide="chevron-right" class="w-5 h-5 text-text-muted shrink-0 mt-1"></i>
    </div>
</a>
