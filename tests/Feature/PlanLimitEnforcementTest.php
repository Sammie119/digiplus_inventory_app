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

class PlanLimitEnforcementTest extends TestCase
{
    use RefreshDatabase;

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

    private function loginAs(User $user): User
    {
        $this->actingAs($user);
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);
        CurrentTenant::set($user->tenant_id);

        return $user;
    }

    private function subscribeTenant(Tenant $tenant, int $maxBranches, int $maxUsers): void
    {
        $plan = SubscriptionPlan::factory()->create([
            'max_branches' => $maxBranches,
            'max_users' => $maxUsers,
        ]);

        TenantSubscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
    }

    public function test_branch_creation_is_blocked_once_the_plan_limit_is_reached(): void
    {
        $tenant = Tenant::factory()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->subscribeTenant($tenant, maxBranches: 1, maxUsers: 3);
        $this->loginAs($this->createUserWithRole($tenant, $mainBranch, 'Business Owner'));

        Volt::test('pages.branches.index')
            ->call('openCreateModal')
            ->assertHasErrors('limit')
            ->assertDispatched('toast', message: 'Branch limit reached for your current plan.', type: 'error');

        Volt::test('pages.branches.index')
            ->set('name', 'Downtown Branch')
            ->set('code', 'DT1')
            ->call('save')
            ->assertHasErrors('limit');

        $this->assertDatabaseMissing('branches', ['tenant_id' => $tenant->id, 'name' => 'Downtown Branch']);
    }

    public function test_branch_creation_succeeds_when_under_the_plan_limit(): void
    {
        $tenant = Tenant::factory()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->subscribeTenant($tenant, maxBranches: 2, maxUsers: 3);
        $this->loginAs($this->createUserWithRole($tenant, $mainBranch, 'Business Owner'));

        Volt::test('pages.branches.index')
            ->call('openCreateModal')
            ->assertHasNoErrors();

        Volt::test('pages.branches.index')
            ->set('name', 'Downtown Branch')
            ->set('code', 'DT1')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('branches', ['tenant_id' => $tenant->id, 'name' => 'Downtown Branch']);
    }

    public function test_branch_creation_is_blocked_without_an_active_subscription(): void
    {
        $tenant = Tenant::factory()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $mainBranch, 'Business Owner'));

        Volt::test('pages.branches.index')
            ->call('openCreateModal')
            ->assertHasErrors('limit');
    }

    public function test_user_creation_is_blocked_once_the_plan_limit_is_reached(): void
    {
        $tenant = Tenant::factory()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->subscribeTenant($tenant, maxBranches: 3, maxUsers: 1);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $mainBranch, 'Business Owner'));

        Volt::test('pages.users.index')
            ->call('openCreateModal')
            ->assertHasErrors('limit')
            ->assertDispatched('toast', message: 'User limit reached for your current plan.', type: 'error');

        Volt::test('pages.users.index')
            ->set('name', 'New Cashier')
            ->set('email', 'new-cashier@example.com')
            ->set('branch_id', $mainBranch->id)
            ->set('role', 'Cashier')
            ->call('save')
            ->assertHasErrors('limit');

        $this->assertDatabaseMissing('users', ['email' => 'new-cashier@example.com']);
    }

    public function test_user_creation_succeeds_when_under_the_plan_limit(): void
    {
        $tenant = Tenant::factory()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->subscribeTenant($tenant, maxBranches: 3, maxUsers: 2);
        $this->loginAs($this->createUserWithRole($tenant, $mainBranch, 'Business Owner'));

        Volt::test('pages.users.index')
            ->call('openCreateModal')
            ->assertHasNoErrors();

        Volt::test('pages.users.index')
            ->set('name', 'New Cashier')
            ->set('email', 'new-cashier@example.com')
            ->set('branch_id', $mainBranch->id)
            ->set('role', 'Cashier')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'new-cashier@example.com']);
    }

    public function test_user_creation_is_blocked_without_an_active_subscription(): void
    {
        $tenant = Tenant::factory()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $mainBranch, 'Business Owner'));

        Volt::test('pages.users.index')
            ->call('openCreateModal')
            ->assertHasErrors('limit');
    }
}
