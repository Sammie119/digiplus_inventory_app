<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseItem>
 */
class PurchaseItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_id' => Purchase::factory(),
            'item_id' => Item::factory(),
            'batch_id' => null,
            'quantity' => fake()->randomFloat(3, 1, 50),
            'unit_cost' => fake()->randomFloat(2, 1, 50),
            'purchase_unit_id' => null,
            'purchase_unit_quantity' => null,
        ];
    }
}
