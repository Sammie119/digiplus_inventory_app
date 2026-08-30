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
    <body class="font-sans text-gray-900 dark:text-gray-100 antialiased">
        <div class="min-h-screen flex flex-col items-center py-10 px-4 bg-gray-100 dark:bg-gray-900">
            <div class="mb-6">
                <a href="/" wire:navigate>
                    <x-application-logo class="w-16 h-16 fill-current text-gray-500 dark:text-gray-400" />
                </a>
            </div>

            <div class="w-full max-w-3xl">
                {{ $slot }}
            </div>
        </div>

        <x-toast-container />
    </body>
</html>
