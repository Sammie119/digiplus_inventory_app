<?php

use App\Models\Notification;
use App\Support\NotificationPresenter;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component
{
    protected function getListeners(): array
    {
        $userId = Auth::id();

        return $userId
            ? ["echo-private:App.Models.User.{$userId},.notification.created" => 'onNotificationPushed']
            : [];
    }

    public function onNotificationPushed(array $payload): void
    {
        unset($this->unreadCount, $this->recent);

        $this->dispatch('toast', message: $payload['message'], type: $payload['type']);
    }

    #[Computed]
    public function unreadCount(): int
    {
        return Notification::where('user_id', Auth::id())->whereNull('read_at')->count();
    }

    #[Computed]
    public function recent()
    {
        return Notification::where('user_id', Auth::id())->latest()->limit(8)->get();
    }

    public function open(int $id): void
    {
        $notification = Notification::where('user_id', Auth::id())->findOrFail($id);
        $notification->update(['read_at' => now()]);
        unset($this->unreadCount, $this->recent);

        $route = NotificationPresenter::linkRoute($notification);

        if ($route) {
            $this->redirect(route($route), navigate: true);
        }
    }

    public function markAllRead(): void
    {
        Notification::where('user_id', Auth::id())->whereNull('read_at')->update(['read_at' => now()]);
        unset($this->unreadCount, $this->recent);
    }

    public function message(Notification $notification): string
    {
        return NotificationPresenter::message($notification);
    }
}; ?>

<x-dropdown align="right" width="w-80">
    <x-slot name="trigger">
        <button class="relative inline-flex items-center justify-center p-2 rounded-md text-gray-500 hover:text-gray-700 hover:bg-gray-100 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-gray-700 focus:outline-none transition duration-150 ease-in-out">
            <x-icon.bell class="h-5 w-5" />
            @if ($this->unreadCount > 0)
                <span class="absolute -top-0.5 -right-0.5 inline-flex items-center justify-center h-4 min-w-4 px-1 rounded-full bg-red-600 text-white text-[10px] font-semibold leading-none">
                    {{ $this->unreadCount > 9 ? '9+' : $this->unreadCount }}
                </span>
            @endif
        </button>
    </x-slot>

    <x-slot name="content">
        <div class="flex items-center justify-between px-4 py-2 border-b border-gray-100 dark:border-gray-600">
            <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Notifications') }}</span>
            @if ($this->unreadCount > 0)
                <button type="button" wire:click="markAllRead" class="text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">
                    {{ __('Mark all read') }}
                </button>
            @endif
        </div>

        <div class="max-h-80 overflow-y-auto">
            @forelse ($this->recent as $notification)
                <button
                    type="button"
                    wire:click="open({{ $notification->id }})"
                    wire:key="notif-{{ $notification->id }}"
                    class="w-full text-start px-4 py-3 border-b border-gray-100 dark:border-gray-600 last:border-b-0 hover:bg-gray-50 dark:hover:bg-gray-600 {{ $notification->read_at ? '' : 'bg-indigo-50 dark:bg-indigo-900/20' }}"
                >
                    <p class="text-sm text-gray-700 dark:text-gray-200">{{ $this->message($notification) }}</p>
                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ $notification->created_at->diffForHumans() }}</p>
                </button>
            @empty
                <p class="px-4 py-6 text-sm text-gray-500 dark:text-gray-400 text-center">{{ __('No notifications yet.') }}</p>
            @endforelse
        </div>

        <a href="{{ route('notifications.index') }}" wire:navigate class="block px-4 py-2 text-center text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300 border-t border-gray-100 dark:border-gray-600">
            {{ __('View all') }}
        </a>
    </x-slot>
</x-dropdown>
