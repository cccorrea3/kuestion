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
                        {{-- F2 (UX §6.4) — indicador de conexión inactiva en el header. --}}
                        <livewire:repository-status-indicator />
                        <livewire:pending-review-badge />
                        <livewire:notification-badge />

                        {{-- Menú de usuario: settings, equipo (si aplica) y salir, accesibles
                             desde el avatar en el topbar. Alpine nativo (incluido con Livewire). --}}
                        <div class="relative pl-3 ml-3 border-l border-border" x-data="{ open: false }" @keydown.escape.window="open = false">
                            <button type="button" @click="open = !open" @click.away="open = false"
                                aria-haspopup="menu" :aria-expanded="open"
                                class="flex items-center gap-2 rounded-lg px-1.5 py-1 hover:bg-page transition-colors duration-150 cursor-pointer">
                                <span class="flex items-center justify-center w-8 h-8 rounded-full bg-gradient-to-br from-primary/30 to-accent/30 text-xs font-bold text-text ring-1 ring-primary/10">
                                    {{ mb_strtoupper(mb_substr(auth()->user()->name ?: '?', 0, 1)) }}
                                </span>
                                <span class="hidden sm:block text-sm text-text font-medium">{{ auth()->user()->name }}</span>
                                <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-text-muted transition-transform duration-150"
                                    :class="open && '-rotate-180'"></i>
                            </button>

                            <div x-show="open" x-cloak x-transition:enter="transition ease-out duration-100"
                                x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                                x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                                role="menu" @click="open = false"
                                class="absolute right-0 top-full mt-2 w-56 bg-surface rounded-xl shadow-lg ring-1 ring-border border border-border py-1.5 z-50">
                                <div class="px-3 py-2 border-b border-border/60">
                                    <p class="text-sm font-semibold text-text truncate">{{ auth()->user()->name }}</p>
                                    <p class="text-xs text-text-muted truncate">{{ auth()->user()->email }}</p>
                                </div>
                                <a href="{{ route('contribute') }}" wire:navigate role="menuitem"
                                    class="flex items-center gap-2.5 px-3 py-2 text-sm text-text hover:bg-page transition-colors duration-150">
                                    <i data-lucide="book-open" class="w-4 h-4 text-text-muted"></i>
                                    Aportar
                                </a>
                                <a href="{{ route('settings') }}" wire:navigate role="menuitem"
                                    class="flex items-center gap-2.5 px-3 py-2 text-sm text-text hover:bg-page transition-colors duration-150">
                                    <i data-lucide="settings" class="w-4 h-4 text-text-muted"></i>
                                    Configuración
                                </a>
                                @if (auth()->user()->team_dashboard_access === 'readonly')
                                    <a href="{{ route('team.index') }}" wire:navigate role="menuitem"
                                        class="flex items-center gap-2.5 px-3 py-2 text-sm text-text hover:bg-page transition-colors duration-150">
                                        <i data-lucide="users" class="w-4 h-4 text-text-muted"></i>
                                        Panorama del equipo
                                    </a>
                                @endif
                                <div class="my-1 border-t border-border/60"></div>
                                <form action="{{ route('logout') }}" method="POST" class="inline">
                                    @csrf
                                    <button type="submit" role="menuitem"
                                        class="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-text-muted hover:text-danger hover:bg-page transition-colors duration-150 cursor-pointer">
                                        <i data-lucide="log-out" class="w-4 h-4"></i>
                                        Cerrar sesión
                                    </button>
                                </form>
                            </div>
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
