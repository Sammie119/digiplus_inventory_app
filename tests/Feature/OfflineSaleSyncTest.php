<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
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

class OfflineSaleSyncTest extends TestCase
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
            'quantity_on_hand' => $qty,
        ]);
    }

    public function test_syncing_a_valid_offline_sale_creates_it(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        $item = $this->makeItem($tenant, sellingPrice: 20);
        $this->stockUp($tenant, $branch, $item, 10);

        Volt::test('pages.pos.index')->set('opening_float', '100')->call('startShift');

        Volt::test('pages.pos.index')->call('syncOfflineSale', [
            'uuid' => 'a1111111-1111-1111-1111-111111111111',
            'lines' => [['item_id' => $item->id, 'quantity' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 60]],
        ]);

        $sale = Sale::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('offline', $sale->sync_source);
        $this->assertFalse($sale->offline_flagged_oversell);
        $this->assertStringStartsWith('MAIN-'.now()->year.'-', $sale->receipt_number);

        $stockLevel = StockLevel::where('item_id', $item->id)->where('branch_id', $branch->id)->firstOrFail();
        $this->assertEquals(7, $stockLevel->quantity_on_hand);
    }

    public function test_syncing_an_offline_sale_that_exceeds_stock_is_not_blocked_but_flagged(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        $item = $this->makeItem($tenant, sellingPrice: 20);
        $this->stockUp($tenant, $branch, $item, 2);

        Volt::test('pages.pos.index')->set('opening_float', '100')->call('startShift');

        Volt::test('pages.pos.index')->call('syncOfflineSale', [
            'uuid' => 'a2222222-2222-2222-2222-222222222222',
            'lines' => [['item_id' => $item->id, 'quantity' => 5]],
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ]);

        $sale = Sale::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertTrue($sale->offline_flagged_oversell);

        $stockLevel = StockLevel::where('item_id', $item->id)->where('branch_id', $branch->id)->firstOrFail();
        $this->assertEquals(-3, $stockLevel->quantity_on_hand);
    }

    public function test_syncing_the_same_uuid_twice_only_creates_one_sale(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        $item = $this->makeItem($tenant, sellingPrice: 20);
        $this->stockUp($tenant, $branch, $item, 10);

        Volt::test('pages.pos.index')->set('opening_float', '100')->call('startShift');

        $payload = [
            'uuid' => 'a3333333-3333-3333-3333-333333333333',
            'lines' => [['item_id' => $item->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 20]],
        ];

        Volt::test('pages.pos.index')->call('syncOfflineSale', $payload);
        Volt::test('pages.pos.index')->call('syncOfflineSale', $payload);

        $this->assertSame(1, Sale::where('uuid', $payload['uuid'])->count());
    }

    public function test_store_keeper_cannot_sync_offline_sales(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $storeKeeper = $this->createUserWithRole($tenant, $branch, 'Store Keeper');

        // 'sales.create' (required by both the POS page's mount() and
        // syncOfflineSale()) isn't in Store Keeper's permission set, so the
        // page itself is already unreachable — mirrors
        // PosManagementTest::test_store_keeper_cannot_reach_pos.
        $this->actingAs($storeKeeper)->get('/pos')->assertForbidden();
    }

    public function test_receipt_numbers_stay_sequential_across_online_and_offline_sales(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        $item = $this->makeItem($tenant, sellingPrice: 20);
        $this->stockUp($tenant, $branch, $item, 10);

        Volt::test('pages.pos.index')->set('opening_float', '100')->call('startShift');

        Volt::test('pages.pos.index')
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity', '1')
            ->set('payments.0.method', 'cash')
            ->set('payments.0.amount', '20')
            ->call('completeSale');

        Volt::test('pages.pos.index')->call('syncOfflineSale', [
            'uuid' => 'a5555555-5555-5555-5555-555555555555',
            'lines' => [['item_id' => $item->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 20]],
        ]);

        $numbers = Sale::where('tenant_id', $tenant->id)->orderBy('id')->pluck('receipt_number');

        $this->assertStringEndsWith('000001', $numbers[0]);
        $this->assertStringEndsWith('000002', $numbers[1]);
    }
}
