<x-layouts.app title="Cuenta creada">
    <div class="relative flex flex-col items-center justify-center py-20 text-center max-w-lg mx-auto px-4">
        {{-- Decorative background --}}
        <div class="absolute inset-0 -z-10 overflow-hidden">
            <div class="absolute -top-40 left-1/2 -translate-x-1/2 w-[500px] h-[500px] rounded-full bg-gradient-to-b from-primary/[0.04] to-transparent blur-3xl"></div>
        </div>

        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-success/20 to-primary/20 flex items-center justify-center mb-6 ring-1 ring-success/10 shadow-lg shadow-success/5">
            <svg class="w-8 h-8 text-success" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
            </svg>
        </div>

        <h1 class="text-2xl font-bold text-text tracking-tight">Cuenta creada con éxito</h1>

        @php
            // C5 — El nombre de la organización sale del primer repositorio del usuario
            // (resolved_tenant_name), no de una lista hardcodeada (B3).
            $repo = auth()->user()->repositories()->orderByDesc('is_default')->orderBy('created_at')->first();
            $tenantName = $repo?->resolved_tenant_name;
        @endphp

        @if ($tenantName)
            <p class="text-sm text-text-muted mt-2">
                Tu organización: <strong class="text-text">{{ $tenantName }}</strong>
            </p>

            <div class="mt-3 mb-10 px-6 py-4 rounded-xl bg-primary/[0.03] border border-primary/5">
                <p class="text-sm text-text-muted leading-relaxed">
                    Ahora puedes hacer consultas sobre tu base de conocimiento en Kuaforia y recibir notificaciones cuando las respuestas cambien.
                </p>
            </div>

            <div class="flex flex-col sm:flex-row gap-3">
                <x-button href="{{ route('questions.create') }}">Hacer mi primera consulta</x-button>
                <x-button href="{{ route('questions.index') }}" variant="secondary">Explorar preguntas existentes</x-button>
            </div>
        @else
            <p class="text-sm text-text-muted mt-2">
                Conectá tu fuente de conocimiento para empezar a vigilar preguntas.
            </p>

            <div class="mt-3 mb-10 px-6 py-4 rounded-xl bg-primary/[0.03] border border-primary/5">
                <p class="text-sm text-text-muted leading-relaxed">
                    Sin una conexión activa no podrás crear preguntas nuevas.
                </p>
            </div>

            <x-button href="{{ route('settings') }}">Conectar Kuaforia</x-button>
        @endif
    </div>
</x-layouts.app>
