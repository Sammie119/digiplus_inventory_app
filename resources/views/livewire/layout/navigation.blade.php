<div x-data="{ open: false }">
    <!-- Mobile top bar -->
    <div class="lg:hidden flex items-center justify-between px-4 py-3 bg-white dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700">
        <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2">
            <x-application-logo class="h-8 w-auto fill-current text-gray-800 dark:text-gray-100" />
            <span class="font-semibold text-gray-800 dark:text-gray-100">{{ config('app.name') }}</span>
        </a>

        <button @click="open = true" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 dark:text-gray-500 dark:hover:text-gray-300 dark:hover:bg-gray-700 focus:outline-none transition duration-150 ease-in-out">
            <x-icon.menu />
        </button>
    </div>

    <!-- Mobile backdrop -->
    <div
        x-show="open"
        x-transition:enter="transition-opacity ease-linear duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition-opacity ease-linear duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-on:click="open = false"
        class="fixed inset-0 bg-gray-900/50 z-30 lg:hidden"
        style="display: none;"
    ></div>

    <!-- Sidebar -->
    <aside
        :class="open ? 'translate-x-0' : '-translate-x-full'"
        class="fixed inset-y-0 left-0 z-40 w-64 h-screen flex flex-col bg-white dark:bg-gray-800 border-r border-gray-100 dark:border-gray-700 transform transition-transform duration-200 ease-in-out lg:translate-x-0 lg:static lg:z-auto"
    >
        <div class="h-16 flex items-center justify-between px-4 shrink-0 border-b border-gray-100 dark:border-gray-700">
            <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2">
                <x-application-logo class="h-8 w-auto fill-current text-gray-800 dark:text-gray-100" />
                <span class="font-semibold text-gray-800 dark:text-gray-100">{{ config('app.name') }}</span>
            </a>

            <button @click="open = false" class="lg:hidden text-gray-400 hover:text-gray-500 dark:text-gray-500 dark:hover:text-gray-300">
                <x-icon.x-mark class="h-5 w-5" />
            </button>
        </div>

        <nav x-on:click="open = false" class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
            <x-sidebar-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" wire:navigate>
                <x-slot:icon><x-icon.home /></x-slot:icon>
                {{ __('Dashboard') }}
            </x-sidebar-link>

            @can('sales.create')
                <x-sidebar-link :href="route('pos.index')" :active="request()->routeIs('pos.index')" wire:navigate>
                    <x-slot:icon><x-icon.shopping-cart /></x-slot:icon>
                    {{ __('Point of Sale') }}
                </x-sidebar-link>
            @endcan

            @can('sales.view')
                <x-sidebar-link :href="route('sales.index')" :active="request()->routeIs('sales.*')" wire:navigate>
                    <x-slot:icon><x-icon.receipt /></x-slot:icon>
                    {{ __('Sales') }}
                </x-sidebar-link>
            @endcan

            @can('sales.view')
                <x-sidebar-link :href="route('customers.index')" :active="request()->routeIs('customers.*')" wire:navigate>
                    <x-slot:icon><x-icon.id-card /></x-slot:icon>
                    {{ __('Customers') }}
                </x-sidebar-link>
            @endcan

            @can('reports.view')
                <x-sidebar-link :href="route('reports.sales')" :active="request()->routeIs('reports.sales')" wire:navigate>
                    <x-slot:icon><x-icon.trending-up /></x-slot:icon>
                    {{ __('Sales Report') }}
                </x-sidebar-link>
            @endcan

            @can('reports.view')
                <x-sidebar-link :href="route('reports.stock')" :active="request()->routeIs('reports.stock')" wire:navigate>
                    <x-slot:icon><x-icon.archive /></x-slot:icon>
                    {{ __('Stock Report') }}
                </x-sidebar-link>
            @endcan

            @can('reports.view')
                <x-sidebar-link :href="route('reports.shifts')" :active="request()->routeIs('reports.shifts')" wire:navigate>
                    <x-slot:icon><x-icon.clock /></x-slot:icon>
                    {{ __('Shift Report') }}
                </x-sidebar-link>
            @endcan

            @can('reports.view')
                @if (auth()->user()->hasRole(['Business Owner', 'Branch Manager']))
                    <x-sidebar-link :href="route('reports.scheduled')" :active="request()->routeIs('reports.scheduled')" wire:navigate>
                        <x-slot:icon><x-icon.mail /></x-slot:icon>
                        {{ __('Scheduled Reports') }}
                    </x-sidebar-link>
                @endif
            @endcan

            @can('tenant-settings.manage')
                <x-sidebar-link :href="route('tax-rates.index')" :active="request()->routeIs('tax-rates.index')" wire:navigate>
                    <x-slot:icon><x-icon.percent /></x-slot:icon>
                    {{ __('Tax Rates') }}
                </x-sidebar-link>
            @endcan

            @can('branches.manage')
                <x-sidebar-link :href="route('branches.index')" :active="request()->routeIs('branches.index')" wire:navigate>
                    <x-slot:icon><x-icon.building /></x-slot:icon>
                    {{ __('Branches') }}
                </x-sidebar-link>
            @endcan

            @can('users.manage')
                <x-sidebar-link :href="route('users.index')" :active="request()->routeIs('users.index')" wire:navigate>
                    <x-slot:icon><x-icon.users /></x-slot:icon>
                    {{ __('Users') }}
                </x-sidebar-link>
            @endcan

            @can('audit.view')
                <x-sidebar-link :href="route('audit-log.index')" :active="request()->routeIs('audit-log.*')" wire:navigate>
                    <x-slot:icon><x-icon.shield-check /></x-slot:icon>
                    {{ __('Audit Log') }}
                </x-sidebar-link>
            @endcan

            @can('stock.view')
                <x-sidebar-link :href="route('products.index')" :active="request()->routeIs('products.*', 'categories.*', 'units-of-measure.*')" wire:navigate>
                    <x-slot:icon><x-icon.box /></x-slot:icon>
                    {{ __('Products') }}
                </x-sidebar-link>
            @endcan

            @can('stock.view')
                <x-sidebar-link :href="route('stock-levels.index')" :active="request()->routeIs('stock-levels.*')" wire:navigate>
                    <x-slot:icon><x-icon.chart-bar /></x-slot:icon>
                    {{ __('Stock Levels') }}
                </x-sidebar-link>
            @endcan

            @can('suppliers.view')
                <x-sidebar-link :href="route('suppliers.index')" :active="request()->routeIs('suppliers.*')" wire:navigate>
                    <x-slot:icon><x-icon.truck /></x-slot:icon>
                    {{ __('Suppliers') }}
                </x-sidebar-link>
            @endcan

            @can('stock.view')
                <x-sidebar-link :href="route('purchases.index')" :active="request()->routeIs('purchases.*')" wire:navigate>
                    <x-slot:icon><x-icon.clipboard-list /></x-slot:icon>
                    {{ __('Purchases') }}
                </x-sidebar-link>
            @endcan

            @can('stock.view')
                <x-sidebar-link :href="route('stock-adjustments.index')" :active="request()->routeIs('stock-adjustments.*')" wire:navigate>
                    <x-slot:icon><x-icon.adjustments /></x-slot:icon>
                    {{ __('Stock Adjustments') }}
                </x-sidebar-link>
            @endcan

            @can('stock.view')
                <x-sidebar-link :href="route('stock-transfers.index')" :active="request()->routeIs('stock-transfers.*')" wire:navigate>
                    <x-slot:icon><x-icon.transfer /></x-slot:icon>
                    {{ __('Stock Transfers') }}
                </x-sidebar-link>
            @endcan
        </nav>
    </aside>
</div>
