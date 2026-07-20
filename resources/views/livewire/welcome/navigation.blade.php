<nav class="flex flex-1 items-center justify-end gap-2">
    <x-theme-toggle />

    @auth
        <a
            href="{{ url('/dashboard') }}"
            class="rounded-md px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-200 ring-1 ring-transparent transition hover:text-indigo-600 dark:hover:text-indigo-400 focus:outline-none focus-visible:ring-indigo-500"
        >
            Dashboard
        </a>
    @else
        <a
            href="{{ route('login') }}"
            class="rounded-md px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-200 ring-1 ring-transparent transition hover:text-indigo-600 dark:hover:text-indigo-400 focus:outline-none focus-visible:ring-indigo-500"
        >
            Log in
        </a>

        @if (Route::has('register'))
            <a
                href="{{ route('register') }}"
                class="inline-flex items-center px-4 py-2 bg-indigo-600 dark:bg-indigo-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 dark:hover:bg-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
            >
                Register
            </a>
        @endif
    @endauth
</nav>
