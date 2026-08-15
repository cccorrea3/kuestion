<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-bold text-text">Panorama del equipo</h1>
        <x-badge variant="info">
            <i data-lucide="eye" class="w-3 h-3"></i>
            Solo lectura
        </x-badge>
    </div>

    @unless ($defaultRepository)
        {{-- E3/P13 — sin repo default el dashboard degrada con el mensaje de conexión (§6.5), no falla. --}}
        <div class="bg-surface rounded-xl shadow-sm border border-border p-6 mb-6 text-center">
            <i data-lucide="plug" class="w-8 h-8 text-text-muted mx-auto mb-2"></i>
            <p class="text-sm font-medium text-text">No hay una fuente de conocimiento conectada.</p>
            <p class="text-xs text-text-muted mt-1">Conectá tu primer repositorio para ver la salud del equipo.</p>
            <a href="{{ route('settings') }}"
               class="inline-flex items-center gap-1.5 mt-4 px-4 py-2 rounded-lg bg-primary text-white text-sm font-medium hover:bg-primary/90 transition">
                <i data-lucide="settings" class="w-4 h-4"></i>
                Ir a configuraciones
            </a>
        </div>
    @endunless

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-surface rounded-xl shadow-sm border border-border p-4">
            <p class="text-xs text-text-muted flex items-center gap-1">
                <i data-lucide="message-square" class="w-3.5 h-3.5"></i>
                Preguntas activas
            </p>
            <p class="text-2xl font-bold text-text mt-1">{{ $summary['total'] }}</p>
        </div>
        <div class="bg-surface rounded-xl shadow-sm border border-border p-4">
            <p class="text-xs text-text-muted flex items-center gap-1">
                <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i>
                Con cambios sin revisar
            </p>
            <p class="text-2xl font-bold text-text mt-1">{{ $summary['unreviewed_percent'] }}%</p>
            <p class="text-xs text-text-muted mt-0.5">{{ $summary['unreviewed'] }} preguntas</p>
        </div>
        <div class="bg-surface rounded-xl shadow-sm border border-border p-4">
            <p class="text-xs text-text-muted flex items-center gap-1">
                <i data-lucide="users" class="w-3.5 h-3.5"></i>
                Miembros del tenant
            </p>
            <p class="text-2xl font-bold text-text mt-1">{{ $summary['team_size'] }}</p>
        </div>
        <div class="bg-surface rounded-xl shadow-sm border border-border p-4">
            <p class="text-xs text-text-muted flex items-center gap-1">
                <i data-lucide="git-compare" class="w-3.5 h-3.5"></i>
                Cambios esta semana
            </p>
            <p class="text-2xl font-bold text-text mt-1">{{ $weeklyTrends->last()['cambios'] ?? 0 }}</p>
        </div>
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <div class="bg-surface rounded-xl shadow-sm border border-border p-5">
            <h2 class="text-sm font-bold text-text mb-3 flex items-center gap-2">
                <i data-lucide="tags" class="w-4 h-4 text-primary"></i>
                Tags más vigilados
            </h2>
            @forelse ($summary['top_tags'] as $tag => $count)
                <div class="flex items-center justify-between py-1.5 border-b border-border/50 last:border-0">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-teal-100 text-primary text-xs font-medium">{{ $tag }}</span>
                    <span class="text-xs text-text-muted">{{ $count }} {{ $count === 1 ? 'pregunta' : 'preguntas' }}</span>
                </div>
            @empty
                <p class="text-sm text-text-muted">Sin tags todavía.</p>
            @endforelse
        </div>

        @if ($weeklyTrends->isNotEmpty())
            <div class="bg-surface rounded-xl shadow-sm border border-border p-5">
                <h2 class="text-sm font-bold text-text mb-3 flex items-center gap-2">
                    <i data-lucide="trending-up" class="w-4 h-4 text-primary"></i>
                    Tendencias (últimas 8 semanas)
                </h2>
                @php $max = max($weeklyTrends->max('creadas'), $weeklyTrends->max('cambios'), 1); @endphp
                <div class="space-y-3">
                    @foreach ($weeklyTrends as $week)
                        <div>
                            <div class="flex justify-between text-xs text-text-muted mb-1">
                                <span class="font-medium text-text">{{ $week['week'] }}</span>
                                <span>{{ $week['creadas'] }} creadas · {{ $week['cambios'] }} cambios</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="flex-1 h-1.5 bg-gray-100 rounded overflow-hidden">
                                    <div class="h-full bg-primary rounded" style="width: {{ $week['creadas'] / $max * 100 }}%"></div>
                                </div>
                                <div class="flex-1 h-1.5 bg-gray-100 rounded overflow-hidden">
                                    <div class="h-full bg-accent rounded" style="width: {{ $week['cambios'] / $max * 100 }}%"></div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="flex items-center gap-4 mt-4 text-xs text-text-muted">
                    <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-primary"></span> Creadas</span>
                    <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-accent"></span> Cambios detectados</span>
                </div>
            </div>
        @endif
    </div>

    {{-- 12.5 — Nota de pie: solución temporal + nota de privacidad del maestro. --}}
    <p class="text-xs text-text-muted mt-6 flex items-start gap-1.5">
        <i data-lucide="info" class="w-3.5 h-3.5 mt-0.5 shrink-0"></i>
        Vista de solo lectura del tenant. El acceso por <span class="font-medium">team_dashboard_access</span> es una solución
        temporal que será reemplazada por un sistema de roles; por ahora se asume que el tenant es un equipo de confianza
        sin subgrupos.
    </p>
</div>
