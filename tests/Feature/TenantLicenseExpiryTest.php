<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TenantLicenseExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function createOwner(Tenant $tenant, Branch $branch): User
    {
        app(RolePermissionSeeder::class)->run();
        app(RolePermissionSeeder::class)->seedForTeam($tenant->id);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
        ]);
        $user->assignRole('Business Owner');

        return $user;
    }

    private function loginAs(User $user): void
    {
        $this->actingAs($user);
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);
        CurrentTenant::set($user->tenant_id);
    }

    private function subscribeTenant(Tenant $tenant, string $periodEnd): void
    {
        $plan = SubscriptionPlan::factory()->create();

        TenantSubscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => $periodEnd,
            'status' => 'active',
        ]);
    }

    public function test_tenant_with_a_lapsed_subscription_is_blocked(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->subscribeTenant($tenant, now()->subDay()->toDateString());
        $owner = $this->createOwner($tenant, $branch);

        $this->actingAs($owner)->get('/dashboard')->assertRedirect(route('billing.renew'));
        $this->actingAs($owner)->get('/pos')->assertRedirect(route('billing.renew'));
    }

    public function test_tenant_on_the_expiry_day_itself_is_not_yet_blocked(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->subscribeTenant($tenant, now()->toDateString());
        $owner = $this->createOwner($tenant, $branch);

        $this->actingAs($owner)->get('/dashboard')->assertOk();
    }

    public function test_tenant_with_a_current_subscription_is_not_blocked(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->subscribeTenant($tenant, now()->addDays(10)->toDateString());
        $owner = $this->createOwner($tenant, $branch);

        $this->actingAs($owner)->get('/dashboard')->assertOk();
    }

    public function test_tenant_with_no_subscription_at_all_is_blocked(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->createOwner($tenant, $branch);

        $this->actingAs($owner)->get('/dashboard')->assertRedirect(route('billing.renew'));
    }

    public function test_suspended_tenant_is_still_blocked_regardless_of_subscription(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'suspended']);
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->subscribeTenant($tenant, now()->addDays(10)->toDateString());
        $owner = $this->createOwner($tenant, $branch);

        $this->actingAs($owner)->get('/dashboard')->assertRedirect(route('billing.renew'));
    }
}
