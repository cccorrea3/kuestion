<x-layouts.app title="Cuenta creada">
    <div class="flex flex-col items-center justify-center py-16 text-center max-w-lg mx-auto">
        <div class="w-16 h-16 rounded-2xl bg-success/10 flex items-center justify-center mb-6">
            <svg class="w-8 h-8 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
            </svg>
        </div>

        <h1 class="text-2xl font-bold text-text mb-2">Cuenta creada con éxito</h1>
        @php $tenantName = collect(config('services.kuaforia.tenants'))->firstWhere('slug', auth()->user()->tenant_slug)['name'] ?? auth()->user()->tenant_slug; @endphp
        <p class="text-text-muted text-sm mb-1">
            Tu organización: <strong>{{ $tenantName }}</strong>
        </p>
        <p class="text-text-muted text-sm mb-8">
            Ahora puedes hacer consultas sobre tu base de conocimiento.
        </p>

        <div class="flex flex-col sm:flex-row gap-3">
            <x-button href="{{ route('questions.create') }}">Hacer mi primera consulta</x-button>
            <x-button href="{{ route('questions.index') }}" variant="secondary">Explorar preguntas existentes</x-button>
        </div>
    </div>
</x-layouts.app>
