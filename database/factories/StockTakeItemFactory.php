<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTakeItem>
 */
class StockTakeItemFactory extends Factory
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
            'stock_take_id' => StockTake::factory(),
            'item_id' => Item::factory(),
            'batch_id' => null,
            'system_quantity' => 10,
            'counted_quantity' => 10,
            'variance' => 0,
        ];
    }
}
