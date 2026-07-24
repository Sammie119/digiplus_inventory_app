@props(['options', 'wireModel', 'placeholder' => 'Search…'])

<div
    x-data="{
        open: false,
        search: '',
        options: @js($options),
        itemId: $wire.entangle(@js($wireModel)).live,
        init() {
            this.syncSearchFromSelection();
            this.$watch('itemId', () => { if (! this.open) this.syncSearchFromSelection(); });
        },
        syncSearchFromSelection() {
            const found = this.options.find(o => o.id == this.itemId);
            this.search = found ? found.label : '';
        },
        get filtered() {
            return this.search === ''
                ? this.options
                : this.options.filter(o => o.label.toLowerCase().includes(this.search.toLowerCase())
                    || (o.code && o.code.toLowerCase() === this.search.toLowerCase()));
        },
        select(option) {
            this.itemId = option.id;
            this.search = option.label;
            this.open = false;
        },
        selectByExactCode() {
            // A hardware barcode scanner types the code then sends Enter —
            // this lets that Enter keystroke select the match immediately,
            // with no mouse click needed.
            const match = this.options.find(o => o.code && o.code.toLowerCase() === this.search.toLowerCase());
            if (match) this.select(match);
        },
    }"
    x-on:click.outside="open = false; syncSearchFromSelection()"
    class="relative mt-1"
>
    <input
        type="text"
        x-model="search"
        x-on:focus="open = true; search = ''"
        x-on:input="open = true"
        x-on:keydown.escape="open = false; syncSearchFromSelection()"
        x-on:keydown.enter.prevent="selectByExactCode()"
        autocomplete="off"
        placeholder="{{ $placeholder }}"
        {{ $attributes->merge(['class' => 'border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block w-full']) }}
    />

    <div
        x-show="open"
        x-transition
        style="display: none;"
        class="absolute z-20 mt-1 w-full max-h-60 overflow-auto bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-md shadow-lg py-1 text-sm"
    >
        <template x-for="option in filtered" :key="option.id">
            <div
                x-on:click="select(option)"
                x-text="option.label"
                class="px-3 py-2 cursor-pointer text-gray-900 dark:text-gray-100 hover:bg-indigo-50 dark:hover:bg-indigo-900/40"
            ></div>
        </template>
        <div x-show="filtered.length === 0" class="px-3 py-2 text-gray-400 dark:text-gray-500">{{ __('No results') }}</div>
    </div>
</div>
