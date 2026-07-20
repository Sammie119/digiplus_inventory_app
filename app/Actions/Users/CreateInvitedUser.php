<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class CreateInvitedUser
{
    /**
     * Create a staff user with a random, unusable password and send them a
     * password-reset link (Breeze's existing flow) so they set their own.
     */
    public function __invoke(int $tenantId, int $branchId, string $name, string $email, string $role): User
    {
        $user = DB::transaction(function () use ($tenantId, $branchId, $name, $email, $role) {
            $user = User::create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(Str::password(40)),
            ]);

            $user->assignRole($role);

            return $user;
        });

        Password::sendResetLink(['email' => $user->email]);

        return $user;
    }
}
