<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
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
            'branch_id' => Branch::factory(),
            'user_id' => User::factory(),
            'type' => 'low_stock',
            'payload' => ['sku' => $this->faker->bothify('SKU-####')],
            'read_at' => null,
        ];
    }
}
