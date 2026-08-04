<?php

use App\Actions\Backups\GenerateDatabaseBackup;
use App\Models\DatabaseBackup;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.super-admin')] class extends Component
{
    public ?int $confirmingDeleteId = null;

    #[Computed]
    public function backups()
    {
        return DatabaseBackup::with('generatedBy')->orderByDesc('id')->get();
    }

    public function generate(GenerateDatabaseBackup $generateDatabaseBackup): void
    {
        $generateDatabaseBackup(Auth::id());

        unset($this->backups);
        $this->dispatch('toast', message: __('Database backup generated.'), type: 'success');
    }

    public function confirmDelete(int $backupId): void
    {
        $this->confirmingDeleteId = $backupId;
    }

    public function delete(): void
    {
        $backup = DatabaseBackup::findOrFail($this->confirmingDeleteId);

        Storage::disk('local')->delete($backup->file_path);
        $backup->delete();

        $this->confirmingDeleteId = null;
        unset($this->backups);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'confirm-delete-database-backup' }))");
        $this->dispatch('toast', message: __('Database backup deleted.'), type: 'success');
    }
}; ?>

<div>
    <x-slot name="header">{{ __('Database Backups') }}</x-slot>

    <div class="py-6">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('A full SQL dump of the entire database, across all tenants. Generated automatically every day; only the 14 most recent are kept.') }}
            </p>

            <div class="flex justify-end">
                <x-primary-button wire:click="generate" wire:loading.attr="disabled">
                    <x-icon.archive class="h-4 w-4 -ml-0.5 mr-1.5" />
                    {{ __('Generate Backup Now') }}
                </x-primary-button>
            </div>

            <div id="database-backups-table" class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Generated') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('By') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Size') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($this->backups as $backup)
                            <tr wire:key="database-backup-{{ $backup->id }}">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $backup->created_at->format('d M Y H:i') }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $backup->generatedBy?->name ?? __('Scheduled') }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ number_format($backup->size_bytes / 1024, 1) }} KB</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-3">
                                    <a
                                        href="{{ route('super-admin.database-backups.download', $backup->id) }}"
                                        class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300"
                                    >{{ __('Download') }}</a>
                                    <button
                                        type="button"
                                        title="{{ __('Delete') }}"
                                        aria-label="{{ __('Delete') }}"
                                        class="inline-flex items-center justify-center p-1.5 rounded-md text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/40"
                                        wire:click="confirmDelete({{ $backup->id }})"
                                        x-on:click="$dispatch('open-modal', 'confirm-delete-database-backup')"
                                    ><x-icon.trash class="h-4 w-4" /></button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No backups yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <x-confirm-modal
        name="confirm-delete-database-backup"
        :title="__('Delete this backup?')"
        confirm-action="delete"
        :confirm-label="__('Delete')"
    >
        {{ __('This cannot be undone.') }}
    </x-confirm-modal>
</div>
