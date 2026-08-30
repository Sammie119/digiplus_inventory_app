<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SalesReportTest extends TestCase
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

    private function makeSale(Tenant $tenant, Branch $branch, User $cashier, float $grandTotal, float $costAtSale, string $status = 'completed', ?\DateTimeInterface $createdAt = null): Sale
    {
        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);
        $item = Item::factory()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'base_unit_id' => $unit->id]);
        $shift = Shift::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'cashier_id' => $cashier->id]);

        $sale = Sale::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'shift_id' => $shift->id,
            'cashier_id' => $cashier->id,
            'receipt_number' => 'MAIN-2026-'.str_pad((string) rand(1, 999999), 6, '0', STR_PAD_LEFT),
            'subtotal' => $grandTotal,
            'grand_total' => $grandTotal,
            'status' => $status,
            'created_at' => $createdAt ?? now(),
        ]);

        SaleItem::factory()->create([
            'sale_id' => $sale->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'unit_price' => $grandTotal,
            'line_total' => $grandTotal,
            'cost_at_sale' => $costAtSale,
        ]);

        return $sale;
    }

    public function test_date_range_excludes_sales_outside_it(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $this->makeSale($tenant, $branch, $owner, 100, 50, createdAt: now());
        $this->makeSale($tenant, $branch, $owner, 200, 100, createdAt: now()->subDays(45));

        $summary = Volt::test('pages.reports.sales')
            ->set('from', now()->startOfMonth()->toDateString())
            ->set('to', now()->endOfMonth()->toDateString())
            ->get('summary');

        $this->assertEquals(1, $summary['count']);
        $this->assertEquals(100, $summary['revenue']);
    }

    public function test_revenue_cost_and_margin_totals_are_correct(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $this->makeSale($tenant, $branch, $owner, 100, 60);
        $this->makeSale($tenant, $branch, $owner, 50, 20);

        $summary = Volt::test('pages.reports.sales')->get('summary');

        $this->assertEquals(2, $summary['count']);
        $this->assertEquals(150, $summary['revenue']);
        $this->assertEquals(80, $summary['cost']);
        $this->assertEquals(70, $summary['margin']);
        $this->assertEqualsWithDelta(46.7, $summary['margin_pct'], 0.1);
    }

    public function test_tax_total_sums_correctly(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $saleA = $this->makeSale($tenant, $branch, $owner, 115, 60);
        $saleA->update(['tax_total' => 15]);
        $saleB = $this->makeSale($tenant, $branch, $owner, 57.5, 20);
        $saleB->update(['tax_total' => 7.5]);

        $summary = Volt::test('pages.reports.sales')->get('summary');

        $this->assertEquals(22.5, $summary['tax']);
    }

    public function test_voided_sale_is_excluded_from_totals(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $this->makeSale($tenant, $branch, $owner, 100, 50, status: 'completed');
        $this->makeSale($tenant, $branch, $owner, 500, 250, status: 'void');

        $summary = Volt::test('pages.reports.sales')->get('summary');

        $this->assertEquals(1, $summary['count']);
        $this->assertEquals(100, $summary['revenue']);
    }

    public function test_cashier_cannot_view_sales_report(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->createUserWithRole($tenant, $branch, 'Cashier');

        $this->actingAs($cashier)->get('/reports/sales')->assertForbidden();
    }

    public function test_branch_manager_sees_only_their_branch(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $otherBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager = $this->loginAs($this->createUserWithRole($tenant, $mainBranch, 'Branch Manager'));
        $otherManager = $this->createUserWithRole($tenant, $otherBranch, 'Branch Manager');

        $this->makeSale($tenant, $mainBranch, $manager, 100, 50);
        $this->makeSale($tenant, $otherBranch, $otherManager, 999, 500);

        $component = Volt::test('pages.reports.sales');
        $this->assertCount(1, $component->get('branchOptions'));

        $summary = $component->get('summary');
        $this->assertEquals(1, $summary['count']);
        $this->assertEquals(100, $summary['revenue']);
    }

    public function test_business_owner_can_switch_branches(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $otherBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $mainBranch, 'Business Owner'));
        $otherCashier = $this->createUserWithRole($tenant, $otherBranch, 'Cashier');

        $this->makeSale($tenant, $mainBranch, $owner, 100, 50);
        $this->makeSale($tenant, $otherBranch, $otherCashier, 300, 150);

        $allBranches = Volt::test('pages.reports.sales')->get('summary');
        $this->assertEquals(400, $allBranches['revenue']);

        $mainOnly = Volt::test('pages.reports.sales')
            ->set('branch_id', $mainBranch->id)
            ->get('summary');
        $this->assertEquals(100, $mainOnly['revenue']);
    }

    public function test_tenant_isolation(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $otherTenant = Tenant::factory()->licensed()->create();
        $otherBranch = Branch::factory()->main()->create(['tenant_id' => $otherTenant->id]);
        $otherCashier = User::factory()->create(['tenant_id' => $otherTenant->id, 'branch_id' => $otherBranch->id]);
        $this->makeSale($otherTenant, $otherBranch, $otherCashier, 5000, 2500);

        $summary = Volt::test('pages.reports.sales')->get('summary');
        $this->assertEquals(0, $summary['count']);
    }
}
