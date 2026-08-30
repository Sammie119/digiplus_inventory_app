<?php

namespace Tests\Feature;

use App\Console\Commands\ExpireTenantSubscriptions;
use App\Models\Branch;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpireTenantSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    private function createOwner(Tenant $tenant, Branch $branch): User
    {
        app(RolePermissionSeeder::class)->run();
        app(RolePermissionSeeder::class)->seedForTeam($tenant->id);

        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
        $owner->assignRole('Business Owner');

        return $owner;
    }

    public function test_lapsed_active_subscriptions_are_flagged_expired(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->createOwner($tenant, $branch);
        $plan = SubscriptionPlan::factory()->create();

        $lapsed = TenantSubscription::factory()->create([
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active',
            'period_end' => now()->subDay()->toDateString(),
        ]);

        $current = TenantSubscription::factory()->create([
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active',
            'period_end' => now()->addDays(10)->toDateString(),
        ]);

        $today = TenantSubscription::factory()->create([
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active',
            'period_end' => now()->toDateString(),
        ]);

        $this->artisan(ExpireTenantSubscriptions::class)->assertSuccessful();

        $this->assertDatabaseHas('tenant_subscriptions', ['id' => $lapsed->id, 'status' => 'expired']);
        $this->assertDatabaseHas('tenant_subscriptions', ['id' => $current->id, 'status' => 'active']);
        $this->assertDatabaseHas('tenant_subscriptions', ['id' => $today->id, 'status' => 'active']);
    }

    public function test_one_tenants_failure_does_not_block_the_next_tenants_expiry(): void
    {
        $brokenTenant = Tenant::factory()->create();
        $brokenBranch = Branch::factory()->main()->create(['tenant_id' => $brokenTenant->id]);
        $this->createOwner($brokenTenant, $brokenBranch);
        $plan = SubscriptionPlan::factory()->create();

        $broken = TenantSubscription::factory()->create([
            'tenant_id' => $brokenTenant->id, 'plan_id' => $plan->id, 'status' => 'active',
            'period_end' => now()->subDay()->toDateString(),
        ]);

        $healthyTenant = Tenant::factory()->create();
        $healthyBranch = Branch::factory()->main()->create(['tenant_id' => $healthyTenant->id]);
        $this->createOwner($healthyTenant, $healthyBranch);

        $healthy = TenantSubscription::factory()->create([
            'tenant_id' => $healthyTenant->id, 'plan_id' => $plan->id, 'status' => 'active',
            'period_end' => now()->subDay()->toDateString(),
        ]);

        TenantSubscription::updating(function (TenantSubscription $subscription) use ($broken) {
            if ($subscription->is($broken)) {
                throw new \RuntimeException('Simulated update failure');
            }
        });

        $this->artisan(ExpireTenantSubscriptions::class)->assertFailed();

        $this->assertDatabaseHas('tenant_subscriptions', ['id' => $healthy->id, 'status' => 'expired']);
        $this->assertDatabaseHas('tenant_subscriptions', ['id' => $broken->id, 'status' => 'active']);
    }
}
