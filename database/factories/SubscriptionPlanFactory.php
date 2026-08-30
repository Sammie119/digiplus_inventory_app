<?php

namespace Database\Factories;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionPlan>
 */
class SubscriptionPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Basic',
            'max_branches' => 1,
            'max_users' => 3,
            'max_transactions_per_month' => 1000,
            'price' => 29.00,
        ];
    }

    public function trial(): static
    {
        return $this->state(fn () => config('billing.trial_plan'));
    }
}
