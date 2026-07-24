<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
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

class PosManagementTest extends TestCase
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

    private function makeItem(Tenant $tenant, float $costPrice = 10, float $sellingPrice = 20): Item
    {
        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);

        return Item::factory()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'base_unit_id' => $unit->id,
            'cost_price' => $costPrice,
            'selling_price' => $sellingPrice,
        ]);
    }

    private function stockUp(Tenant $tenant, Branch $branch, Item $item, float $qty, ?int $batchId = null): void
    {
        StockLevel::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'batch_id' => $batchId,
            'quantity_on_hand' => $qty,
        ]);
    }

    public function test_cashier_can_open_a_shift(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        Volt::test('pages.pos.index')
            ->set('opening_float', '100')
            ->call('startShift');

        $this->assertDatabaseHas('shifts', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'cashier_id' => $cashier->id,
            'opening_float' => 100,
            'status' => 'open',
        ]);
    }

    public function test_cashier_cannot_open_a_second_shift_while_one_is_open(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        Volt::test('pages.pos.index')->set('opening_float', '100')->call('startShift');

        Volt::test('pages.pos.index')
            ->set('opening_float', '50')
            ->call('startShift')
            ->assertForbidden();
    }

    public function test_completing_a_sale_with_cash_payment_deducts_stock_and_creates_records(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        $item = $this->makeItem($tenant, costPrice: 10, sellingPrice: 20);
        $this->stockUp($tenant, $branch, $item, 15);

        Volt::test('pages.pos.index')->set('opening_float', '100')->call('startShift');

        Volt::test('pages.pos.index')
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity', '3')
            ->set('payments.0.method', 'cash')
            ->set('payments.0.amount', '60')
            ->call('completeSale')
            ->assertDispatched('toast', message: 'Sale completed.', type: 'success');

        $sale = Sale::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertEquals(60, $sale->grand_total);
        $this->assertEquals($cashier->id, $sale->cashier_id);
        $this->assertStringStartsWith('MAIN-'.now()->year.'-', $sale->receipt_number);

        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'item_id' => $item->id,
            'quantity' => 3,
            'unit_price' => 20,
            'line_total' => 60,
            'cost_at_sale' => 10,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $item->id,
            'movement_type' => 'sale',
            'quantity' => -3,
            'reference_type' => Sale::class,
            'reference_id' => $sale->id,
        ]);

        $stockLevel = StockLevel::where('item_id', $item->id)->where('branch_id', $branch->id)->firstOrFail();
        $this->assertEquals(12, $stockLevel->quantity_on_hand);

        $this->assertDatabaseHas('payments', [
            'sale_id' => $sale->id,
            'payment_method' => 'cash',
            'amount' => 60,
        ]);
    }

    public function test_completing_a_sale_with_an_all_scoped_tax_rate_computes_tax_total(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        \App\Models\TaxRate::factory()->create(['tenant_id' => $tenant->id, 'name' => 'VAT', 'rate_percentage' => 15]);

        $item = $this->makeItem($tenant, costPrice: 10, sellingPrice: 20);
        $this->stockUp($tenant, $branch, $item, 15);

        Volt::test('pages.pos.index')->set('opening_float', '100')->call('startShift');

        // Subtotal 3*20=60, +15% VAT = 9, grand total 69.
        $pos = Volt::test('pages.pos.index')
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity', '3');

        $this->assertEquals(9.0, $pos->get('taxTotal'));
        $this->assertEquals(69.0, $pos->get('grandTotal'));

        // Paying only the old subtotal (60) should fail now that tax applies.
        $pos->set('payments.0.method', 'cash')->set('payments.0.amount', '60')
            ->call('completeSale')
            ->assertHasErrors(['payments']);

        $pos->set('payments.0.amount', '69')->call('completeSale');

        $sale = Sale::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertEquals(60, $sale->subtotal);
        $this->assertEquals(9, $sale->tax_total);
        $this->assertEquals(69, $sale->grand_total);
    }

    public function test_category_scoped_tax_rate_only_taxes_matching_lines(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        $taxedCategory = Category::factory()->create(['tenant_id' => $tenant->id]);
        $untaxedCategory = Category::factory()->create(['tenant_id' => $tenant->id]);

        \App\Models\TaxRate::factory()->forCategory($taxedCategory->id)->create([
            'tenant_id' => $tenant->id, 'name' => 'Luxury Tax', 'rate_percentage' => 10,
        ]);

        $taxedProduct = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $taxedCategory->id]);
        $untaxedProduct = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $untaxedCategory->id]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);

        $taxedItem = Item::factory()->create([
            'tenant_id' => $tenant->id, 'product_id' => $taxedProduct->id, 'base_unit_id' => $unit->id,
            'cost_price' => 10, 'selling_price' => 20,
        ]);
        $untaxedItem = Item::factory()->create([
            'tenant_id' => $tenant->id, 'product_id' => $untaxedProduct->id, 'base_unit_id' => $unit->id,
            'cost_price' => 10, 'selling_price' => 20,
        ]);

        $this->stockUp($tenant, $branch, $taxedItem, 10);
        $this->stockUp($tenant, $branch, $untaxedItem, 10);

        Volt::test('pages.pos.index')->set('opening_float', '100')->call('startShift');

        // 1 taxed line (20, +10% = 2) + 1 untaxed line (20, +0) = subtotal 40, tax 2, grand 42.
        $pos = Volt::test('pages.pos.index')
            ->set('lines.0.item_id', $taxedItem->id)
            ->set('lines.0.quantity', '1')
            ->call('addLine')
            ->set('lines.1.item_id', $untaxedItem->id)
            ->set('lines.1.quantity', '1');

        $this->assertEquals(2.0, $pos->get('taxTotal'));
        $this->assertEquals(42.0, $pos->get('grandTotal'));
    }

    public function test_split_tender_payments_must_sum_to_the_total(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        $item = $this->makeItem($tenant, sellingPrice: 20);
        $this->stockUp($tenant, $branch, $item, 15);

        Volt::test('pages.pos.index')->set('opening_float', '100')->call('startShift');

        // Correct split: 3 * 20 = 60, paid 40 cash + 20 mobile_money.
        Volt::test('pages.pos.index')
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity', '3')
            ->set('payments.0.method', 'cash')
            ->set('payments.0.amount', '40')
            ->call('addPayment')
            ->set('payments.1.method', 'mobile_money')
            ->set('payments.1.amount', '20')
            ->call('completeSale');

        $sale = Sale::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertEquals(2, $sale->payments()->count());
        $this->assertEquals(60, $sale->payments()->sum('amount'));
    }

    public function test_mismatched_payment_total_fails_validation_with_no_mutation(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        $item = $this->makeItem($tenant, sellingPrice: 20);
        $this->stockUp($tenant, $branch, $item, 15);

        Volt::test('pages.pos.index')->set('opening_float', '100')->call('startShift');

        Volt::test('pages.pos.index')
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity', '3')
            ->set('payments.0.method', 'cash')
            ->set('payments.0.amount', '50')
            ->call('completeSale')
            ->assertHasErrors(['payments']);

        $this->assertDatabaseMissing('sales', ['tenant_id' => $tenant->id]);
    }

    public function test_fefo_auto_selects_earliest_expiry_batch(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        $item = $this->makeItem($tenant, sellingPrice: 20);
        $item->update(['track_batches' => true]);

        $laterBatch = Batch::create(['tenant_id' => $tenant->id, 'item_id' => $item->id, 'batch_number' => 'LATER', 'expiry_date' => now()->addMonths(6)]);
        $earlierBatch = Batch::create(['tenant_id' => $tenant->id, 'item_id' => $item->id, 'batch_number' => 'EARLIER', 'expiry_date' => now()->addMonth()]);

        $this->stockUp($tenant, $branch, $item, 10, $laterBatch->id);
        $this->stockUp($tenant, $branch, $item, 10, $earlierBatch->id);

        Volt::test('pages.pos.index')->set('opening_float', '100')->call('startShift');

        Volt::test('pages.pos.index')
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity', '4')
            ->set('payments.0.method', 'cash')
            ->set('payments.0.amount', '80')
            ->call('completeSale');

        $sale = Sale::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertEquals($earlierBatch->id, $sale->items()->first()->batch_id);

        $earlierLevel = StockLevel::where('batch_id', $earlierBatch->id)->firstOrFail();
        $this->assertEquals(6, $earlierLevel->quantity_on_hand);
        $laterLevel = StockLevel::where('batch_id', $laterBatch->id)->firstOrFail();
        $this->assertEquals(10, $laterLevel->quantity_on_hand);
    }

    public function test_manual_batch_override_is_honored(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        $item = $this->makeItem($tenant, sellingPrice: 20);
        $item->update(['track_batches' => true]);

        $laterBatch = Batch::create(['tenant_id' => $tenant->id, 'item_id' => $item->id, 'batch_number' => 'LATER', 'expiry_date' => now()->addMonths(6)]);
        $earlierBatch = Batch::create(['tenant_id' => $tenant->id, 'item_id' => $item->id, 'batch_number' => 'EARLIER', 'expiry_date' => now()->addMonth()]);

        $this->stockUp($tenant, $branch, $item, 10, $laterBatch->id);
        $this->stockUp($tenant, $branch, $item, 10, $earlierBatch->id);

        Volt::test('pages.pos.index')->set('opening_float', '100')->call('startShift');

        Volt::test('pages.pos.index')
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity', '4')
            ->set('lines.0.batch_id', $laterBatch->id)
            ->set('payments.0.method', 'cash')
            ->set('payments.0.amount', '80')
            ->call('completeSale');

        $sale = Sale::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertEquals($laterBatch->id, $sale->items()->first()->batch_id);
    }

    public function test_selling_more_than_on_hand_stock_fails_validation(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        $item = $this->makeItem($tenant, sellingPrice: 20);
        $this->stockUp($tenant, $branch, $item, 2);

        Volt::test('pages.pos.index')->set('opening_float', '100')->call('startShift');

        Volt::test('pages.pos.index')
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity', '5')
            ->set('payments.0.method', 'cash')
            ->set('payments.0.amount', '100')
            ->call('completeSale')
            ->assertHasErrors(['lines']);

        $this->assertDatabaseMissing('sales', ['tenant_id' => $tenant->id]);
    }

    public function test_selling_without_an_open_shift_is_forbidden(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        $item = $this->makeItem($tenant, sellingPrice: 20);
        $this->stockUp($tenant, $branch, $item, 10);

        Volt::test('pages.pos.index')
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity', '1')
            ->set('payments.0.method', 'cash')
            ->set('payments.0.amount', '20')
            ->call('completeSale')
            ->assertHasErrors(['lines']);

        $this->assertDatabaseMissing('sales', ['tenant_id' => $tenant->id]);
    }

    public function test_receipt_numbers_are_sequential_per_branch_and_independent_across_branches(): void
    {
        $tenant = Tenant::factory()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $secondBranch = Branch::factory()->create(['tenant_id' => $tenant->id, 'code' => 'DOWNTOWN']);

        $mainCashier = $this->createUserWithRole($tenant, $mainBranch, 'Cashier');
        $secondCashier = $this->createUserWithRole($tenant, $secondBranch, 'Cashier');

        $item = $this->makeItem($tenant, sellingPrice: 10);
        $this->stockUp($tenant, $mainBranch, $item, 10);
        $this->stockUp($tenant, $secondBranch, $item, 10);

        $this->loginAs($mainCashier);
        Volt::test('pages.pos.index')->set('opening_float', '0')->call('startShift');
        Volt::test('pages.pos.index')->set('lines.0.item_id', $item->id)->set('lines.0.quantity', '1')->set('payments.0.method', 'cash')->set('payments.0.amount', '10')->call('completeSale');
        Volt::test('pages.pos.index')->set('lines.0.item_id', $item->id)->set('lines.0.quantity', '1')->set('payments.0.method', 'cash')->set('payments.0.amount', '10')->call('completeSale');

        $this->loginAs($secondCashier);
        Volt::test('pages.pos.index')->set('opening_float', '0')->call('startShift');
        Volt::test('pages.pos.index')->set('lines.0.item_id', $item->id)->set('lines.0.quantity', '1')->set('payments.0.method', 'cash')->set('payments.0.amount', '10')->call('completeSale');

        $mainReceipts = Sale::where('branch_id', $mainBranch->id)->orderBy('id')->pluck('receipt_number')->all();
        $this->assertEquals([
            'MAIN-'.now()->year.'-000001',
            'MAIN-'.now()->year.'-000002',
        ], $mainReceipts);

        $secondReceipts = Sale::where('branch_id', $secondBranch->id)->pluck('receipt_number')->all();
        $this->assertEquals(['DOWNTOWN-'.now()->year.'-000001'], $secondReceipts);
    }

    public function test_closing_shift_computes_expected_cash_and_variance_counting_only_cash(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        $item = $this->makeItem($tenant, sellingPrice: 20);
        $this->stockUp($tenant, $branch, $item, 10);

        Volt::test('pages.pos.index')->set('opening_float', '100')->call('startShift');

        // Sale 1: split cash(20) + mobile_money(20) for a 2-unit sale.
        Volt::test('pages.pos.index')
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity', '2')
            ->set('payments.0.method', 'cash')
            ->set('payments.0.amount', '20')
            ->call('addPayment')
            ->set('payments.1.method', 'mobile_money')
            ->set('payments.1.amount', '20')
            ->call('completeSale');

        $shift = Shift::where('tenant_id', $tenant->id)->firstOrFail();

        // expected_cash = opening_float(100) + cash payments(20) = 120.
        Volt::test('pages.pos.index')
            ->set('closing_float_counted', '115')
            ->call('endShift');

        $shift->refresh();
        $this->assertEquals('closed', $shift->status);
        $this->assertEquals(120, $shift->expected_cash);
        $this->assertEquals(-5, $shift->variance);
    }

    public function test_store_keeper_cannot_reach_pos(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $storeKeeper = $this->createUserWithRole($tenant, $branch, 'Store Keeper');

        $this->actingAs($storeKeeper)->get('/pos')->assertForbidden();
    }

    public function test_sales_list_is_tenant_isolated(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $otherTenant = Tenant::factory()->create();
        $otherBranch = Branch::factory()->main()->create(['tenant_id' => $otherTenant->id]);
        $otherShift = Shift::factory()->create(['tenant_id' => $otherTenant->id, 'branch_id' => $otherBranch->id]);
        Sale::factory()->create([
            'tenant_id' => $otherTenant->id,
            'branch_id' => $otherBranch->id,
            'shift_id' => $otherShift->id,
            'receipt_number' => 'OTHER-2026-000001',
        ]);

        Volt::test('pages.sales.index')->assertDontSee('OTHER-2026-000001');
    }
}
