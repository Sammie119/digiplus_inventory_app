@props(['label', 'active' => false])

<div x-data="{ open: @js((bool) $active) }">
    <button
        type="button"
        x-on:click.stop="open = ! open"
        class="w-full flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 hover:text-gray-900 dark:hover:text-gray-100 transition duration-150 ease-in-out"
    >
        @isset($icon)
            <span class="shrink-0">{{ $icon }}</span>
        @endisset
        <span class="flex-1 text-left">{{ $label }}</span>
        <x-icon.chevron-down class="h-4 w-4 shrink-0 transition-transform duration-150" x-bind:class="{ 'rotate-180': open }" />
    </button>

    <div x-show="open" x-transition style="display: none;" class="mt-1 ml-4 pl-3 border-l border-gray-200 dark:border-gray-700 space-y-1">
        {{ $slot }}
    </div>
</div>
