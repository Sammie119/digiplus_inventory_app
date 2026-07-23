<div
    x-data="{
        toasts: [],
        push(message, type) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, message, type });
            setTimeout(() => this.dismiss(id), 5000);
        },
        dismiss(id) {
            this.toasts = this.toasts.filter(t => t.id !== id);
        },
    }"
    x-on:toast.window="push($event.detail.message, $event.detail.type)"
    id="toast-container"
    class="fixed top-4 right-4 z-[60] w-80 space-y-2"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div
            x-show="true"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-x-4"
            x-transition:enter-end="opacity-100 translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="rounded-md shadow-lg p-4 text-sm flex items-start gap-3 border"
            :class="{
                'bg-amber-50 dark:bg-amber-900/40 border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300': toast.type === 'low_stock',
                'bg-red-50 dark:bg-red-900/40 border-red-200 dark:border-red-800 text-red-800 dark:text-red-300': toast.type === 'large_discrepancy' || toast.type === 'error',
                'bg-green-50 dark:bg-green-900/40 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300': toast.type === 'success',
                'bg-gray-50 dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-800 dark:text-gray-200': !['low_stock', 'large_discrepancy', 'error', 'success'].includes(toast.type),
            }"
        >
            <span class="flex-1" x-text="toast.message"></span>
            <button type="button" x-on:click="dismiss(toast.id)" class="shrink-0 opacity-60 hover:opacity-100">
                <x-icon.x-mark class="h-4 w-4" />
            </button>
        </div>
    </template>
</div>
