<?php

namespace App\Actions\Tenants;

use App\Models\Branch;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegisterTenantOwner
{
    /**
     * Create a new tenant, its main branch, and the owning user, all in one
     * transaction, then seed and assign the Business Owner role.
     */
    public function __invoke(string $businessName, string $name, string $email, string $hashedPassword): User
    {
        return DB::transaction(function () use ($businessName, $name, $email, $hashedPassword) {
            $tenant = Tenant::create([
                'name' => $businessName,
                'subdomain' => $this->uniqueSubdomain($businessName),
                'currency' => 'GHS',
                'status' => 'trial',
            ]);

            $trialPlan = SubscriptionPlan::firstOrCreate(
                ['is_trial' => true],
                config('billing.trial_plan'),
            );

            TenantSubscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $trialPlan->id,
                'period_start' => now()->toDateString(),
                'period_end' => now()->addDays(config('billing.trial_days'))->toDateString(),
                'status' => 'active',
            ]);

            $branch = Branch::create([
                'tenant_id' => $tenant->id,
                'name' => 'Main Branch',
                'code' => 'MAIN',
                'is_main_branch' => true,
            ]);

            $user = User::create([
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'name' => $name,
                'email' => $email,
                'password' => $hashedPassword,
            ]);

            $seeder = app(RolePermissionSeeder::class);
            $seeder->run();
            $seeder->seedForTeam($tenant->id);

            $user->assignRole('Business Owner');

            return $user;
        });
    }

    private function uniqueSubdomain(string $businessName): string
    {
        $base = Str::slug($businessName) ?: 'business';
        $subdomain = $base;

        for ($suffix = 2; Tenant::where('subdomain', $subdomain)->exists(); $suffix++) {
            $subdomain = "{$base}-{$suffix}";
        }

        return $subdomain;
    }
}
