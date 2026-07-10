<x-layouts.app title="Kuestion — Vigilancia de respuestas AI">
    <div class="flex flex-col items-center justify-center py-16 text-center max-w-lg mx-auto">
        <div class="w-16 h-16 rounded-2xl bg-primary/10 flex items-center justify-center mb-6">
            <svg class="w-8 h-8 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />
            </svg>
        </div>

        <h1 class="text-2xl font-bold text-text mb-3">Kuestion</h1>
        <p class="text-text-muted text-sm leading-relaxed mb-2">
            Kuestion vigila las respuestas de tu base de conocimiento en <strong>Kuaforia</strong>.
            Cuando algo cambia, te avisa.
        </p>

        <div class="flex flex-col gap-3 w-full mt-6 mb-8">
            <div class="flex items-start gap-3 bg-page rounded-xl p-4 text-left">
                <span class="flex items-center justify-center w-8 h-8 rounded-lg bg-primary/10 text-primary font-bold text-sm shrink-0">1</span>
                <div>
                    <p class="text-sm font-medium text-text">Haz una pregunta</p>
                    <p class="text-xs text-text-muted mt-0.5">Kuestion consulta a Kuaforia y obtiene una respuesta con fuentes y nivel de confianza.</p>
                </div>
            </div>
            <div class="flex items-start gap-3 bg-page rounded-xl p-4 text-left">
                <span class="flex items-center justify-center w-8 h-8 rounded-lg bg-primary/10 text-primary font-bold text-sm shrink-0">2</span>
                <div>
                    <p class="text-sm font-medium text-text">Detecta cambios</p>
                    <p class="text-xs text-text-muted mt-0.5">Cada vez que Kuaforia actualiza su conocimiento, Kuestion detecta si la respuesta cambió y te lo muestra.</p>
                </div>
            </div>
            <div class="flex items-start gap-3 bg-page rounded-xl p-4 text-left">
                <span class="flex items-center justify-center w-8 h-8 rounded-lg bg-primary/10 text-primary font-bold text-sm shrink-0">3</span>
                <div>
                    <p class="text-sm font-medium text-text">Decide qué conservar</p>
                    <p class="text-xs text-text-muted mt-0.5">Acepta o descarta los cambios. Cada versión queda registrada con su diff visual.</p>
                </div>
            </div>
        </div>

        <a href="{{ route('questions.create') }}">
            <x-button>Empieza vigilando tu primera pregunta</x-button>
        </a>
    </div>
</x-layouts.app>
