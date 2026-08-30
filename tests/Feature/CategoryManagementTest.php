<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
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

    public function test_business_owner_can_view_categories_index(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->createUserWithRole($tenant, $branch, 'Business Owner');

        $this->actingAs($owner)
            ->get('/categories')
            ->assertOk()
            ->assertSeeVolt('pages.categories.index');
    }

    public function test_business_owner_can_create_category_with_parent(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $parent = Category::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Drinks']);

        Volt::test('pages.categories.index')
            ->set('name', 'Sodas')
            ->set('parent_category_id', $parent->id)
            ->call('save');

        $this->assertDatabaseHas('categories', [
            'tenant_id' => $tenant->id,
            'name' => 'Sodas',
            'parent_category_id' => $parent->id,
        ]);
    }

    public function test_cashier_can_view_but_not_create_category(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        $this->get('/categories')->assertOk()->assertDontSee('Add Category');

        Volt::test('pages.categories.index')->call('openCreateModal')->assertForbidden();
    }

    public function test_category_from_another_tenant_is_not_reachable(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $otherTenant = Tenant::factory()->licensed()->create();
        $otherCategory = Category::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->expectException(ModelNotFoundException::class);

        Volt::test('pages.categories.index')->call('editCategory', $otherCategory->id);
    }
}
