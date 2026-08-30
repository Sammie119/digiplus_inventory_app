<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleTaxLine;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SaleReceiptTest extends TestCase
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

    private function makeSale(Tenant $tenant, Branch $branch, User $cashier): Sale
    {
        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);
        $item = Item::factory()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'base_unit_id' => $unit->id, 'sku' => 'RCPT-1',
        ]);
        $shift = Shift::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'cashier_id' => $cashier->id]);

        $sale = Sale::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'shift_id' => $shift->id,
            'cashier_id' => $cashier->id,
            'receipt_number' => 'MAIN-2026-000001',
            'subtotal' => 40,
            'tax_total' => 6,
            'grand_total' => 46,
            'status' => 'completed',
        ]);

        SaleItem::factory()->create([
            'sale_id' => $sale->id, 'item_id' => $item->id, 'quantity' => 2, 'unit_price' => 20, 'line_total' => 40, 'cost_at_sale' => 10,
        ]);

        return $sale;
    }

    public function test_user_with_sales_view_can_open_the_receipt(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $sale = $this->makeSale($tenant, $branch, $owner);

        $response = $this->get(route('sales.receipt', $sale));

        $response->assertOk();
        $response->assertSee('MAIN-2026-000001');
        $response->assertSee('RCPT-1');
        $response->assertSee('46.00');
    }

    public function test_receipt_shows_each_tax_line_separately_when_present(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $sale = $this->makeSale($tenant, $branch, $owner);

        SaleTaxLine::create(['sale_id' => $sale->id, 'tax_rate_id' => null, 'name' => 'VAT', 'rate_percentage' => 12.5, 'amount' => 4]);
        SaleTaxLine::create(['sale_id' => $sale->id, 'tax_rate_id' => null, 'name' => 'NHIL', 'rate_percentage' => 2.5, 'amount' => 2]);

        $response = $this->get(route('sales.receipt', $sale));

        $response->assertOk();
        $response->assertSee('VAT (12.5%)');
        $response->assertSee('NHIL (2.5%)');
    }

    public function test_user_without_sales_permission_is_forbidden(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->createUserWithRole($tenant, $branch, 'Business Owner');
        $storeKeeper = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Store Keeper'));

        $sale = $this->makeSale($tenant, $branch, $owner);

        $this->get(route('sales.receipt', $sale))->assertForbidden();
    }

    public function test_receipt_from_another_tenant_is_not_found(): void
    {
        $tenantA = Tenant::factory()->licensed()->create();
        $branchA = Branch::factory()->main()->create(['tenant_id' => $tenantA->id]);
        $ownerA = $this->createUserWithRole($tenantA, $branchA, 'Business Owner');
        $saleA = $this->makeSale($tenantA, $branchA, $ownerA);

        $tenantB = Tenant::factory()->licensed()->create();
        $branchB = Branch::factory()->main()->create(['tenant_id' => $tenantB->id]);
        $this->loginAs($this->createUserWithRole($tenantB, $branchB, 'Business Owner'));

        $this->get(route('sales.receipt', $saleA))->assertNotFound();
    }
}
