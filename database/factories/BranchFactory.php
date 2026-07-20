<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
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
            'name' => fake()->unique()->city().' Branch',
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'is_main_branch' => false,
        ];
    }

    public function main(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_main_branch' => true,
        ]);
    }
}
