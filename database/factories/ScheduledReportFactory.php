<?php

namespace Database\Factories;

use App\Models\ScheduledReport;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledReport>
 */
class ScheduledReportFactory extends Factory
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
            'branch_id' => null,
            'report_type' => 'sales',
            'frequency' => 'weekly',
            'recipients' => [$this->faker->safeEmail()],
            'last_sent_at' => null,
        ];
    }
}
