<?php

use App\Actions\Users\CreateInvitedUser;
use App\Models\Branch;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $userId = null;
    public string $name = '';
    public string $email = '';
    public ?int $branch_id = null;
    public string $role = '';
    public ?int $confirmingToggleStatusId = null;

    public function mount(): void
    {
        Gate::authorize('viewAny', User::class);
    }

    #[Computed]
    public function users()
    {
        $actor = Auth::user();

        $query = User::where('tenant_id', $actor->tenant_id)->with('roles');

        if ($actor->hasRole('Branch Manager')) {
            $query->where('branch_id', $actor->branch_id);
        }

        return $query->orderBy('name')->get();
    }

    #[Computed]
    public function branchOptions()
    {
        $actor = Auth::user();

        if ($actor->hasRole('Branch Manager')) {
            return Branch::where('id', $actor->branch_id)->get();
        }

        return Branch::orderBy('name')->get();
    }

    #[Computed]
    public function roleOptions(): array
    {
        return app(UserPolicy::class)->assignableRoles(Auth::user());
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', User::class);

        $actor = Auth::user();
        $this->reset(['userId', 'name', 'email', 'role']);
        $this->branch_id = $actor->hasRole('Branch Manager') ? $actor->branch_id : null;
        $this->resetErrorBag();
    }

    public function editUser(int $userId): void
    {
        $target = User::findOrFail($userId);
        Gate::authorize('update', $target);

        $this->userId = $target->id;
        $this->name = $target->name;
        $this->email = $target->email;
        $this->branch_id = $target->branch_id;
        $this->role = $target->roles->first()?->name ?? '';
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $actor = Auth::user();
        $target = $this->userId ? User::findOrFail($this->userId) : null;

        Gate::authorize($target ? 'update' : 'create', $target ?? User::class);

        $allowedRoles = $this->roleOptions;
        $allowedBranchIds = $this->branchOptions->pluck('id')->all();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($target?->id),
            ],
            'branch_id' => ['required', Rule::in($allowedBranchIds)],
            'role' => ['required', Rule::in($allowedRoles)],
        ]);

        if ($target) {
            $target->update([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'branch_id' => $validated['branch_id'],
            ]);
            $target->syncRoles([$validated['role']]);
        } else {
            app(CreateInvitedUser::class)(
                $actor->tenant_id,
                $validated['branch_id'],
                $validated['name'],
                $validated['email'],
                $validated['role'],
            );
        }

        $this->reset(['userId', 'name', 'email', 'branch_id', 'role']);
        unset($this->users);
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'user-form' }))");
    }

    public function confirmToggleStatus(int $userId): void
    {
        Gate::authorize('update', User::findOrFail($userId));

        $this->confirmingToggleStatusId = $userId;
    }

    #[Computed]
    public function confirmingUser(): ?User
    {
        return $this->confirmingToggleStatusId ? User::find($this->confirmingToggleStatusId) : null;
    }

    public function toggleStatus(): void
    {
        $target = User::findOrFail($this->confirmingToggleStatusId);
        Gate::authorize('update', $target);

        $this->confirmingToggleStatusId = null;
        $this->js("window.dispatchEvent(new CustomEvent('close-modal', { detail: 'confirm-toggle-status' }))");

        $actor = Auth::user();

        if ($target->id === $actor->id) {
            $this->addError('status', 'You cannot deactivate your own account.');

            return;
        }

        if ($target->status === 'active' && $target->hasRole('Business Owner')) {
            $remainingOwners = User::where('tenant_id', $actor->tenant_id)
                ->where('status', 'active')
                ->whereKeyNot($target->id)
                ->role('Business Owner')
                ->count();

            if ($remainingOwners === 0) {
                $this->addError('status', 'A tenant must always have at least one active Business Owner.');

                return;
            }
        }

        $target->update(['status' => $target->status === 'active' ? 'disabled' : 'active']);
        unset($this->users);
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Users') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @error('status')
                <div class="p-4 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 rounded-md text-sm">{{ $message }}</div>
            @enderror

            <div class="flex justify-end">
                <x-primary-button wire:click="openCreateModal" x-on:click="$dispatch('open-modal', 'user-form')">
                    <x-icon.plus class="h-4 w-4 -ml-0.5 mr-1.5" />
                    {{ __('Add User') }}
                </x-primary-button>
            </div>

            <!-- Mobile: stacked cards -->
            <div class="sm:hidden space-y-3">
                @forelse ($this->users as $tenantUser)
                    <div wire:key="user-card-{{ $tenantUser->id }}" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $tenantUser->name }}</div>
                                <div class="text-sm text-gray-500 dark:text-gray-400 truncate">{{ $tenantUser->email }}</div>
                            </div>
                            <div class="flex items-center gap-1 shrink-0">
                                <button
                                    type="button"
                                    title="{{ __('Edit') }}"
                                    aria-label="{{ __('Edit') }}"
                                    class="inline-flex items-center justify-center p-1.5 rounded-md text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/40"
                                    wire:click="editUser({{ $tenantUser->id }})"
                                    x-on:click="$dispatch('open-modal', 'user-form')"
                                ><x-icon.pencil class="h-4 w-4" /></button>
                                <button
                                    type="button"
                                    title="{{ $tenantUser->status === 'active' ? __('Deactivate') : __('Reactivate') }}"
                                    aria-label="{{ $tenantUser->status === 'active' ? __('Deactivate') : __('Reactivate') }}"
                                    class="inline-flex items-center justify-center p-1.5 rounded-md {{ $tenantUser->status === 'active' ? 'text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/40' : 'text-green-600 dark:text-green-400 hover:bg-green-50 dark:hover:bg-green-900/40' }}"
                                    wire:click="confirmToggleStatus({{ $tenantUser->id }})"
                                    x-on:click="$dispatch('open-modal', 'confirm-toggle-status')"
                                >
                                    @if ($tenantUser->status === 'active')
                                        <x-icon.ban class="h-4 w-4" />
                                    @else
                                        <x-icon.check-circle class="h-4 w-4" />
                                    @endif
                                </button>
                            </div>
                        </div>

                        <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('Branch') }}</dt>
                                <dd class="text-gray-900 dark:text-gray-100">{{ $tenantUser->branch?->name }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('Role') }}</dt>
                                <dd class="text-gray-900 dark:text-gray-100">{{ $tenantUser->roles->pluck('name')->join(', ') }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('Status') }}</dt>
                                <dd class="text-gray-900 dark:text-gray-100">{{ ucfirst($tenantUser->status) }}</dd>
                            </div>
                        </dl>
                    </div>
                @empty
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No users yet.') }}</div>
                @endforelse
            </div>

            <!-- Desktop: table -->
            <div class="hidden sm:block bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Name') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Email') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Branch') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Role') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($this->users as $tenantUser)
                            <tr wire:key="user-{{ $tenantUser->id }}">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $tenantUser->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $tenantUser->email }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $tenantUser->branch?->name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $tenantUser->roles->pluck('name')->join(', ') }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ ucfirst($tenantUser->status) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-1">
                                    <button
                                        type="button"
                                        title="{{ __('Edit') }}"
                                        aria-label="{{ __('Edit') }}"
                                        class="inline-flex items-center justify-center p-1.5 rounded-md text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/40 hover:text-indigo-900 dark:hover:text-indigo-300"
                                        wire:click="editUser({{ $tenantUser->id }})"
                                        x-on:click="$dispatch('open-modal', 'user-form')"
                                    ><x-icon.pencil class="h-4 w-4" /></button>
                                    <button
                                        type="button"
                                        title="{{ $tenantUser->status === 'active' ? __('Deactivate') : __('Reactivate') }}"
                                        aria-label="{{ $tenantUser->status === 'active' ? __('Deactivate') : __('Reactivate') }}"
                                        class="inline-flex items-center justify-center p-1.5 rounded-md {{ $tenantUser->status === 'active' ? 'text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/40 hover:text-red-900 dark:hover:text-red-300' : 'text-green-600 dark:text-green-400 hover:bg-green-50 dark:hover:bg-green-900/40 hover:text-green-900 dark:hover:text-green-300' }}"
                                        wire:click="confirmToggleStatus({{ $tenantUser->id }})"
                                        x-on:click="$dispatch('open-modal', 'confirm-toggle-status')"
                                    >
                                        @if ($tenantUser->status === 'active')
                                            <x-icon.ban class="h-4 w-4" />
                                        @else
                                            <x-icon.check-circle class="h-4 w-4" />
                                        @endif
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No users yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <x-modal name="user-form" focusable>
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ $userId ? __('Edit User') : __('Add User') }}
            </h2>

            <div class="mt-4">
                <x-input-label for="user-name" :value="__('Name')" />
                <x-text-input wire:model="name" id="user-name" class="block mt-1 w-full" type="text" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="user-email" :value="__('Email')" />
                <x-text-input wire:model="email" id="user-email" class="block mt-1 w-full" type="email" required />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="user-branch" :value="__('Branch')" />
                <select wire:model="branch_id" id="user-branch" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                    <option value="">{{ __('Select a branch') }}</option>
                    @foreach ($this->branchOptions as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('branch_id')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="user-role" :value="__('Role')" />
                <select wire:model="role" id="user-role" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                    <option value="">{{ __('Select a role') }}</option>
                    @foreach ($this->roleOptions as $roleOption)
                        <option value="{{ $roleOption }}">{{ $roleOption }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('role')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <x-secondary-button x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-confirm-modal
        name="confirm-toggle-status"
        :title="$this->confirmingUser?->status === 'active' ? __('Deactivate this user?') : __('Reactivate this user?')"
        confirm-action="toggleStatus"
        :confirm-label="$this->confirmingUser?->status === 'active' ? __('Deactivate') : __('Reactivate')"
    />
</div>
