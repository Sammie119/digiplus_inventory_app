<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\FinancialYear;
use App\Models\Item;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProfitLossReportTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithRole(Tenant $tenant, Branch $branch, string $role): User
    {
        app(RolePermissionSeeder::class)->run();
        app(RolePermissionSeeder::class)->seedForTeam($tenant->id);

        $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
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

    private function makeItem(Tenant $tenant): Item
    {
        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);

        return Item::factory()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'base_unit_id' => $unit->id]);
    }

    private function makeSale(Tenant $tenant, Branch $branch, User $cashier, Item $item, array $saleAttrs, array $lineAttrs): Sale
    {
        $shift = Shift::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'cashier_id' => $cashier->id]);

        $sale = Sale::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'shift_id' => $shift->id,
            'cashier_id' => $cashier->id,
            'status' => 'completed',
        ], $saleAttrs));

        SaleItem::factory()->create(array_merge([
            'sale_id' => $sale->id,
            'item_id' => $item->id,
        ], $lineAttrs));

        return $sale;
    }

    public function test_revenue_is_net_of_tax_and_cost_from_cost_at_sale(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));
        $item = $this->makeItem($tenant);

        $this->makeSale($tenant, $branch, $owner, $item,
            ['subtotal' => 100, 'tax_total' => 15, 'grand_total' => 115],
            ['quantity' => 1, 'unit_price' => 100, 'line_total' => 100, 'cost_at_sale' => 60],
        );

        $summary = Volt::test('pages.reports.profit-loss')->get('summary');

        $this->assertEquals(1, $summary['sales_count']);
        $this->assertEquals(100, $summary['gross_revenue']); // 115 grand_total - 15 tax
        $this->assertEquals(60, $summary['cogs']);
        $this->assertEquals(40, $summary['net_gross_profit']);
    }

    public function test_voided_sale_is_excluded(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));
        $item = $this->makeItem($tenant);

        $this->makeSale($tenant, $branch, $owner, $item,
            ['subtotal' => 500, 'grand_total' => 500, 'status' => 'void'],
            ['quantity' => 1, 'unit_price' => 500, 'line_total' => 500, 'cost_at_sale' => 250],
        );

        $summary = Volt::test('pages.reports.profit-loss')->get('summary');

        $this->assertEquals(0, $summary['sales_count']);
        $this->assertEquals(0, $summary['gross_revenue']);
    }

    public function test_approved_return_reduces_revenue_and_cogs(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));
        $item = $this->makeItem($tenant);

        $sale = $this->makeSale($tenant, $branch, $owner, $item,
            ['subtotal' => 200, 'grand_total' => 200],
            ['quantity' => 2, 'unit_price' => 100, 'line_total' => 200, 'cost_at_sale' => 60],
        );
        $saleItem = $sale->items->first();

        $return = SaleReturn::factory()->approved()->create([
            'tenant_id' => $tenant->id, 'sale_id' => $sale->id, 'branch_id' => $branch->id, 'processed_by' => $owner->id,
        ]);
        SaleReturnItem::factory()->create([
            'sale_return_id' => $return->id, 'sale_item_id' => $saleItem->id, 'quantity_returned' => 1,
        ]);

        $summary = Volt::test('pages.reports.profit-loss')->get('summary');

        $this->assertEquals(200, $summary['gross_revenue']);
        $this->assertEquals(100, $summary['returns_revenue']); // 1 unit returned @ 100/unit
        $this->assertEquals(100, $summary['net_revenue']);
        $this->assertEquals(120, $summary['cogs']); // 2 * 60
        $this->assertEquals(60, $summary['returns_cogs']); // 1 * 60
        $this->assertEquals(60, $summary['net_cogs']);
    }

    public function test_pending_return_does_not_reduce_revenue(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));
        $item = $this->makeItem($tenant);

        $sale = $this->makeSale($tenant, $branch, $owner, $item,
            ['subtotal' => 200, 'grand_total' => 200],
            ['quantity' => 2, 'unit_price' => 100, 'line_total' => 200, 'cost_at_sale' => 60],
        );
        $saleItem = $sale->items->first();

        $return = SaleReturn::factory()->create([
            'tenant_id' => $tenant->id, 'sale_id' => $sale->id, 'branch_id' => $branch->id, 'processed_by' => $owner->id, 'status' => 'pending',
        ]);
        SaleReturnItem::factory()->create([
            'sale_return_id' => $return->id, 'sale_item_id' => $saleItem->id, 'quantity_returned' => 1,
        ]);

        $summary = Volt::test('pages.reports.profit-loss')->get('summary');

        $this->assertEquals(0, $summary['returns_revenue']);
        $this->assertEquals(200, $summary['net_revenue']);
    }

    public function test_negative_stock_adjustment_reduces_gross_profit_but_positive_does_not(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));
        $item = $this->makeItem($tenant);

        StockMovement::create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'item_id' => $item->id, 'batch_id' => null,
            'movement_type' => 'adjustment', 'quantity' => -5, 'unit_cost' => 10,
            'reference_type' => 'App\\Models\\StockAdjustment', 'reference_id' => 1, 'reason_code' => 'damage',
            'performed_by' => $owner->id,
        ]);
        StockMovement::create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'item_id' => $item->id, 'batch_id' => null,
            'movement_type' => 'adjustment', 'quantity' => 8, 'unit_cost' => 10,
            'reference_type' => 'App\\Models\\StockAdjustment', 'reference_id' => 2, 'reason_code' => 'count_correction',
            'performed_by' => $owner->id,
        ]);

        $summary = Volt::test('pages.reports.profit-loss')->get('summary');

        $this->assertEquals(50, $summary['shrinkage']); // only the -5 * 10 counts, not the +8
        $this->assertEquals(-50, $summary['net_gross_profit']);
    }

    public function test_branch_manager_sees_only_their_branch(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $otherBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager = $this->loginAs($this->createUserWithRole($tenant, $mainBranch, 'Branch Manager'));
        $otherManager = $this->createUserWithRole($tenant, $otherBranch, 'Branch Manager');
        $item = $this->makeItem($tenant);

        $this->makeSale($tenant, $mainBranch, $manager, $item,
            ['subtotal' => 100, 'grand_total' => 100],
            ['quantity' => 1, 'unit_price' => 100, 'line_total' => 100, 'cost_at_sale' => 50],
        );
        $this->makeSale($tenant, $otherBranch, $otherManager, $item,
            ['subtotal' => 999, 'grand_total' => 999],
            ['quantity' => 1, 'unit_price' => 999, 'line_total' => 999, 'cost_at_sale' => 500],
        );

        $summary = Volt::test('pages.reports.profit-loss')->get('summary');

        $this->assertEquals(1, $summary['sales_count']);
        $this->assertEquals(100, $summary['gross_revenue']);
    }

    public function test_financial_year_picker_defaults_to_open_year_and_updates_range(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $openYear = FinancialYear::factory()->create([
            'tenant_id' => $tenant->id, 'status' => 'open', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ]);

        $component = Volt::test('pages.reports.profit-loss');

        $this->assertSame($openYear->id, $component->get('financial_year_id'));
        $this->assertSame('2026-01-01', $component->get('from'));
        $this->assertSame('2026-12-31', $component->get('to'));

        $otherYear = FinancialYear::factory()->closed()->create([
            'tenant_id' => $tenant->id, 'start_date' => '2025-01-01', 'end_date' => '2025-12-31',
        ]);

        $component->set('financial_year_id', $otherYear->id);

        $this->assertSame('2025-01-01', $component->get('from'));
        $this->assertSame('2025-12-31', $component->get('to'));
    }

    public function test_cashier_cannot_view_profit_loss_report(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->createUserWithRole($tenant, $branch, 'Cashier');

        $this->actingAs($cashier)->get('/reports/profit-loss')->assertForbidden();
    }

    public function test_tenant_isolation(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $otherTenant = Tenant::factory()->licensed()->create();
        $otherBranch = Branch::factory()->main()->create(['tenant_id' => $otherTenant->id]);
        $otherCashier = User::factory()->create(['tenant_id' => $otherTenant->id, 'branch_id' => $otherBranch->id]);
        $otherItem = $this->makeItem($otherTenant);

        $this->makeSale($otherTenant, $otherBranch, $otherCashier, $otherItem,
            ['subtotal' => 5000, 'grand_total' => 5000],
            ['quantity' => 1, 'unit_price' => 5000, 'line_total' => 5000, 'cost_at_sale' => 2500],
        );

        $summary = Volt::test('pages.reports.profit-loss')->get('summary');

        $this->assertEquals(0, $summary['sales_count']);
    }
}
