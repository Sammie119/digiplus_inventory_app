<?php

use App\Models\Notification;
use App\Support\NotificationPresenter;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public const TYPE_LABELS = [
        'low_stock' => 'Low Stock',
        'large_discrepancy' => 'Large Discrepancy',
        'daily_summary' => 'Daily Summary',
    ];

    public string $status = '';
    public string $type = '';

    #[Computed]
    public function notifications()
    {
        $query = Notification::where('user_id', Auth::id())->latest();

        if ($this->status === 'unread') {
            $query->whereNull('read_at');
        } elseif ($this->status === 'read') {
            $query->whereNotNull('read_at');
        }

        if ($this->type) {
            $query->where('type', $this->type);
        }

        return $query->get();
    }

    public function open(int $id): void
    {
        $notification = Notification::where('user_id', Auth::id())->findOrFail($id);
        $notification->update(['read_at' => now()]);
        unset($this->notifications);

        $route = NotificationPresenter::linkRoute($notification);

        if ($route) {
            $this->redirect(route($route), navigate: true);
        }
    }

    public function markAllRead(): void
    {
        Notification::where('user_id', Auth::id())->whereNull('read_at')->update(['read_at' => now()]);
        unset($this->notifications);
    }

    public function message(Notification $notification): string
    {
        return NotificationPresenter::message($notification);
    }

    public function typeLabel(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? $type;
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Notifications') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4 flex flex-wrap items-end gap-4">
                <div class="w-40">
                    <x-input-label for="notif-status" :value="__('Status')" />
                    <select wire:model.live="status" id="notif-status" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                        <option value="">{{ __('All') }}</option>
                        <option value="unread">{{ __('Unread') }}</option>
                        <option value="read">{{ __('Read') }}</option>
                    </select>
                </div>
                <div class="w-48">
                    <x-input-label for="notif-type" :value="__('Type')" />
                    <select wire:model.live="type" id="notif-type" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                        <option value="">{{ __('All Types') }}</option>
                        @foreach (self::TYPE_LABELS as $value => $label)
                            <option value="{{ $value }}">{{ __($label) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="ms-auto">
                    <x-secondary-button type="button" wire:click="markAllRead">
                        {{ __('Mark all read') }}
                    </x-secondary-button>
                </div>
            </div>

            <div id="notifications-list" class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($this->notifications as $notification)
                    <button
                        type="button"
                        wire:click="open({{ $notification->id }})"
                        wire:key="notif-row-{{ $notification->id }}"
                        class="w-full text-start px-6 py-4 flex items-start justify-between gap-4 hover:bg-gray-50 dark:hover:bg-gray-700/40 {{ $notification->read_at ? '' : 'bg-indigo-50 dark:bg-indigo-900/20' }}"
                    >
                        <div>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                @if ($notification->type === 'low_stock') bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400
                                @elseif ($notification->type === 'large_discrepancy') bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-400
                                @else bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300
                                @endif
                            ">
                                {{ __($this->typeLabel($notification->type)) }}
                            </span>
                            <p class="mt-2 text-sm text-gray-700 dark:text-gray-200">{{ $this->message($notification) }}</p>
                            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ $notification->created_at->format('d M Y, H:i') }}</p>
                        </div>
                        @if (! $notification->read_at)
                            <span class="mt-1 h-2 w-2 rounded-full bg-indigo-600 shrink-0"></span>
                        @endif
                    </button>
                @empty
                    <p class="px-6 py-8 text-sm text-gray-500 dark:text-gray-400 text-center">{{ __('No notifications yet.') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
