@props(['repository'])

{{-- F1 (UX §6.9/5.5): active → nada; invalid → "Conexión inactiva" con enlace a
     /settings (el repo afectado se resalta vía ?highlight=, P12); revoked →
     "Desconectado" sin acción de reparación. --}}
@if ($repository && $repository->status === 'invalid')
    <a href="{{ route('settings', ['highlight' => $repository->id]) }}" wire:navigate
        class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-700 hover:bg-orange-200 transition-colors duration-150"
        title="La conexión con tu fuente de conocimiento está inactiva">
        <i data-lucide="alert-triangle" class="w-3 h-3"></i>
        Conexión inactiva
    </a>
@elseif ($repository && $repository->status === 'revoked')
    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-text-muted"
        title="Esta pregunta quedó desconectada de su fuente">
        <i data-lucide="unplug" class="w-3 h-3"></i>
        Desconectado
    </span>
@endif
