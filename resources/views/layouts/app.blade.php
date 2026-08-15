<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Kuestion') }} @isset($title) — {{ $title }} @endisset</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="font-sans antialiased bg-page text-text min-h-screen">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:bg-primary focus:text-white focus:px-4 focus:py-2 focus:rounded-lg">
        Saltar al contenido principal
    </a>

    <header class="sticky top-0 z-40 bg-surface border-b border-border">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <div class="flex items-center justify-between h-16">
                <a href="/" class="flex items-center gap-2 font-bold text-lg text-text">
                    <i data-lucide="brain" class="w-6 h-6 text-primary"></i>
                    Kuestion
                </a>
                <nav class="flex items-center gap-3">
                    @auth
                        <a href="{{ route('tags.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-text-muted hover:text-text transition-colors duration-150">
                            <i data-lucide="tags" class="w-4 h-4"></i>
                            Tags
                        </a>
                        @if (auth()->user()->team_dashboard_access === 'readonly')
                            <a href="{{ route('team.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-text-muted hover:text-text transition-colors duration-150">
                                <i data-lucide="users" class="w-4 h-4"></i>
                                Equipo
                            </a>
                        @endif
                        {{-- F2 (UX §6.4) — indicador de conexión inactiva en el header. --}}
                        <livewire:repository-status-indicator />
                        <livewire:notification-badge />

                        <div class="flex items-center gap-3 pl-3 ml-3 border-l border-border">
                            <span class="flex items-center justify-center w-8 h-8 rounded-full bg-gradient-to-br from-primary/30 to-accent/30 text-xs font-bold text-text ring-1 ring-primary/10 transition-all duration-200 hover:ring-2 hover:ring-primary/40">
                                {{ mb_strtoupper(mb_substr(auth()->user()->name ?: '?', 0, 1)) }}
                            </span>
                            <span class="hidden sm:block text-sm text-text font-medium">{{ auth()->user()->name }}</span>
                            <a href="{{ route('settings') }}" wire:navigate
                                class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-text-muted hover:text-text hover:bg-page transition-colors duration-150 cursor-pointer"
                                title="Configuración">
                                <i data-lucide="settings" class="w-5 h-5"></i>
                            </a>
                            <form action="{{ route('logout') }}" method="POST" class="inline">
                                @csrf
                                <button type="submit"
                                    class="inline-flex items-center gap-1.5 text-sm text-text-muted hover:text-danger transition-colors duration-150 cursor-pointer"
                                    title="Cerrar sesión">
                                    <i data-lucide="log-out" class="w-4 h-4"></i>
                                    <span class="hidden sm:inline">Salir</span>
                                </button>
                            </form>
                        </div>
                    @endauth
                </nav>
            </div>
        </div>
    </header>

    @auth
        <livewire:kuaforia-key-prompt />
    @endauth

    <main id="main-content" class="max-w-4xl mx-auto px-4 sm:px-6 py-6">
        {{ $slot }}
    </main>

    @livewireScripts
    {{-- Bloque 2: lucide bundleado local (resources/js/app.js) — sin CDN, sin script inline --}}
</body>
</html>
