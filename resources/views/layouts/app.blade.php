<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="color-scheme" content="light dark">
        <link rel="icon" type="image/png" href="{{ asset('img/digi-plus-favicon.png') }}">

        <script>
            (function () {
                function applyTheme() {
                    if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                        document.documentElement.classList.add('dark');
                    } else {
                        document.documentElement.classList.remove('dark');
                    }
                }
                applyTheme();
                // wire:navigate morphs <html>'s attributes against the fetched page, which
                // never carries class="dark" server-side, so the class must be reapplied
                // after every client-side navigation, not just on the first paint.
                document.addEventListener('livewire:navigated', applyTheme);
            })();
        </script>

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="h-screen flex flex-col lg:flex-row overflow-hidden bg-gray-100 dark:bg-gray-900">
            <livewire:layout.navigation />

            <div class="flex-1 flex flex-col overflow-y-auto">
                <!-- App Header: page heading (if set) + theme toggle & user menu, pushed to the far right -->
                <header class="bg-white dark:bg-gray-800 shadow shrink-0">
                    <div class="h-16 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between gap-2">
                        <div class="min-w-0 truncate">
                            {{ $header ?? '' }}
                        </div>

                        <div class="flex items-center gap-2">
                            <livewire:layout.notifications />
                            <livewire:layout.user-menu />
                        </div>
                    </div>
                </header>

                <!-- Page Content -->
                <main class="flex-1">
                    {{ $slot }}
                </main>
            </div>
        </div>

        <x-toast-container />
    </body>
</html>
