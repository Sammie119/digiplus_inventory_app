@props(['name', 'title', 'confirmAction', 'confirmLabel' => null])

<x-modal :name="$name" focusable>
    <div class="p-6">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $title }}</h2>

        @if ($slot->isNotEmpty())
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $slot }}</p>
        @endif

        <div class="mt-6 flex justify-end space-x-3">
            <x-secondary-button x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
            <x-danger-button wire:click="{{ $confirmAction }}">{{ $confirmLabel ?? __('Confirm') }}</x-danger-button>
        </div>
    </div>
</x-modal>
