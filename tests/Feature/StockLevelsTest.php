<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StockLevelsTest extends TestCase
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

    private function makeItem(Tenant $tenant, int $reorderLevel = 5): Item
    {
        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);

        return Item::factory()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'base_unit_id' => $unit->id,
            'reorder_level' => $reorderLevel,
        ]);
    }

    private function stockUp(Tenant $tenant, Branch $branch, Item $item, float $qty): void
    {
        StockLevel::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'batch_id' => null,
            'quantity_on_hand' => $qty,
        ]);
    }

    public function test_business_owner_sees_consolidated_stock_across_branches(): void
    {
        $tenant = Tenant::factory()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $secondBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $mainBranch, 'Business Owner'));

        $item = $this->makeItem($tenant);
        $this->stockUp($tenant, $mainBranch, $item, 10);
        $this->stockUp($tenant, $secondBranch, $item, 7);

        $rows = Volt::test('pages.stock-levels.index')->get('rows');

        $row = collect($rows)->firstWhere('item.id', $item->id);
        $this->assertEquals(17, $row['on_hand']);
    }

    public function test_selecting_a_branch_shows_only_that_branchs_stock(): void
    {
        $tenant = Tenant::factory()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $secondBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $mainBranch, 'Business Owner'));

        $item = $this->makeItem($tenant);
        $this->stockUp($tenant, $mainBranch, $item, 10);
        $this->stockUp($tenant, $secondBranch, $item, 7);

        $rows = Volt::test('pages.stock-levels.index')
            ->set('branch_id', $mainBranch->id)
            ->get('rows');

        $row = collect($rows)->firstWhere('item.id', $item->id);
        $this->assertEquals(10, $row['on_hand']);
    }

    public function test_branch_manager_only_sees_their_own_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $secondBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $mainBranch, 'Branch Manager'));

        $item = $this->makeItem($tenant);
        $this->stockUp($tenant, $mainBranch, $item, 10);
        $this->stockUp($tenant, $secondBranch, $item, 7);

        $component = Volt::test('pages.stock-levels.index');

        $this->assertEquals($mainBranch->id, $component->get('branch_id'));
        $this->assertCount(1, $component->get('branchOptions'));

        $row = collect($component->get('rows'))->firstWhere('item.id', $item->id);
        $this->assertEquals(10, $row['on_hand']);
    }

    public function test_item_with_zero_stock_still_appears(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $item = $this->makeItem($tenant);

        $rows = Volt::test('pages.stock-levels.index')->get('rows');

        $row = collect($rows)->firstWhere('item.id', $item->id);
        $this->assertNotNull($row);
        $this->assertEquals(0, $row['on_hand']);
        $this->assertEquals('out', $row['status']);
    }

    public function test_status_classification_low_and_ok(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $lowItem = $this->makeItem($tenant, reorderLevel: 10);
        $this->stockUp($tenant, $branch, $lowItem, 5);

        $okItem = $this->makeItem($tenant, reorderLevel: 10);
        $this->stockUp($tenant, $branch, $okItem, 50);

        $rows = Volt::test('pages.stock-levels.index')->get('rows');

        $this->assertEquals('low', collect($rows)->firstWhere('item.id', $lowItem->id)['status']);
        $this->assertEquals('ok', collect($rows)->firstWhere('item.id', $okItem->id)['status']);
    }

    public function test_search_filters_by_sku(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);

        $matching = Item::factory()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'base_unit_id' => $unit->id, 'sku' => 'FIND-ME-123']);
        $other = Item::factory()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'base_unit_id' => $unit->id, 'sku' => 'OTHER-456']);

        $rows = Volt::test('pages.stock-levels.index')
            ->set('search', 'FIND-ME')
            ->get('rows');

        $ids = collect($rows)->pluck('item.id')->all();
        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_tenant_isolation(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $otherTenant = Tenant::factory()->create();
        $otherCategory = Category::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherProduct = Product::factory()->create(['tenant_id' => $otherTenant->id, 'category_id' => $otherCategory->id]);
        $otherUnit = UnitOfMeasure::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherItem = Item::factory()->create([
            'tenant_id' => $otherTenant->id,
            'product_id' => $otherProduct->id,
            'base_unit_id' => $otherUnit->id,
            'sku' => 'OTHER-TENANT-SKU',
        ]);

        Volt::test('pages.stock-levels.index')->assertDontSee('OTHER-TENANT-SKU');
    }
}
