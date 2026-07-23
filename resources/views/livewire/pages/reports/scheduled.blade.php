<?php

use App\Models\Branch;
use App\Models\ScheduledReport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    public ?int $scheduleId = null;
    public string $frequency = 'weekly';
    public string $recipientsText = '';
    public ?int $branch_id = null;
    public ?int $confirmingDeleteId = null;

    public function mount(): void
    {
        Gate::authorize('reports.view');

        if (! Auth::user()->hasRole(['Business Owner', 'Branch Manager'])) {
            abort(403);
        }
    }

    private function isOwner(): bool
    {
        return Auth::user()->hasRole('Business Owner');
    }

    #[Computed]
    public function branchOptions()
    {
        $actor = Auth::user();

        if ($this->isOwner()) {
            return Branch::orderBy('name')->get();
        }

        return Branch::where('id', $actor->branch_id)->get();
    }

    #[Computed]
    public function schedules()
    {
        $actor = Auth::user();

        $query = ScheduledReport::with('branch')->where('tenant_id', $actor->tenant_id)->latest();

        if (! $this->isOwner()) {
            $query->where('branch_id', $actor->branch_id);
        }

        return $query->get();
    }

    public function openCreateModal(): void
    {
        $this->reset(['scheduleId', 'recipientsText']);
        $this->frequency = 'weekly';
        $this->branch_id = $this->isOwner() ? null : Auth::user()->branch_id;
        $this->resetErrorBag();
    }

    public function editSchedule(int $scheduleId): void
    {
        $actor = Auth::user();
        $schedule = ScheduledReport::where('tenant_id', $actor->tenant_id)->findOrFail($scheduleId);

        if (! $this->isOwner() && $schedule->branch_id !== $actor->branch_id) {
            abort(403);
        }

        $this->scheduleId = $schedule->id;
        $this->frequency = $schedule->frequency;
        $this->recipientsText = implode("\n", $schedule->recipients);
        $this->branch_id = $schedule->branch_id;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $actor = Auth::user();
        $schedule = $this->scheduleId ? ScheduledReport::where('tenant_id', $actor->tenant_id)->findOrFail($this->scheduleId) : null;

        if ($schedule && ! $this->isOwner() && $schedule->branch_id !== $actor->branch_id) {
            abort(403);
        }

        $recipients = collect(preg_split('/[\r\n,]+/', $this->recipientsText))
            ->map(fn ($email) => trim($email))
            ->filter()
            ->values()
            ->all();

        $validated = $this->validate([
            'frequency' => ['required', Rule::in(self::FREQUENCIES)],
        ]);

        $this->validate([
            'branch_id' => ['nullable', Rule::in($this->branchOptions->pluck('id')->all())],
        ]);

        if (empty($recipients) || collect($recipients)->contains(fn ($email) => ! filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $this->addError('recipientsText', __('Enter one or more valid email addresses, one per line.'));

            return;
        }

        $branchId = $this->isOwner() ? $this->branch_id : $actor->branch_id;

        if ($schedule) {
            $schedule->update([
                'frequency' => $validated['frequency'],
                'recipients' => $recipients,
                'branch_id' => $branchId,
            ]);
        } else {
            ScheduledReport::create([
                'tenant_id' => $actor->tenant_id,
                'branch_id' => $branchId,
                'report_type' => 'sales',
                'frequency' => $validated['frequency'],
                'recipients' => $recipients,
            ]);
        }

        $this->dispatch('toast', message: $schedule ? __('Schedule updated.') : __('Schedule created.'), type: 'success');

        $this->reset(['scheduleId', 'recipientsText']);
        unset($this->schedules);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'schedule-form' }))");
    }

    public function confirmDelete(int $scheduleId): void
    {
        $this->confirmingDeleteId = $scheduleId;
    }

    public function delete(): void
    {
        $actor = Auth::user();
        $schedule = ScheduledReport::where('tenant_id', $actor->tenant_id)->findOrFail($this->confirmingDeleteId);

        if (! $this->isOwner() && $schedule->branch_id !== $actor->branch_id) {
            abort(403);
        }

        $schedule->delete();

        $this->confirmingDeleteId = null;
        unset($this->schedules);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'confirm-delete-schedule' }))");
        $this->dispatch('toast', message: __('Schedule deleted.'), type: 'success');
    }

    public function branchLabel(?int $branchId): string
    {
        if (! $branchId) {
            return __('All Branches');
        }

        return $this->branchOptions->firstWhere('id', $branchId)?->name ?? __('All Branches');
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Scheduled Reports') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('Automatically email the Sales Report on a recurring schedule.') }}
            </p>

            <div class="flex justify-end">
                <x-primary-button wire:click="openCreateModal" x-on:click="$dispatch('open-modal', 'schedule-form')">
                    <x-icon.plus class="h-4 w-4 -ml-0.5 mr-1.5" />
                    {{ __('New Schedule') }}
                </x-primary-button>
            </div>

            <div id="schedules-table" class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Branch') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Frequency') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Recipients') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Last Sent') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($this->schedules as $schedule)
                            <tr wire:key="schedule-{{ $schedule->id }}">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $schedule->branch?->name ?? __('All Branches') }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ ucfirst($schedule->frequency) }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ implode(', ', $schedule->recipients) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $schedule->last_sent_at?->format('d M Y, H:i') ?? __('Never') }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-1">
                                    <button
                                        type="button"
                                        title="{{ __('Edit') }}"
                                        aria-label="{{ __('Edit') }}"
                                        class="inline-flex items-center justify-center p-1.5 rounded-md text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/40"
                                        wire:click="editSchedule({{ $schedule->id }})"
                                        x-on:click="$dispatch('open-modal', 'schedule-form')"
                                    ><x-icon.pencil class="h-4 w-4" /></button>
                                    <button
                                        type="button"
                                        title="{{ __('Delete') }}"
                                        aria-label="{{ __('Delete') }}"
                                        class="inline-flex items-center justify-center p-1.5 rounded-md text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/40"
                                        wire:click="confirmDelete({{ $schedule->id }})"
                                        x-on:click="$dispatch('open-modal', 'confirm-delete-schedule')"
                                    ><x-icon.trash class="h-4 w-4" /></button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No scheduled reports yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <x-modal name="schedule-form" focusable>
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ $scheduleId ? __('Edit Schedule') : __('New Schedule') }}
            </h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Sends the Sales Report as a PDF.') }}</p>

            @if ($this->isOwner())
                <div class="mt-4">
                    <x-input-label for="schedule-branch" :value="__('Branch')" />
                    <select wire:model="branch_id" id="schedule-branch" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                        <option value="">{{ __('All Branches') }}</option>
                        @foreach ($this->branchOptions as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('branch_id')" class="mt-2" />
                </div>
            @endif

            <div class="mt-4">
                <x-input-label for="schedule-frequency" :value="__('Frequency')" />
                <select wire:model="frequency" id="schedule-frequency" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                    @foreach (self::FREQUENCIES as $option)
                        <option value="{{ $option }}">{{ ucfirst($option) }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('frequency')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="schedule-recipients" :value="__('Recipients')" />
                <textarea wire:model="recipientsText" id="schedule-recipients" rows="3" placeholder="{{ __('One email per line') }}" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full"></textarea>
                <x-input-error :messages="$errors->get('recipientsText')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-confirm-modal
        name="confirm-delete-schedule"
        :title="__('Delete this schedule?')"
        confirm-action="delete"
        :confirm-label="__('Delete')"
    >
        {{ __('This scheduled report will no longer be sent.') }}
    </x-confirm-modal>
</div>
