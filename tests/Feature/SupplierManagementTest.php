<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SupplierManagementTest extends TestCase
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

    public function test_business_owner_can_view_suppliers_index(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->createUserWithRole($tenant, $branch, 'Business Owner');

        $this->actingAs($owner)
            ->get('/suppliers')
            ->assertOk()
            ->assertSeeVolt('pages.suppliers.index');
    }

    public function test_business_owner_can_create_supplier(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        Volt::test('pages.suppliers.index')
            ->set('name', 'Accra Wholesale')
            ->set('phone', '0201234567')
            ->call('save');

        $this->assertDatabaseHas('suppliers', [
            'tenant_id' => $tenant->id,
            'name' => 'Accra Wholesale',
            'phone' => '0201234567',
        ]);
    }

    public function test_branch_manager_can_view_but_not_create_supplier(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Branch Manager'));

        $this->get('/suppliers')->assertOk()->assertDontSee('Add Supplier');

        Volt::test('pages.suppliers.index')->call('openCreateModal')->assertForbidden();
    }

    public function test_cashier_cannot_view_suppliers_index(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->createUserWithRole($tenant, $branch, 'Cashier');

        $this->actingAs($cashier)->get('/suppliers')->assertForbidden();
    }

    public function test_supplier_from_another_tenant_is_not_reachable(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $otherTenant = Tenant::factory()->licensed()->create();
        $otherSupplier = Supplier::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->expectException(ModelNotFoundException::class);

        Volt::test('pages.suppliers.index')->call('editSupplier', $otherSupplier->id);
    }
}
