<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
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
            'shift_id' => Shift::factory(),
            'cashier_id' => User::factory(),
            'customer_id' => null,
            'receipt_number' => $this->faker->unique()->numerify('TEST-2026-######'),
            'subtotal' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 0,
            'status' => 'completed',
            'voided_by' => null,
            'void_reason' => null,
            'void_approved_by' => null,
            'sync_source' => 'online',
            'synced_at' => null,
            'offline_flagged_oversell' => false,
        ];
    }
}
