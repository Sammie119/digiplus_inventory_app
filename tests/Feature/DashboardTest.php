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

class DashboardTest extends TestCase
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

    private function makeCompletedSale(Tenant $tenant, Branch $branch, User $cashier, float $grandTotal): Sale
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
        ]);

        SaleItem::factory()->create([
            'sale_id' => $sale->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'unit_price' => $grandTotal,
            'line_total' => $grandTotal,
            'cost_at_sale' => $grandTotal / 2,
        ]);

        return $sale;
    }

    public function test_owner_sees_todays_sales_figure(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $this->makeCompletedSale($tenant, $branch, $owner, 100);
        $this->makeCompletedSale($tenant, $branch, $owner, 50);

        $todaySales = Volt::test('pages.dashboard.index')->get('todaySales');

        $this->assertEquals(2, $todaySales['count']);
        $this->assertEquals(150, $todaySales['revenue']);
    }

    public function test_figures_are_branch_scoped_for_non_owner(): void
    {
        $tenant = Tenant::factory()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $otherBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager = $this->loginAs($this->createUserWithRole($tenant, $mainBranch, 'Branch Manager'));
        $otherCashier = $this->createUserWithRole($tenant, $otherBranch, 'Cashier');

        $this->makeCompletedSale($tenant, $mainBranch, $manager, 100);
        $this->makeCompletedSale($tenant, $otherBranch, $otherCashier, 999);

        $todaySales = Volt::test('pages.dashboard.index')->get('todaySales');

        $this->assertEquals(1, $todaySales['count']);
        $this->assertEquals(100, $todaySales['revenue']);
    }

    public function test_cashier_without_reports_permission_does_not_see_report_widgets(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Point of Sale')
            ->assertDontSee('Sales Trend')
            ->assertDontSee('Top Categories');
    }

    public function test_owner_sees_report_widgets(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Sales Trend')
            ->assertSee('Top Categories');
    }
}
