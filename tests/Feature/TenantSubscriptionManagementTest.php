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
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TenantSubscriptionManagementTest extends TestCase
{
    use RefreshDatabase;

    private function createSuperAdmin(): User
    {
        return User::factory()->create([
            'tenant_id' => null,
            'branch_id' => null,
            'is_super_admin' => true,
        ]);
    }

    private function createUserWithRole(Tenant $tenant, Branch $branch, string $role): User
    {
        app(RolePermissionSeeder::class)->run();
        app(RolePermissionSeeder::class)->seedForTeam($tenant->id);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    public function test_super_admin_can_assign_a_plan_to_a_tenant(): void
    {
        $this->actingAs($this->createSuperAdmin());

        $tenant = Tenant::factory()->create();
        $plan = SubscriptionPlan::factory()->create(['name' => 'Standard']);

        Volt::test('pages.super-admin.tenants')
            ->call('openSubscriptionModal', $tenant->id)
            ->set('plan_id', (string) $plan->id)
            ->set('period_start', now()->toDateString())
            ->set('period_end', now()->addMonth()->toDateString())
            ->call('saveSubscription')
            ->assertDispatched('toast', message: 'Subscription updated.', type: 'success');

        $this->assertDatabaseHas('tenant_subscriptions', [
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);
    }

    public function test_changing_plan_cancels_the_previous_active_subscription(): void
    {
        $this->actingAs($this->createSuperAdmin());

        $tenant = Tenant::factory()->create();
        $oldPlan = SubscriptionPlan::factory()->create(['name' => 'Basic']);
        $newPlan = SubscriptionPlan::factory()->create(['name' => 'Standard']);

        $existing = TenantSubscription::factory()->create([
            'tenant_id' => $tenant->id, 'plan_id' => $oldPlan->id, 'status' => 'active',
        ]);

        Volt::test('pages.super-admin.tenants')
            ->call('openSubscriptionModal', $tenant->id)
            ->set('plan_id', (string) $newPlan->id)
            ->set('period_start', now()->toDateString())
            ->set('period_end', now()->addMonth()->toDateString())
            ->call('saveSubscription');

        $this->assertDatabaseHas('tenant_subscriptions', ['id' => $existing->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('tenant_subscriptions', ['tenant_id' => $tenant->id, 'plan_id' => $newPlan->id, 'status' => 'active']);
    }

    public function test_suspending_a_tenant_blocks_its_users(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->createUserWithRole($tenant, $branch, 'Business Owner');

        $this->actingAs($superAdmin);
        Volt::test('pages.super-admin.tenants')
            ->call('confirmSuspend', $tenant->id)
            ->call('suspend');

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'status' => 'suspended']);

        $this->actingAs($owner);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        CurrentTenant::set($tenant->id);

        $this->get('/dashboard')->assertRedirect(route('billing.renew'));
    }

    public function test_reactivating_a_tenant_restores_access(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $tenant = Tenant::factory()->licensed()->create(['status' => 'suspended']);
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->createUserWithRole($tenant, $branch, 'Business Owner');

        $this->actingAs($superAdmin);
        Volt::test('pages.super-admin.tenants')->call('activate', $tenant->id);

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'status' => 'active']);

        $this->actingAs($owner);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        CurrentTenant::set($tenant->id);

        $this->get('/dashboard')->assertOk();
    }
}
