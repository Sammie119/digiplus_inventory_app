<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Item;
use App\Models\Product;
use App\Models\Sale;
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

class CustomerManagementTest extends TestCase
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

    private function makeItem(Tenant $tenant, float $sellingPrice = 20): Item
    {
        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);

        return Item::factory()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'base_unit_id' => $unit->id,
            'selling_price' => $sellingPrice,
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

    public function test_cashier_can_create_a_customer(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        Volt::test('pages.customers.index')
            ->set('name', 'Jane Doe')
            ->set('phone', '0551234567')
            ->set('credit_limit', '500')
            ->call('save');

        $this->assertDatabaseHas('customers', [
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'phone' => '0551234567',
            'credit_limit' => 500,
            'credit_balance' => 0,
        ]);
    }

    public function test_store_keeper_cannot_view_customers(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $storeKeeper = $this->createUserWithRole($tenant, $branch, 'Store Keeper');

        $this->actingAs($storeKeeper)->get('/customers')->assertForbidden();
    }

    public function test_completing_credit_sale_requires_a_customer(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        $item = $this->makeItem($tenant, sellingPrice: 20);
        $this->stockUp($tenant, $branch, $item, 10);

        Volt::test('pages.pos.index')->set('opening_float', '0')->call('startShift');

        Volt::test('pages.pos.index')
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity', '1')
            ->set('payments.0.method', 'credit')
            ->set('payments.0.amount', '20')
            ->call('completeSale')
            ->assertHasErrors(['customer_id']);

        $this->assertDatabaseMissing('sales', ['tenant_id' => $tenant->id]);
    }

    public function test_completing_credit_sale_increments_balance_and_logs_ledger_entry(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        $item = $this->makeItem($tenant, sellingPrice: 20);
        $this->stockUp($tenant, $branch, $item, 10);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'credit_balance' => 0]);

        Volt::test('pages.pos.index')->set('opening_float', '0')->call('startShift');

        Volt::test('pages.pos.index')
            ->set('customer_id', $customer->id)
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity', '2')
            ->set('payments.0.method', 'credit')
            ->set('payments.0.amount', '40')
            ->call('completeSale');

        $sale = Sale::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertEquals($customer->id, $sale->customer_id);

        $customer->refresh();
        $this->assertEquals(40, $customer->credit_balance);

        $this->assertDatabaseHas('customer_ledger_entries', [
            'customer_id' => $customer->id,
            'entry_type' => 'debit_sale',
            'amount' => 40,
            'balance_after' => 40,
            'reference_type' => Sale::class,
            'reference_id' => $sale->id,
        ]);
    }

    public function test_credit_sale_exceeding_limit_fails_validation(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        $item = $this->makeItem($tenant, sellingPrice: 20);
        $this->stockUp($tenant, $branch, $item, 10);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'credit_balance' => 90, 'credit_limit' => 100]);

        Volt::test('pages.pos.index')->set('opening_float', '0')->call('startShift');

        Volt::test('pages.pos.index')
            ->set('customer_id', $customer->id)
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity', '1')
            ->set('payments.0.method', 'credit')
            ->set('payments.0.amount', '20')
            ->call('completeSale')
            ->assertHasErrors(['customer_id']);

        $this->assertDatabaseMissing('sales', ['tenant_id' => $tenant->id]);
        $this->assertEquals(90, $customer->fresh()->credit_balance);
    }

    public function test_recording_a_payment_reduces_balance_and_logs_entry(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'credit_balance' => 100]);

        Volt::test('pages.customers.index')
            ->set('paymentAmount', '60')
            ->call('recordPayment', $customer->id);

        $customer->refresh();
        $this->assertEquals(40, $customer->credit_balance);

        $this->assertDatabaseHas('customer_ledger_entries', [
            'customer_id' => $customer->id,
            'entry_type' => 'credit_payment',
            'amount' => 60,
            'balance_after' => 40,
        ]);
    }

    public function test_recording_a_payment_beyond_balance_fails_validation(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'credit_balance' => 30]);

        Volt::test('pages.customers.index')
            ->set('paymentAmount', '50')
            ->call('recordPayment', $customer->id)
            ->assertHasErrors(['paymentAmount']);

        $this->assertEquals(30, $customer->fresh()->credit_balance);
    }

    public function test_voiding_credit_sale_reverses_customer_balance(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->createUserWithRole($tenant, $branch, 'Cashier');
        $manager = $this->createUserWithRole($tenant, $branch, 'Branch Manager');

        $item = $this->makeItem($tenant, sellingPrice: 20);
        $this->stockUp($tenant, $branch, $item, 10);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'credit_balance' => 0]);

        $this->loginAs($cashier);
        Volt::test('pages.pos.index')->set('opening_float', '0')->call('startShift');
        Volt::test('pages.pos.index')
            ->set('customer_id', $customer->id)
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity', '2')
            ->set('payments.0.method', 'credit')
            ->set('payments.0.amount', '40')
            ->call('completeSale');

        $sale = Sale::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertEquals(40, $customer->fresh()->credit_balance);

        Volt::test('pages.sales.index')
            ->call('openVoidModal', $sale->id)
            ->set('voidReason', 'Mistake')
            ->call('requestVoid');

        $this->loginAs($manager);
        Volt::test('pages.sales.index')->call('resolveVoid', $sale->id, true);

        $customer->refresh();
        $this->assertEquals(0, $customer->credit_balance);

        $this->assertDatabaseHas('customer_ledger_entries', [
            'customer_id' => $customer->id,
            'entry_type' => 'void_reversal',
            'amount' => 40,
            'balance_after' => 0,
        ]);
    }

    public function test_voiding_non_credit_sale_does_not_touch_customer_ledger(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->createUserWithRole($tenant, $branch, 'Cashier');
        $manager = $this->createUserWithRole($tenant, $branch, 'Branch Manager');

        $item = $this->makeItem($tenant, sellingPrice: 20);
        $this->stockUp($tenant, $branch, $item, 10);
        Customer::factory()->create(['tenant_id' => $tenant->id]);

        $this->loginAs($cashier);
        Volt::test('pages.pos.index')->set('opening_float', '0')->call('startShift');
        Volt::test('pages.pos.index')
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity', '1')
            ->set('payments.0.method', 'cash')
            ->set('payments.0.amount', '20')
            ->call('completeSale');

        $sale = Sale::where('tenant_id', $tenant->id)->firstOrFail();

        Volt::test('pages.sales.index')
            ->call('openVoidModal', $sale->id)
            ->set('voidReason', 'Mistake')
            ->call('requestVoid');

        $this->loginAs($manager);
        Volt::test('pages.sales.index')->call('resolveVoid', $sale->id, true);

        $this->assertDatabaseCount('customer_ledger_entries', 0);
    }

    public function test_customer_list_is_tenant_isolated(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $otherTenant = Tenant::factory()->create();
        Customer::factory()->create(['tenant_id' => $otherTenant->id, 'name' => 'Other Tenant Customer']);

        Volt::test('pages.customers.index')->assertDontSee('Other Tenant Customer');
    }
}
