<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
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

        <title>{{ config('app.name', 'DigiPlus Inventory Solutions') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Styles -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="antialiased font-sans bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100">
        <div class="max-w-7xl mx-auto px-6 lg:px-8">
            <header class="flex items-center justify-between gap-2 py-6">
                <a href="/" class="flex items-center gap-2 min-w-0 shrink-0">
                    <x-application-logo class="h-9 w-auto fill-current text-gray-800 dark:text-gray-100 shrink-0" />
                    <span class="hidden sm:inline font-semibold text-gray-800 dark:text-gray-100 truncate">{{ config('app.name', 'DigiPlus Inventory Solutions') }}</span>
                </a>

                @if (Route::has('login'))
                    <livewire:welcome.navigation />
                @endif
            </header>
        </div>

        <!-- Hero -->
        <section class="max-w-7xl mx-auto px-6 lg:px-8 py-16 sm:py-24 text-center">
            <h1 class="text-4xl sm:text-5xl font-bold tracking-tight text-gray-900 dark:text-white">
                Run your retail business from one place
            </h1>

            <p class="mt-6 max-w-2xl mx-auto text-lg text-gray-600 dark:text-gray-400">
                DigiPlus Inventory Solutions is a cloud-based platform for shop and store
                owners to manage inventory, track sales, and generate operational reports
                in real time &mdash; whether you run a single shop or a growing
                multi-branch chain.
            </p>

            <div class="mt-10 flex items-center justify-center gap-4">
                @guest
                    <a
                        href="{{ route('register') }}"
                        class="inline-flex items-center px-6 py-3 bg-indigo-600 dark:bg-indigo-500 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-indigo-500 dark:hover:bg-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900 transition ease-in-out duration-150"
                    >
                        Get Started
                    </a>

                    <a
                        href="{{ route('login') }}"
                        class="inline-flex items-center px-6 py-3 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-md font-semibold text-sm text-gray-700 dark:text-gray-200 uppercase tracking-widest shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900 transition ease-in-out duration-150"
                    >
                        Log In
                    </a>
                @else
                    <a
                        href="{{ route('dashboard') }}"
                        class="inline-flex items-center px-6 py-3 bg-indigo-600 dark:bg-indigo-500 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-indigo-500 dark:hover:bg-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900 transition ease-in-out duration-150"
                    >
                        Go to Dashboard
                    </a>
                @endguest
            </div>
        </section>

        <!-- Feature grid -->
        <section class="max-w-7xl mx-auto px-6 lg:px-8 py-16 sm:py-24">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">
                <div class="p-6 rounded-lg border border-gray-200 dark:border-gray-700">
                    <div class="flex size-10 items-center justify-center rounded-full bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400">
                        <x-icon.box class="h-5 w-5" />
                    </div>
                    <h3 class="mt-4 text-lg font-semibold text-gray-900 dark:text-white">Stock Management</h3>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        Real-time stock levels per item and per branch, with barcode
                        scanning, batch and expiry tracking, and stock transfers between
                        locations.
                    </p>
                </div>

                <div class="p-6 rounded-lg border border-gray-200 dark:border-gray-700">
                    <div class="flex size-10 items-center justify-center rounded-full bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400">
                        <x-icon.shopping-cart class="h-5 w-5" />
                    </div>
                    <h3 class="mt-4 text-lg font-semibold text-gray-900 dark:text-white">Point of Sale</h3>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        A fast POS for recording sales &mdash; cash, mobile money, card,
                        or credit &mdash; with shift and cash-drawer reconciliation built
                        in.
                    </p>
                </div>

                <div class="p-6 rounded-lg border border-gray-200 dark:border-gray-700">
                    <div class="flex size-10 items-center justify-center rounded-full bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400">
                        <x-icon.building class="h-5 w-5" />
                    </div>
                    <h3 class="mt-4 text-lg font-semibold text-gray-900 dark:text-white">Multi-Branch Support</h3>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        Manage a single shop or a growing chain, with branch-level and
                        consolidated views across your whole business.
                    </p>
                </div>

                <div class="p-6 rounded-lg border border-gray-200 dark:border-gray-700">
                    <div class="flex size-10 items-center justify-center rounded-full bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400">
                        <x-icon.chart-bar class="h-5 w-5" />
                    </div>
                    <h3 class="mt-4 text-lg font-semibold text-gray-900 dark:text-white">Reports & Analytics</h3>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        Sales and stock reports by day, branch, cashier, or item, with
                        revenue, cost, and margin breakdowns you can export.
                    </p>
                </div>

                <div class="p-6 rounded-lg border border-gray-200 dark:border-gray-700">
                    <div class="flex size-10 items-center justify-center rounded-full bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400">
                        <x-icon.users class="h-5 w-5" />
                    </div>
                    <h3 class="mt-4 text-lg font-semibold text-gray-900 dark:text-white">Role-Based Access</h3>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        Give owners, branch managers, cashiers, and store keepers exactly
                        the access they need &mdash; nothing more.
                    </p>
                </div>

                <div class="p-6 rounded-lg border border-gray-200 dark:border-gray-700">
                    <div class="flex size-10 items-center justify-center rounded-full bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400">
                        <x-icon.alert-circle class="h-5 w-5" />
                    </div>
                    <h3 class="mt-4 text-lg font-semibold text-gray-900 dark:text-white">Low-Stock Alerts</h3>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        Get notified before you run out, with reorder levels you can set
                        per item and override per branch.
                    </p>
                </div>
            </div>
        </section>

        @guest
            <!-- CTA banner -->
            <section class="bg-indigo-600 dark:bg-indigo-700">
                <div class="max-w-7xl mx-auto px-6 lg:px-8 py-16 text-center">
                    <h2 class="text-2xl sm:text-3xl font-bold text-white">
                        Ready to get your inventory under control?
                    </h2>
                    <p class="mt-3 text-indigo-100">
                        Create your business account and start tracking stock and sales
                        today.
                    </p>
                    <a
                        href="{{ route('register') }}"
                        class="mt-8 inline-flex items-center px-6 py-3 bg-white border border-transparent rounded-md font-semibold text-sm text-indigo-600 uppercase tracking-widest hover:bg-indigo-50 focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-indigo-600 transition ease-in-out duration-150"
                    >
                        Get Started
                    </a>
                </div>
            </section>
        @endguest

        <footer class="max-w-7xl mx-auto px-6 lg:px-8 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
            &copy; {{ date('Y') }} {{ config('app.name', 'DigiPlus Inventory Solutions') }}
        </footer>
    </body>
</html>
