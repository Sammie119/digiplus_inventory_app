<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\Shift;
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

class SaleVoidAndReturnTest extends TestCase
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

    /**
     * Builds a completed sale with one line item, its stock already
     * decremented (mirroring what completeSale() in the POS page would
     * have done), so void/return tests exercise the reversal in isolation.
     */
    private function makeCompletedSale(Tenant $tenant, Branch $branch, User $cashier, float $quantity = 5, float $unitPrice = 20, float $costAtSale = 10): array
    {
        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);
        $item = Item::factory()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'base_unit_id' => $unit->id,
            'cost_price' => $costAtSale,
            'selling_price' => $unitPrice,
        ]);

        $shift = Shift::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'cashier_id' => $cashier->id]);

        $sale = Sale::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'shift_id' => $shift->id,
            'cashier_id' => $cashier->id,
            'receipt_number' => 'MAIN-2026-'.str_pad((string) rand(1, 999999), 6, '0', STR_PAD_LEFT),
            'subtotal' => $quantity * $unitPrice,
            'grand_total' => $quantity * $unitPrice,
        ]);

        $saleItem = SaleItem::factory()->create([
            'sale_id' => $sale->id,
            'item_id' => $item->id,
            'batch_id' => null,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $quantity * $unitPrice,
            'cost_at_sale' => $costAtSale,
        ]);

        Payment::factory()->create([
            'sale_id' => $sale->id,
            'payment_method' => 'cash',
            'amount' => $quantity * $unitPrice,
        ]);

        // Stock as it would stand after the original sale decremented it.
        StockLevel::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'batch_id' => null,
            'quantity_on_hand' => 20 - $quantity,
        ]);

        return compact('sale', 'saleItem', 'item');
    }

    public function test_cashier_can_request_a_void(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        ['sale' => $sale] = $this->makeCompletedSale($tenant, $branch, $cashier);

        Volt::test('pages.sales.index')
            ->call('openVoidModal', $sale->id)
            ->set('voidReason', 'Customer changed their mind')
            ->call('requestVoid');

        $this->assertDatabaseHas('sales', [
            'id' => $sale->id,
            'status' => 'completed',
            'voided_by' => $cashier->id,
            'void_reason' => 'Customer changed their mind',
        ]);
    }

    public function test_cannot_request_a_second_void_while_one_is_pending(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        ['sale' => $sale] = $this->makeCompletedSale($tenant, $branch, $cashier);
        $sale->update(['voided_by' => $cashier->id, 'void_reason' => 'First request']);

        Volt::test('pages.sales.index')
            ->call('openVoidModal', $sale->id)
            ->assertForbidden();
    }

    public function test_manager_approving_void_reverses_stock(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->createUserWithRole($tenant, $branch, 'Cashier');
        $manager = $this->createUserWithRole($tenant, $branch, 'Branch Manager');

        ['sale' => $sale, 'item' => $item] = $this->makeCompletedSale($tenant, $branch, $cashier, quantity: 5);
        $sale->update(['voided_by' => $cashier->id, 'void_reason' => 'Damaged item']);

        $this->loginAs($manager);
        Volt::test('pages.sales.index')->call('resolveVoid', $sale->id, true);

        $this->assertDatabaseHas('sales', [
            'id' => $sale->id,
            'status' => 'void',
            'void_approved_by' => $manager->id,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $item->id,
            'movement_type' => 'return_in',
            'quantity' => 5,
            'reference_type' => Sale::class,
            'reference_id' => $sale->id,
        ]);

        $stockLevel = StockLevel::where('item_id', $item->id)->where('branch_id', $branch->id)->firstOrFail();
        $this->assertEquals(20, $stockLevel->quantity_on_hand);
    }

    public function test_rejecting_void_clears_request_with_no_stock_effect(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->createUserWithRole($tenant, $branch, 'Cashier');
        $manager = $this->createUserWithRole($tenant, $branch, 'Branch Manager');

        ['sale' => $sale, 'item' => $item] = $this->makeCompletedSale($tenant, $branch, $cashier, quantity: 5);
        $sale->update(['voided_by' => $cashier->id, 'void_reason' => 'Changed mind']);

        $this->loginAs($manager);
        Volt::test('pages.sales.index')->call('resolveVoid', $sale->id, false);

        $this->assertDatabaseHas('sales', [
            'id' => $sale->id,
            'status' => 'completed',
            'voided_by' => null,
            'void_reason' => null,
        ]);

        $stockLevel = StockLevel::where('item_id', $item->id)->where('branch_id', $branch->id)->firstOrFail();
        $this->assertEquals(15, $stockLevel->quantity_on_hand);
    }

    public function test_cashier_cannot_approve_or_reject_void(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->createUserWithRole($tenant, $branch, 'Cashier');

        ['sale' => $sale] = $this->makeCompletedSale($tenant, $branch, $cashier);
        $sale->update(['voided_by' => $cashier->id, 'void_reason' => 'x']);

        $this->loginAs($cashier);
        Volt::test('pages.sales.index')
            ->call('resolveVoid', $sale->id, true)
            ->assertForbidden();
    }

    public function test_voiding_an_already_void_sale_is_forbidden(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        ['sale' => $sale] = $this->makeCompletedSale($tenant, $branch, $cashier);
        $sale->update(['status' => 'void']);

        Volt::test('pages.sales.index')
            ->call('openVoidModal', $sale->id)
            ->assertForbidden();
    }

    public function test_voiding_a_sale_with_a_return_is_forbidden(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        ['sale' => $sale] = $this->makeCompletedSale($tenant, $branch, $cashier);
        SaleReturn::factory()->create(['tenant_id' => $tenant->id, 'sale_id' => $sale->id, 'branch_id' => $branch->id]);

        Volt::test('pages.sales.index')
            ->call('openVoidModal', $sale->id)
            ->assertForbidden();
    }

    public function test_cashier_can_request_a_partial_return_and_manager_approval_restocks_only_flagged_lines(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->createUserWithRole($tenant, $branch, 'Cashier');
        $manager = $this->createUserWithRole($tenant, $branch, 'Branch Manager');

        ['sale' => $sale, 'saleItem' => $saleItem, 'item' => $item] = $this->makeCompletedSale($tenant, $branch, $cashier, quantity: 10);

        $this->loginAs($cashier);
        $component = Volt::test('pages.sales.index')->call('openReturnModal', $sale->id);
        $component
            ->set('returnReason', 'Wrong size')
            ->set('returnLines.0.quantity_returned', '4')
            ->set('returnLines.0.restock', true)
            ->call('requestReturn');

        $saleReturn = SaleReturn::where('sale_id', $sale->id)->firstOrFail();
        $this->assertEquals('pending', $saleReturn->status);
        $this->assertDatabaseHas('sale_return_items', [
            'sale_return_id' => $saleReturn->id,
            'sale_item_id' => $saleItem->id,
            'quantity_returned' => 4,
            'restock' => true,
        ]);

        $this->loginAs($manager);
        Volt::test('pages.sales.index')->call('resolveReturn', $saleReturn->id, true);

        $this->assertDatabaseHas('sale_returns', ['id' => $saleReturn->id, 'status' => 'approved']);

        $stockLevel = StockLevel::where('item_id', $item->id)->where('branch_id', $branch->id)->firstOrFail();
        // Started at 20 - 10 sold = 10, then +4 restocked = 14.
        $this->assertEquals(14, $stockLevel->quantity_on_hand);

        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $item->id,
            'movement_type' => 'return_in',
            'quantity' => 4,
            'reference_type' => SaleReturn::class,
            'reference_id' => $saleReturn->id,
        ]);
    }

    public function test_return_without_restock_does_not_change_stock(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->createUserWithRole($tenant, $branch, 'Cashier');
        $manager = $this->createUserWithRole($tenant, $branch, 'Branch Manager');

        ['sale' => $sale, 'item' => $item] = $this->makeCompletedSale($tenant, $branch, $cashier, quantity: 10);

        $this->loginAs($cashier);
        Volt::test('pages.sales.index')
            ->call('openReturnModal', $sale->id)
            ->set('returnReason', 'Damaged, cannot resell')
            ->set('returnLines.0.quantity_returned', '3')
            ->set('returnLines.0.restock', false)
            ->call('requestReturn');

        $saleReturn = SaleReturn::where('sale_id', $sale->id)->firstOrFail();

        $this->loginAs($manager);
        Volt::test('pages.sales.index')->call('resolveReturn', $saleReturn->id, true);

        $stockLevel = StockLevel::where('item_id', $item->id)->where('branch_id', $branch->id)->firstOrFail();
        // Started at 20 - 10 = 10, no restock -> unchanged.
        $this->assertEquals(10, $stockLevel->quantity_on_hand);
        $this->assertDatabaseMissing('stock_movements', ['reference_type' => SaleReturn::class, 'reference_id' => $saleReturn->id]);
    }

    public function test_requesting_return_beyond_remaining_quantity_fails_validation(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        ['sale' => $sale] = $this->makeCompletedSale($tenant, $branch, $cashier, quantity: 5);

        Volt::test('pages.sales.index')
            ->call('openReturnModal', $sale->id)
            ->set('returnReason', 'Too many')
            ->set('returnLines.0.quantity_returned', '10')
            ->call('requestReturn')
            ->assertHasErrors(['returnLines']);

        $this->assertDatabaseMissing('sale_returns', ['sale_id' => $sale->id]);
    }

    public function test_cancelling_a_pending_return_leaves_no_trace(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->createUserWithRole($tenant, $branch, 'Cashier');
        $manager = $this->createUserWithRole($tenant, $branch, 'Branch Manager');

        ['sale' => $sale, 'item' => $item] = $this->makeCompletedSale($tenant, $branch, $cashier, quantity: 10);

        $this->loginAs($cashier);
        Volt::test('pages.sales.index')
            ->call('openReturnModal', $sale->id)
            ->set('returnReason', 'Mistake')
            ->set('returnLines.0.quantity_returned', '2')
            ->call('requestReturn');

        $saleReturn = SaleReturn::where('sale_id', $sale->id)->firstOrFail();

        $this->loginAs($manager);
        Volt::test('pages.sales.index')->call('resolveReturn', $saleReturn->id, false);

        $this->assertDatabaseMissing('sale_returns', ['id' => $saleReturn->id]);
        $this->assertDatabaseMissing('sale_return_items', ['sale_return_id' => $saleReturn->id]);

        $stockLevel = StockLevel::where('item_id', $item->id)->where('branch_id', $branch->id)->firstOrFail();
        $this->assertEquals(10, $stockLevel->quantity_on_hand);
    }

    public function test_requesting_return_on_voided_sale_is_forbidden(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        ['sale' => $sale] = $this->makeCompletedSale($tenant, $branch, $cashier);
        $sale->update(['status' => 'void']);

        Volt::test('pages.sales.index')
            ->call('openReturnModal', $sale->id)
            ->assertForbidden();
    }
}
