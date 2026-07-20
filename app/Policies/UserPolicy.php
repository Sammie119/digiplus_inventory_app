<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Roles a Branch Manager may assign/manage — never Business Owner or
     * Branch Manager itself (SRS §3.1: "Branch-level only").
     */
    public const BRANCH_MANAGER_ASSIGNABLE_ROLES = ['Cashier', 'Store Keeper', 'Auditor'];

    public function viewAny(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function update(User $user, User $target): bool
    {
        if (! $user->can('users.manage') || $user->tenant_id !== $target->tenant_id) {
            return false;
        }

        if ($user->hasRole('Business Owner')) {
            return true;
        }

        if ($user->hasRole('Branch Manager')) {
            return $target->branch_id === $user->branch_id
                && $target->hasAnyRole(self::BRANCH_MANAGER_ASSIGNABLE_ROLES);
        }

        return false;
    }

    /**
     * Roles the acting user is allowed to assign to a new or existing user.
     * Not a standard ability — called directly by the users admin screen to
     * build the role picker and to re-validate the submitted role.
     */
    public function assignableRoles(User $user): array
    {
        if ($user->hasRole('Business Owner')) {
            return ['Business Owner', 'Branch Manager', 'Cashier', 'Store Keeper', 'Auditor'];
        }

        if ($user->hasRole('Branch Manager')) {
            return self::BRANCH_MANAGER_ASSIGNABLE_ROLES;
        }

        return [];
    }
}
