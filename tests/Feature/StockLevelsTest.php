<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\TaxRate;
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

        $row = $rows->firstWhere('item.id', $item->id);
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

        $row = $rows->firstWhere('item.id', $item->id);
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

        $row = $component->get('rows')->firstWhere('item.id', $item->id);
        $this->assertEquals(10, $row['on_hand']);
    }

    public function test_item_with_zero_stock_still_appears(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $item = $this->makeItem($tenant);

        $rows = Volt::test('pages.stock-levels.index')->get('rows');

        $row = $rows->firstWhere('item.id', $item->id);
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

        $this->assertEquals('low', $rows->firstWhere('item.id', $lowItem->id)['status']);
        $this->assertEquals('ok', $rows->firstWhere('item.id', $okItem->id)['status']);
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

        $ids = $rows->pluck('item.id')->all();
        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_all_scoped_tax_rate_is_added_to_the_price(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        TaxRate::factory()->create(['tenant_id' => $tenant->id, 'name' => 'VAT', 'rate_percentage' => 15, 'applies_to' => 'all']);

        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);
        $item = Item::factory()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'base_unit_id' => $unit->id, 'selling_price' => 100,
        ]);

        $row = Volt::test('pages.stock-levels.index')->get('rows')->firstWhere('item.id', $item->id);

        $this->assertEquals(115, $row['price_incl_tax']);
    }

    public function test_category_scoped_tax_rate_only_applies_to_matching_category(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $taxedCategory = Category::factory()->create(['tenant_id' => $tenant->id]);
        $untaxedCategory = Category::factory()->create(['tenant_id' => $tenant->id]);
        TaxRate::factory()->forCategory($taxedCategory->id)->create([
            'tenant_id' => $tenant->id, 'name' => 'Luxury Tax', 'rate_percentage' => 10,
        ]);

        $taxedProduct = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $taxedCategory->id]);
        $untaxedProduct = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $untaxedCategory->id]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);

        $taxedItem = Item::factory()->create([
            'tenant_id' => $tenant->id, 'product_id' => $taxedProduct->id, 'base_unit_id' => $unit->id, 'selling_price' => 100,
        ]);
        $untaxedItem = Item::factory()->create([
            'tenant_id' => $tenant->id, 'product_id' => $untaxedProduct->id, 'base_unit_id' => $unit->id, 'selling_price' => 100,
        ]);

        $rows = Volt::test('pages.stock-levels.index')->get('rows');

        $this->assertEquals(110, $rows->firstWhere('item.id', $taxedItem->id)['price_incl_tax']);
        $this->assertEquals(100, $rows->firstWhere('item.id', $untaxedItem->id)['price_incl_tax']);
    }

    public function test_multiple_simultaneous_tax_rates_stack(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        TaxRate::factory()->create(['tenant_id' => $tenant->id, 'name' => 'VAT', 'rate_percentage' => 15, 'applies_to' => 'all']);
        TaxRate::factory()->forCategory($category->id)->create(['tenant_id' => $tenant->id, 'name' => 'NHIL', 'rate_percentage' => 5]);

        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);
        $item = Item::factory()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'base_unit_id' => $unit->id, 'selling_price' => 100,
        ]);

        $row = Volt::test('pages.stock-levels.index')->get('rows')->firstWhere('item.id', $item->id);

        $this->assertEquals(120, $row['price_incl_tax']);
    }

    public function test_item_with_no_applicable_tax_shows_plain_selling_price(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $item = $this->makeItem($tenant);
        $item->update(['selling_price' => 75]);

        $row = Volt::test('pages.stock-levels.index')->get('rows')->firstWhere('item.id', $item->id);

        $this->assertEquals(75, $row['price_incl_tax']);
    }

    public function test_csv_export_includes_items_beyond_the_first_page(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        // rows() orders newest-first, so the earliest-created item is the
        // one pushed past the first 20-row page.
        for ($i = 0; $i < 21; $i++) {
            $item = $this->makeItem($tenant);
            $item->update(['sku' => "PAGE-ITEM-{$i}", 'selling_price' => 10]);
        }
        $firstItem = Item::where('tenant_id', $tenant->id)->where('sku', 'PAGE-ITEM-0')->firstOrFail();
        $this->stockUp($tenant, $branch, $firstItem, 3);

        $firstPageSkus = Volt::test('pages.stock-levels.index')->get('rows')->pluck('item.sku')->all();
        $this->assertNotContains('PAGE-ITEM-0', $firstPageSkus);

        $component = Volt::test('pages.stock-levels.index')->call('exportCsv');
        $component->assertFileDownloaded(contentType: 'text/csv');

        $content = base64_decode(data_get($component->effects, 'download.content'));
        $this->assertStringContainsString('PAGE-ITEM-0', $content);
        $this->assertStringContainsString('10.00', $content);
    }

    public function test_csv_export_respects_branch_filter(): void
    {
        // The catalog is tenant-wide, so filtering by branch doesn't remove
        // an item from the export -- it changes its On Hand figure to that
        // branch's own stock (0 if it has none there), same as the page.
        $tenant = Tenant::factory()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $secondBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $mainBranch, 'Business Owner'));

        $item = $this->makeItem($tenant);
        $item->update(['sku' => 'SECOND-ONLY-SKU']);
        $this->stockUp($tenant, $secondBranch, $item, 5);

        $component = Volt::test('pages.stock-levels.index')
            ->set('branch_id', $mainBranch->id)
            ->call('exportCsv');

        $content = base64_decode(data_get($component->effects, 'download.content'));
        $lines = array_map('str_getcsv', array_filter(explode("\n", trim($content))));
        $row = collect($lines)->first(fn ($line) => ($line[0] ?? null) === 'SECOND-ONLY-SKU');

        $this->assertNotNull($row);
        $this->assertSame('0', $row[3]); // On Hand column, scoped to the filtered branch
    }

    public function test_pdf_export_returns_a_valid_pdf(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $this->makeItem($tenant);

        $component = Volt::test('pages.stock-levels.index')->call('exportPdf');
        $component->assertFileDownloaded(contentType: 'application/pdf');

        $content = base64_decode(data_get($component->effects, 'download.content'));
        $this->assertNotEmpty($content);
        $this->assertStringStartsWith('%PDF', $content);
    }

    public function test_role_without_stock_view_cannot_reach_the_page_to_export(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);

        app(RolePermissionSeeder::class)->run();
        app(RolePermissionSeeder::class)->seedForTeam($tenant->id);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
        // A user with no role at all has no 'stock.view' permission.

        // The page itself is gated in mount(); such a user can never reach
        // the export buttons in the first place.
        $this->actingAs($user)->get('/stock-levels')->assertForbidden();
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
