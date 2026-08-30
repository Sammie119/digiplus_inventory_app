<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BranchManagementTest extends TestCase
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

    public function test_business_owner_can_view_branches_index(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->createUserWithRole($tenant, $branch, 'Business Owner');

        $this->actingAs($owner)
            ->get('/branches')
            ->assertOk()
            ->assertSeeVolt('pages.branches.index');
    }

    public function test_cashier_cannot_view_branches_index(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->createUserWithRole($tenant, $branch, 'Cashier');

        $this->actingAs($cashier)->get('/branches')->assertForbidden();
    }

    public function test_business_owner_can_create_branch(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $mainBranch, 'Business Owner'));

        Volt::test('pages.branches.index')
            ->set('name', 'Downtown Branch')
            ->set('code', 'dt1')
            ->call('save')
            ->assertDispatched('toast', message: 'Branch created.', type: 'success');

        $this->assertDatabaseHas('branches', [
            'tenant_id' => $tenant->id,
            'name' => 'Downtown Branch',
            'code' => 'DT1',
        ]);
    }

    public function test_deactivating_main_branch_is_blocked(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $mainBranch, 'Business Owner'));

        Volt::test('pages.branches.index')
            ->call('confirmDeactivate', $mainBranch->id)
            ->call('deactivate');

        $this->assertDatabaseHas('branches', ['id' => $mainBranch->id, 'deleted_at' => null]);
    }

    public function test_deactivating_branch_with_users_is_blocked(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $secondBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $mainBranch, 'Business Owner'));
        $this->createUserWithRole($tenant, $secondBranch, 'Cashier');

        Volt::test('pages.branches.index')
            ->call('confirmDeactivate', $secondBranch->id)
            ->call('deactivate');

        $this->assertDatabaseHas('branches', ['id' => $secondBranch->id, 'deleted_at' => null]);
    }

    public function test_branch_from_another_tenant_is_not_reachable(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $mainBranch, 'Business Owner'));

        $otherTenant = Tenant::factory()->licensed()->create();
        $otherBranch = Branch::factory()->main()->create(['tenant_id' => $otherTenant->id]);

        $this->expectException(ModelNotFoundException::class);

        Volt::test('pages.branches.index')->call('editBranch', $otherBranch->id);
    }
}
