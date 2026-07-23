<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleItem>
 */
class SaleItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = $this->faker->randomFloat(3, 1, 10);
        $unitPrice = $this->faker->randomFloat(2, 1, 50);

        return [
            'sale_id' => Sale::factory(),
            'item_id' => Item::factory(),
            'batch_id' => null,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount_amount' => 0,
            'line_total' => round($quantity * $unitPrice, 2),
            'cost_at_sale' => $this->faker->randomFloat(2, 1, 30),
        ];
    }
}
