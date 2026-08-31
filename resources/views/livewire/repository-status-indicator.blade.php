<div>
    @if ($invalidRepositoryId)
        <a href="{{ route('settings', ['highlight' => $invalidRepositoryId]) }}" wire:navigate
            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-orange-100 text-orange-700 hover:bg-orange-200 transition-colors duration-150"
            title="Tu conexión está inactiva. Actualizá tu credencial en Configuración.">
            <i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i>
            Conexión inactiva
        </a>
    @endif
</div>
