@props(['versions', 'currentVersionId' => null])

<div {{ $attributes->merge(['class' => 'space-y-2']) }}>
    @forelse ($versions as $version)
        <div @class([
            'flex items-start gap-3 p-3 rounded-lg transition-colors duration-150',
            'bg-teal-50 border border-teal-200' => $version->id === $currentVersionId,
            'hover:bg-page cursor-pointer' => $version->version_number > 1 && $version->id !== $currentVersionId,
        ]) @if ($version->version_number > 1) wire:click="$parent.showDiff({{ $version->version_number - 1 }}, {{ $version->version_number }})" @endif>
            <div class="flex flex-col items-center">
                <div @class([
                    'w-2.5 h-2.5 rounded-full shrink-0',
                    'bg-primary' => $version->is_current,
                    'bg-border' => !$version->is_current,
                ])></div>
                @if (!$loop->last)
                    <div class="w-px h-full bg-border min-h-[2rem]"></div>
                @endif
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-medium text-text">v{{ $version->version_number }}</span>
                        @if ($version->is_current)
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-teal-100 text-primary">actual</span>
                        @endif
                        @if ($version->status === 'dismissed')
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-gray-100 text-text-muted">descartada</span>
                        @endif
                    </div>
                    <span class="text-xs text-text-muted whitespace-nowrap">{{ $version->created_at->diffForHumans() }}</span>
                </div>
                <div class="flex items-center gap-3 mt-1 text-xs text-text-muted">
                    <span>Confianza: {{ $version->confidence }}%</span>
                    @if ($version->sources && count($version->sources) > 0)
                        <span>{{ count($version->sources) }} {{ str('fuente')->plural(count($version->sources)) }}</span>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <p class="text-sm text-text-muted text-center py-4">Sin versiones registradas.</p>
    @endforelse
</div>
