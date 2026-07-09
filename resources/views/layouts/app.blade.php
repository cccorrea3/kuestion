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
                    {{ $header ?? '' }}
                </nav>
            </div>
        </div>
    </header>

    <main id="main-content" class="max-w-4xl mx-auto px-4 sm:px-6 py-6">
        {{ $slot }}
    </main>

    @livewireScripts
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>lucide.createIcons();</script>
</body>
</html>
