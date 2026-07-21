<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductManagementTest extends TestCase
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

    public function test_business_owner_can_view_products_index(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->createUserWithRole($tenant, $branch, 'Business Owner');

        $this->actingAs($owner)
            ->get('/products')
            ->assertOk()
            ->assertSeeVolt('pages.products.index');
    }

    public function test_business_owner_can_create_product(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $category = Category::factory()->create(['tenant_id' => $tenant->id]);

        Volt::test('pages.products.index')
            ->set('name', 'Instant Noodles')
            ->set('category_id', $category->id)
            ->set('has_variants', false)
            ->call('save');

        $this->assertDatabaseHas('products', [
            'tenant_id' => $tenant->id,
            'name' => 'Instant Noodles',
            'category_id' => $category->id,
        ]);
    }

    public function test_cashier_can_view_but_not_create_product(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        $this->get('/products')->assertOk()->assertDontSee('Add Product');

        Volt::test('pages.products.index')->call('openCreateModal')->assertForbidden();
    }

    public function test_product_from_another_tenant_is_not_reachable(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->createUserWithRole($tenant, $branch, 'Business Owner');

        $otherTenant = Tenant::factory()->create();
        $otherProduct = Product::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->actingAs($owner)
            ->get("/products/{$otherProduct->id}/items")
            ->assertNotFound();
    }

    public function test_business_owner_can_add_item_to_non_variant_product(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'has_variants' => false]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);

        Volt::test('pages.products.items', ['product' => $product])
            ->set('sku', 'NOODLE-1')
            ->set('base_unit_id', $unit->id)
            ->set('cost_price', '5.00')
            ->set('selling_price', '8.00')
            ->set('reorder_level', 10)
            ->call('save');

        $this->assertDatabaseHas('items', [
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'sku' => 'NOODLE-1',
            'variant_attributes' => null,
        ]);
    }

    public function test_business_owner_can_add_variant_items_to_variant_product(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'has_variants' => true]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);

        Volt::test('pages.products.items', ['product' => $product])
            ->set('sku', 'TSHIRT-L-RED')
            ->set('size', 'L')
            ->set('color', 'Red')
            ->set('base_unit_id', $unit->id)
            ->set('cost_price', '15.00')
            ->set('selling_price', '25.00')
            ->set('reorder_level', 5)
            ->call('save');

        $item = Item::where('sku', 'TSHIRT-L-RED')->firstOrFail();

        $this->assertSame(['size' => 'L', 'color' => 'Red'], $item->variant_attributes);
    }

    public function test_checking_track_batches_creates_a_batch_with_expiry(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);

        Volt::test('pages.products.items', ['product' => $product])
            ->set('sku', 'MILK-1L')
            ->set('base_unit_id', $unit->id)
            ->set('cost_price', '5.00')
            ->set('selling_price', '8.00')
            ->set('track_batches', true)
            ->set('batch_number', 'BATCH-001')
            ->set('expiry_date', '2026-12-31')
            ->call('save');

        $item = Item::where('sku', 'MILK-1L')->firstOrFail();
        $batch = \App\Models\Batch::where('item_id', $item->id)->firstOrFail();

        $this->assertSame($tenant->id, $batch->tenant_id);
        $this->assertSame('BATCH-001', $batch->batch_number);
        $this->assertSame('2026-12-31', $batch->expiry_date->toDateString());
    }

    public function test_batch_number_stays_optional_when_track_batches_is_checked(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);

        Volt::test('pages.products.items', ['product' => $product])
            ->set('sku', 'MILK-2L')
            ->set('base_unit_id', $unit->id)
            ->set('cost_price', '5.00')
            ->set('selling_price', '8.00')
            ->set('track_batches', true)
            ->call('save')
            ->assertHasNoErrors();

        $item = Item::where('sku', 'MILK-2L')->firstOrFail();

        $this->assertTrue($item->track_batches);
        $this->assertDatabaseCount('batches', 0);
    }

    public function test_duplicate_sku_within_tenant_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);

        Item::factory()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'sku' => 'DUPLICATE-SKU',
            'base_unit_id' => $unit->id,
        ]);

        Volt::test('pages.products.items', ['product' => $product])
            ->set('sku', 'DUPLICATE-SKU')
            ->set('base_unit_id', $unit->id)
            ->set('cost_price', '5.00')
            ->set('selling_price', '8.00')
            ->call('save')
            ->assertHasErrors('sku');
    }

    public function test_business_owner_can_deactivate_item(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);
        $item = Item::factory()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'base_unit_id' => $unit->id,
        ]);

        Volt::test('pages.products.items', ['product' => $product])
            ->call('confirmDeactivate', $item->id)
            ->call('deactivate');

        $this->assertSoftDeleted($item);
    }

    public function test_cashier_cannot_add_item(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);

        $this->get("/products/{$product->id}/items")->assertOk()->assertDontSee('Add Item');

        Volt::test('pages.products.items', ['product' => $product])
            ->call('openCreateModal')
            ->assertForbidden();
    }
}
