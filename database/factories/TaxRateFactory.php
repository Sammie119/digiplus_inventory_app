<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxRate>
 */
class TaxRateFactory extends Factory
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
            'name' => 'VAT',
            'rate_percentage' => 15,
            'applies_to' => 'all',
            'category_id' => null,
        ];
    }

    public function forCategory(int $categoryId): static
    {
        return $this->state(fn (array $attributes) => [
            'applies_to' => 'category',
            'category_id' => $categoryId,
        ]);
    }
}
