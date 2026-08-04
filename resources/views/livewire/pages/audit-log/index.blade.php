<?php

use App\Models\AuditLog;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public const MODEL_LABELS = [
        Sale::class => 'Sale',
        Purchase::class => 'Purchase',
        StockAdjustment::class => 'Stock Adjustment',
        StockTransfer::class => 'Stock Transfer',
        User::class => 'User',
    ];

    public const ACTIONS = ['created', 'updated', 'deleted'];

    public string $from = '';
    public string $to = '';
    public string $action = '';
    public string $model_type = '';
    public ?int $user_id = null;

    public function mount(): void
    {
        Gate::authorize('audit.view');

        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->endOfMonth()->toDateString();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to', 'action', 'model_type', 'user_id'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function userOptions()
    {
        return User::where('tenant_id', Auth::user()->tenant_id)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function logs()
    {
        $query = AuditLog::with('user')
            ->whereDate('created_at', '>=', $this->from ?: today())
            ->whereDate('created_at', '<=', $this->to ?: today())
            ->orderByDesc('id');

        if ($this->action) {
            $query->where('action', $this->action);
        }

        if ($this->model_type) {
            $query->where('auditable_type', $this->model_type);
        }

        if ($this->user_id) {
            $query->where('user_id', $this->user_id);
        }

        return $query->paginate(20);
    }

    public function modelLabel(string $class): string
    {
        return self::MODEL_LABELS[$class] ?? class_basename($class);
    }

    public function formatValue(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? __('Yes') : __('No');
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Audit Log') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4 flex flex-wrap items-end gap-4">
                <div>
                    <x-input-label for="log-from" :value="__('From')" />
                    <x-text-input wire:model.live="from" id="log-from" class="block mt-1" type="date" />
                </div>
                <div>
                    <x-input-label for="log-to" :value="__('To')" />
                    <x-text-input wire:model.live="to" id="log-to" class="block mt-1" type="date" />
                </div>
                <div class="w-40">
                    <x-input-label for="log-action" :value="__('Action')" />
                    <select wire:model.live="action" id="log-action" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                        <option value="">{{ __('All Actions') }}</option>
                        @foreach (self::ACTIONS as $option)
                            <option value="{{ $option }}">{{ ucfirst($option) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-48">
                    <x-input-label for="log-model" :value="__('Model')" />
                    <select wire:model.live="model_type" id="log-model" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                        <option value="">{{ __('All Models') }}</option>
                        @foreach (self::MODEL_LABELS as $class => $label)
                            <option value="{{ $class }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-48">
                    <x-input-label for="log-user" :value="__('User')" />
                    <select wire:model.live="user_id" id="log-user" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                        <option value="">{{ __('All Users') }}</option>
                        @foreach ($this->userOptions as $user)
                            <option value="{{ $user->id }}">{{ $user->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table id="audit-log-table" class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Date') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('User') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Action') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Model') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($this->logs as $log)
                            <tr wire:key="log-{{ $log->id }}">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $log->created_at->format('d M Y, H:i:s') }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $log->user->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                        @if ($log->action === 'created') bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-400
                                        @elseif ($log->action === 'deleted') bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-400
                                        @else bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400
                                        @endif
                                    ">
                                        {{ ucfirst($log->action) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $this->modelLabel($log->auditable_type) }} #{{ $log->auditable_id }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    <button type="button" x-on:click="$dispatch('open-modal', 'log-view-{{ $log->id }}')" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('View Changes') }}</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No activity in this range.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $this->logs->links() }}
        </div>
    </div>

    @foreach ($this->logs as $log)
        <x-modal name="log-view-{{ $log->id }}" focusable max-width="lg">
            <div class="p-6 space-y-4 text-sm">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                    {{ $this->modelLabel($log->auditable_type) }} #{{ $log->auditable_id }} &mdash; {{ ucfirst($log->action) }}
                </h2>
                <div class="text-gray-500 dark:text-gray-400">
                    {{ $log->user->name }} &middot; {{ $log->created_at->format('d M Y, H:i:s') }}
                    @if ($log->ip_address)
                        &middot; {{ $log->ip_address }}
                    @endif
                </div>

                @if ($log->old_values || $log->new_values)
                    <div class="overflow-hidden border border-gray-200 dark:border-gray-700 rounded-md">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900/40">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Field') }}</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Before') }}</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('After') }}</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                @foreach (array_unique(array_merge(array_keys($log->old_values ?? []), array_keys($log->new_values ?? []))) as $field)
                                    <tr>
                                        <td class="px-3 py-2 whitespace-nowrap text-gray-900 dark:text-gray-100">{{ $field }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $this->formatValue(data_get($log->old_values, $field)) }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $this->formatValue(data_get($log->new_values, $field)) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div class="flex justify-end pt-2">
                    <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Close') }}</x-secondary-button>
                </div>
            </div>
        </x-modal>
    @endforeach
</div>
