@props(['removable' => false])

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-teal-100 text-primary text-xs font-medium']) }}>
    @if ($removable)
        <button type="button" class="hover:text-danger transition-colors duration-150 cursor-pointer" x-on:click="$el.parentElement.remove()">&times;</button>
    @endif
    {{ $slot }}
</span>
