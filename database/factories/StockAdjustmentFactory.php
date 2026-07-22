<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Item;
use App\Models\StockAdjustment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockAdjustment>
 */
class StockAdjustmentFactory extends Factory
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
            'item_id' => Item::factory(),
            'batch_id' => null,
            'quantity_delta' => $this->faker->randomFloat(3, -20, 20),
            'reason_code' => $this->faker->randomElement(['damage', 'write_off', 'return_to_supplier', 'count_correction', 'other']),
            'notes' => null,
            'status' => 'pending',
            'requested_by' => User::factory(),
            'approved_by' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
        ]);
    }
}
