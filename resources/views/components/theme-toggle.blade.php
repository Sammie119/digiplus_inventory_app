@props(['label' => false])

<button
    type="button"
    x-data
    x-on:click="
        document.documentElement.classList.toggle('dark');
        localStorage.theme = document.documentElement.classList.contains('dark') ? 'dark' : 'light';
    "
    {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 rounded-md p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-gray-700 focus:outline-none transition duration-150 ease-in-out']) }}
>
    {{-- Sun icon: shown in dark mode (click to go light) --}}
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="hidden dark:block h-5 w-5">
        <path fill-rule="evenodd" d="M10 2a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 10 2ZM10 15a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 10 15ZM10 7a3 3 0 1 0 0 6 3 3 0 0 0 0-6ZM15.657 5.404a.75.75 0 1 0-1.06-1.06l-1.061 1.06a.75.75 0 0 0 1.06 1.06l1.06-1.06ZM6.464 14.596a.75.75 0 1 0-1.06-1.06l-1.06 1.06a.75.75 0 0 0 1.06 1.06l1.06-1.06ZM18 10a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 18 10ZM5 10a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 5 10ZM15.657 14.596l-1.06-1.06a.75.75 0 1 0-1.061 1.06l1.06 1.06a.75.75 0 0 0 1.061-1.06ZM6.464 5.404l-1.06-1.06a.75.75 0 1 0-1.06 1.06l1.06 1.06a.75.75 0 0 0 1.06-1.06Z" clip-rule="evenodd" />
    </svg>

    {{-- Moon icon: shown in light mode (click to go dark) --}}
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="block dark:hidden h-5 w-5">
        <path fill-rule="evenodd" d="M17.293 13.293A8 8 0 0 1 6.707 2.707a8.001 8.001 0 1 0 10.586 10.586Z" clip-rule="evenodd" />
    </svg>

    @if ($label)
        <span class="text-sm font-medium">{{ __('Toggle theme') }}</span>
    @endif
</button>
