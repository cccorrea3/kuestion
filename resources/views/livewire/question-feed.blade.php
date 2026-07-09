<div>
    @if (!$hasQuestions)
        <div class="flex flex-col items-center justify-center py-16 text-center">
            <i data-lucide="message-circle" class="w-16 h-16 text-border mb-4"></i>
            <p class="text-text-muted text-sm max-w-md">
                Todavía no tienes preguntas vigiladas.<br>
                Escribe tu primera pregunta y Kuestion la consultará a Kuaforia.
                Después, te avisará si la respuesta cambia con el tiempo.
            </p>
            <div class="mt-4">
                <a href="{{ route('questions.create') }}" class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm bg-accent text-white hover:bg-orange-600 transition-colors duration-150 cursor-pointer">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                    Escribe tu primera pregunta
                </a>
            </div>
        </div>
    @else
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-xl font-bold text-text">Preguntas</h1>
            <a href="{{ route('questions.create') }}" class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg font-medium text-sm bg-accent text-white hover:bg-orange-600 transition-colors duration-150 cursor-pointer">
                <i data-lucide="plus" class="w-4 h-4"></i>
                Nueva
            </a>
        </div>

        <div class="flex flex-col sm:flex-row gap-3 mb-6">
            <div class="relative flex-1">
                <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-text-muted pointer-events-none"></i>
                <input type="search" placeholder="Buscar preguntas..." wire:model.live.debounce.300ms="search"
                    class="w-full border border-border rounded-lg pl-9 pr-3 py-2 text-sm text-text placeholder-text-muted/50 focus:ring-2 focus:ring-primary/30 focus:border-primary outline-none transition-all duration-150 bg-surface">
            </div>
            <div class="flex gap-1 bg-gray-100 rounded-lg p-1 text-sm" role="tablist">
                <button wire:click="$set('filter', 'all')" role="tab" @class([
                    'px-3 py-1.5 rounded-md font-medium transition-colors duration-150 cursor-pointer',
                    'bg-surface text-text shadow-sm' => $filter === 'all',
                    'text-text-muted hover:text-text' => $filter !== 'all',
                ])>Todas</button>
                <button wire:click="$set('filter', 'changes')" role="tab" @class([
                    'px-3 py-1.5 rounded-md font-medium transition-colors duration-150 cursor-pointer',
                    'bg-surface text-text shadow-sm' => $filter === 'changes',
                    'text-text-muted hover:text-text' => $filter !== 'changes',
                ])>Con cambios</button>
                <button wire:click="$set('filter', 'starred')" role="tab" @class([
                    'px-3 py-1.5 rounded-md font-medium transition-colors duration-150 cursor-pointer',
                    'bg-surface text-text shadow-sm' => $filter === 'starred',
                    'text-text-muted hover:text-text' => $filter !== 'starred',
                ])>Destacadas</button>
            </div>
        </div>

        <div class="space-y-3" wire:loading.class="opacity-60" wire:target="filter,search">
            @if ($questions->count() === 0)
                <div class="flex flex-col items-center justify-center py-12 text-center">
                    <i data-lucide="search" class="w-12 h-12 text-border mb-3"></i>
                    <p class="text-text-muted text-sm">No se encontraron preguntas con estos filtros.</p>
                </div>
            @else
                <div wire:loading.remove wire:target="filter,search" class="space-y-3">
                    @foreach ($questions as $question)
                        <x-question-card :question="$question" wire:key="q-{{ $question->id }}" />
                    @endforeach
                </div>
                <div wire:loading wire:target="filter,search" class="space-y-3">
                    @foreach (range(1, 3) as $i)
                        <x-skeleton-card wire:key="skeleton-{{ $i }}" />
                    @endforeach
                </div>
            @endif
        </div>

        @if ($questions->hasPages())
            <div class="mt-6">
                {{ $questions->links(data: ['scrollTo' => false]) }}
            </div>
        @endif
    @endif
</div>
