<?php

namespace Database\Factories;

use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubscriptionPayment>
 */
class SubscriptionPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'plan_id' => SubscriptionPlan::factory(),
            'initiated_by_user_id' => User::factory(),
            'reference' => 'DIGIPLUS-'.Str::random(24),
            'amount' => 29.00,
            'currency' => 'GHS',
            'status' => 'pending',
        ];
    }
}
