<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="color-scheme" content="light dark">

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

        <title>{{ __('Super Admin') }} - {{ config('app.name', 'Laravel') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100 dark:bg-gray-900">
            <header class="bg-white dark:bg-gray-800 shadow">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="h-16 flex items-center justify-between gap-4">
                        <div class="flex items-center gap-6">
                            <a href="{{ route('super-admin.dashboard') }}" wire:navigate class="flex items-center gap-2">
                                <x-application-logo class="h-8 w-auto fill-current text-gray-800 dark:text-gray-100" />
                                <span class="font-semibold text-gray-800 dark:text-gray-100">{{ __('Super Admin') }}</span>
                            </a>

                            <nav class="hidden sm:flex items-center gap-1">
                                <a href="{{ route('super-admin.dashboard') }}" wire:navigate class="px-3 py-2 rounded-md text-sm font-medium {{ request()->routeIs('super-admin.dashboard') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100' }}">
                                    {{ __('Dashboard') }}
                                </a>
                                <a href="{{ route('super-admin.tenants.index') }}" wire:navigate class="px-3 py-2 rounded-md text-sm font-medium {{ request()->routeIs('super-admin.tenants.*') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100' }}">
                                    {{ __('Tenants') }}
                                </a>
                                <a href="{{ route('super-admin.subscription-plans.index') }}" wire:navigate class="px-3 py-2 rounded-md text-sm font-medium {{ request()->routeIs('super-admin.subscription-plans.*') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100' }}">
                                    {{ __('Subscription Plans') }}
                                </a>
                                <a href="{{ route('super-admin.database-backups.index') }}" wire:navigate class="px-3 py-2 rounded-md text-sm font-medium {{ request()->routeIs('super-admin.database-backups.*') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100' }}">
                                    {{ __('Database Backups') }}
                                </a>
                            </nav>
                        </div>

                        <livewire:layout.user-menu />
                    </div>
                </div>
            </header>

            <main>
                @if (isset($header))
                    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-6">
                        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ $header }}</h2>
                    </div>
                @endif

                {{ $slot }}
            </main>
        </div>

        <x-toast-container />
    </body>
</html>
