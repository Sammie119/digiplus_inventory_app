<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
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
            'product_id' => Product::factory(),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####')),
            'barcode' => null,
            'variant_attributes' => null,
            'base_unit_id' => UnitOfMeasure::factory(),
            'purchase_unit_id' => null,
            'purchase_unit_factor' => null,
            'cost_price' => fake()->randomFloat(2, 1, 50),
            'selling_price' => fake()->randomFloat(2, 51, 100),
            'reorder_level' => 5,
            'track_batches' => false,
        ];
    }
}
