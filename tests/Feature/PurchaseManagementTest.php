<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PurchaseManagementTest extends TestCase
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

    private function makeItem(Tenant $tenant, float $costPrice = 10): Item
    {
        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);

        return Item::factory()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'base_unit_id' => $unit->id,
            'cost_price' => $costPrice,
        ]);
    }

    public function test_business_owner_can_view_purchases_index(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->createUserWithRole($tenant, $branch, 'Business Owner');

        $this->actingAs($owner)
            ->get('/purchases')
            ->assertOk()
            ->assertSeeVolt('pages.purchases.index');
    }

    public function test_business_owner_can_save_a_draft_purchase(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $supplier = Supplier::factory()->create(['tenant_id' => $tenant->id]);
        $item = $this->makeItem($tenant);

        Volt::test('pages.purchases.show')
            ->set('branch_id', $branch->id)
            ->set('supplier_id', $supplier->id)
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity', '10')
            ->set('lines.0.unit_cost', '20')
            ->call('saveDraft');

        $this->assertDatabaseHas('purchases', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'total_cost' => 200,
        ]);
        $this->assertDatabaseHas('purchase_items', [
            'item_id' => $item->id,
            'quantity' => 10,
            'unit_cost' => 20,
        ]);
    }

    public function test_receiving_purchase_creates_stock_movement_and_stock_level(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $supplier = Supplier::factory()->create(['tenant_id' => $tenant->id]);
        $item = $this->makeItem($tenant, costPrice: 10);

        $component = Volt::test('pages.purchases.show')
            ->set('branch_id', $branch->id)
            ->set('supplier_id', $supplier->id)
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity', '10')
            ->set('lines.0.unit_cost', '20')
            ->call('saveDraft');

        $purchase = Purchase::where('tenant_id', $tenant->id)->firstOrFail();

        Volt::test('pages.purchases.show', ['purchase' => $purchase])->call('receive');

        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'movement_type' => 'purchase',
            'quantity' => 10,
            'unit_cost' => 20,
            'reference_type' => Purchase::class,
            'reference_id' => $purchase->id,
            'performed_by' => $owner->id,
        ]);

        $stockLevel = StockLevel::where('item_id', $item->id)->where('branch_id', $branch->id)->firstOrFail();
        $this->assertEquals(10, $stockLevel->quantity_on_hand);

        $this->assertDatabaseHas('purchases', ['id' => $purchase->id, 'status' => 'received']);
        $this->assertSame($owner->id, $purchase->fresh()->received_by);
    }

    public function test_receiving_purchase_recalculates_weighted_average_cost(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $supplier = Supplier::factory()->create(['tenant_id' => $tenant->id]);
        $item = $this->makeItem($tenant, costPrice: 10);

        // First receipt: 10 units @ 20 into an item with no existing stock -> cost becomes 20.
        Volt::test('pages.purchases.show')
            ->set('branch_id', $branch->id)
            ->set('supplier_id', $supplier->id)
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity', '10')
            ->set('lines.0.unit_cost', '20')
            ->call('saveDraft');

        $firstPurchase = Purchase::where('tenant_id', $tenant->id)->firstOrFail();
        Volt::test('pages.purchases.show', ['purchase' => $firstPurchase])->call('receive');

        $this->assertEquals(20, $item->fresh()->cost_price);

        // Second receipt: 10 more units @ 30 -> weighted average (10*20 + 10*30) / 20 = 25.
        Volt::test('pages.purchases.show')
            ->set('branch_id', $branch->id)
            ->set('supplier_id', $supplier->id)
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity', '10')
            ->set('lines.0.unit_cost', '30')
            ->call('saveDraft');

        $secondPurchase = Purchase::where('tenant_id', $tenant->id)->where('id', '!=', $firstPurchase->id)->firstOrFail();
        Volt::test('pages.purchases.show', ['purchase' => $secondPurchase])->call('receive');

        $this->assertEquals(25, $item->fresh()->cost_price);

        $totalOnHand = StockLevel::where('item_id', $item->id)->sum('quantity_on_hand');
        $this->assertEquals(20, $totalOnHand);
    }

    public function test_receiving_purchase_creates_batch_when_item_tracks_batches(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $supplier = Supplier::factory()->create(['tenant_id' => $tenant->id]);
        $item = $this->makeItem($tenant);
        $item->update(['track_batches' => true]);

        Volt::test('pages.purchases.show')
            ->set('branch_id', $branch->id)
            ->set('supplier_id', $supplier->id)
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity', '5')
            ->set('lines.0.unit_cost', '12')
            ->set('lines.0.batch_number', 'BATCH-X')
            ->set('lines.0.expiry_date', '2027-01-01')
            ->call('saveDraft');

        $purchase = Purchase::where('tenant_id', $tenant->id)->firstOrFail();

        $this->assertDatabaseHas('batches', [
            'item_id' => $item->id,
            'batch_number' => 'BATCH-X',
        ]);

        Volt::test('pages.purchases.show', ['purchase' => $purchase])->call('receive');

        $movement = StockMovement::where('item_id', $item->id)->firstOrFail();
        $this->assertNotNull($movement->batch_id);
    }

    public function test_received_purchase_cannot_be_edited_or_received_again(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $supplier = Supplier::factory()->create(['tenant_id' => $tenant->id]);
        $item = $this->makeItem($tenant);

        Volt::test('pages.purchases.show')
            ->set('branch_id', $branch->id)
            ->set('supplier_id', $supplier->id)
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity', '5')
            ->set('lines.0.unit_cost', '12')
            ->call('saveDraft');

        $purchase = Purchase::where('tenant_id', $tenant->id)->firstOrFail();
        Volt::test('pages.purchases.show', ['purchase' => $purchase])->call('receive');

        Volt::test('pages.purchases.show', ['purchase' => $purchase->fresh()])
            ->call('receive')
            ->assertForbidden();
    }

    public function test_cashier_cannot_create_purchase(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->createUserWithRole($tenant, $branch, 'Cashier');

        $this->actingAs($cashier)->get('/purchases/create')->assertForbidden();
    }

    public function test_purchase_from_another_tenant_is_not_reachable(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->createUserWithRole($tenant, $branch, 'Business Owner');

        $otherTenant = Tenant::factory()->licensed()->create();
        $otherBranch = Branch::factory()->main()->create(['tenant_id' => $otherTenant->id]);
        $otherSupplier = Supplier::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherPurchase = Purchase::factory()->create([
            'tenant_id' => $otherTenant->id,
            'branch_id' => $otherBranch->id,
            'supplier_id' => $otherSupplier->id,
        ]);

        $this->actingAs($owner)
            ->get("/purchases/{$otherPurchase->uuid}")
            ->assertNotFound();
    }
}
