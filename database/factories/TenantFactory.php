<?php

namespace Database\Factories;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'subdomain' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'currency' => 'GHS',
            'status' => 'trial',
        ];
    }

    /**
     * Attach a current, active subscription so the tenant isn't blocked by
     * App\Http\Middleware\EnsureTenantActive. Opt-in only -- tests that
     * manage their own TenantSubscription rows should not chain this.
     *
     * Uses generous branch/user/transaction limits rather than
     * SubscriptionPlanFactory's Basic-tier defaults (max_branches: 1) --
     * this is a general-purpose "just make this tenant licensed" fixture,
     * not a plan-limit test, and shouldn't accidentally cap tests that
     * create more than one branch/user.
     */
    public function licensed(): static
    {
        return $this->afterCreating(function (Tenant $tenant) {
            TenantSubscription::factory()->create([
                'tenant_id' => $tenant->id,
                'plan_id' => SubscriptionPlan::factory()->create([
                    'is_trial' => false,
                    'max_branches' => 999,
                    'max_users' => 999,
                    'max_transactions_per_month' => 999999,
                ]),
                'status' => 'active',
                'period_start' => now()->subDays(5)->toDateString(),
                'period_end' => now()->addMonth()->toDateString(),
            ]);
        });
    }
}
