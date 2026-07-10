<x-layouts.app title="Kuestion — Vigilancia de respuestas AI">
    {{-- Hero --}}
    <div class="relative flex flex-col items-center justify-center pt-20 pb-16 text-center max-w-lg mx-auto px-4">
        {{-- Decorative background --}}
        <div class="absolute inset-0 -z-10 overflow-hidden">
            <div class="absolute -top-40 left-1/2 -translate-x-1/2 w-[600px] h-[600px] rounded-full bg-gradient-to-b from-primary/[0.04] to-transparent blur-3xl"></div>
            <div class="absolute -bottom-40 left-1/2 -translate-x-1/2 w-[400px] h-[400px] rounded-full bg-gradient-to-b from-transparent to-accent/[0.03] blur-3xl"></div>
        </div>

        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-primary/15 to-accent/15 flex items-center justify-center mb-6 ring-1 ring-primary/10 shadow-lg shadow-primary/5">
            <svg class="w-8 h-8 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />
            </svg>
        </div>

        <h1 class="text-3xl sm:text-4xl font-bold text-text tracking-tight leading-tight">
            Tu vigilancia de
            <span class="text-accent">respuestas AI</span>
        </h1>
        <p class="text-text-muted text-sm sm:text-base leading-relaxed mt-3 max-w-sm">
            Cada vez que tu base de conocimiento en <strong class="text-text">Kuaforia</strong> se actualiza,
            <strong class="text-text">Kuestion</strong> detecta si las respuestas cambiaron y te lo muestra al instante.
        </p>

        <div class="flex flex-col sm:flex-row gap-3 mt-8">
            <x-button href="{{ route('register') }}" class="shadow-lg shadow-accent/20">Crear cuenta gratuita</x-button>
            <x-button href="{{ route('login') }}" variant="secondary">Iniciar sesión</x-button>
        </div>
    </div>

    {{-- How it works --}}
    <div class="max-w-xl mx-auto px-4 pb-20">
        <div class="relative">
            {{-- Connecting line --}}
            <div class="absolute left-[19px] top-0 bottom-0 w-px bg-gradient-to-b from-primary/20 via-primary/10 to-transparent hidden sm:block"></div>

            <div class="space-y-8">
                <div class="flex items-start gap-4 relative">
                    <span class="relative z-10 flex items-center justify-center w-10 h-10 rounded-xl bg-gradient-to-br from-primary/20 to-primary/10 text-primary font-bold text-sm shrink-0 ring-1 ring-primary/10">
                        1
                    </span>
                    <div class="pt-1.5">
                        <h3 class="text-sm font-semibold text-text">Haz una pregunta</h3>
                        <p class="text-xs text-text-muted mt-1 leading-relaxed">Kuestion consulta a Kuaforia y obtiene una respuesta con sus fuentes y nivel de confianza.</p>
                    </div>
                </div>

                <div class="flex items-start gap-4 relative">
                    <span class="relative z-10 flex items-center justify-center w-10 h-10 rounded-xl bg-gradient-to-br from-accent/20 to-accent/10 text-accent font-bold text-sm shrink-0 ring-1 ring-accent/10">
                        2
                    </span>
                    <div class="pt-1.5">
                        <h3 class="text-sm font-semibold text-text">Detecta cambios</h3>
                        <p class="text-xs text-text-muted mt-1 leading-relaxed">Cuando Kuaforia actualiza su conocimiento, Kuestion detecta si la respuesta cambió y resalta las diferencias.</p>
                    </div>
                </div>

                <div class="flex items-start gap-4 relative">
                    <span class="relative z-10 flex items-center justify-center w-10 h-10 rounded-xl bg-gradient-to-br from-success/20 to-success/10 text-success font-bold text-sm shrink-0 ring-1 ring-success/10">
                        3
                    </span>
                    <div class="pt-1.5">
                        <h3 class="text-sm font-semibold text-text">Decide qué conservar</h3>
                        <p class="text-xs text-text-muted mt-1 leading-relaxed">Acepta o descarta los cambios. Cada versión queda registrada con su diff visual para auditoría.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Footer CTA --}}
    <div class="text-center pb-20 px-4">
        <div class="max-w-sm mx-auto p-6 rounded-2xl bg-gradient-to-b from-primary/[0.03] to-accent/[0.02] border border-primary/5">
            <p class="text-sm text-text-muted mb-4">
                ¿Tu equipo usa Kuaforia?<br>
                Kuestion es el compañero que vigila tus respuestas automáticamente.
            </p>
            <x-button href="{{ route('register') }}" class="w-full sm:w-auto shadow-lg shadow-accent/20">Comenzar ahora</x-button>
        </div>
    </div>
</x-layouts.app>
