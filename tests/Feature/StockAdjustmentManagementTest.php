<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StockAdjustmentManagementTest extends TestCase
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

    public function test_business_owner_can_view_stock_adjustments_index(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->createUserWithRole($tenant, $branch, 'Business Owner');

        $this->actingAs($owner)
            ->get('/stock-adjustments')
            ->assertOk()
            ->assertSeeVolt('pages.stock-adjustments.index');
    }

    public function test_store_keeper_can_create_pending_adjustment(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $keeper = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Store Keeper'));

        $item = $this->makeItem($tenant);

        Volt::test('pages.stock-adjustments.index')
            ->set('branch_id', $branch->id)
            ->set('item_id', $item->id)
            ->set('quantity', '3')
            ->set('direction', 'decrease')
            ->set('reason_code', 'damage')
            ->call('createAdjustment');

        $this->assertDatabaseHas('stock_adjustments', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity_delta' => -3,
            'reason_code' => 'damage',
            'status' => 'pending',
            'requested_by' => $keeper->id,
        ]);
    }

    public function test_cashier_cannot_create_adjustment(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        Volt::test('pages.stock-adjustments.index')
            ->call('openCreateModal')
            ->assertForbidden();
    }

    public function test_approving_increase_adjustment_creates_stock_movement_and_increments_stock(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $item = $this->makeItem($tenant, costPrice: 15);
        $adjustment = StockAdjustment::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'batch_id' => null,
            'quantity_delta' => 8,
            'reason_code' => 'count_correction',
            'status' => 'pending',
            'requested_by' => $owner->id,
        ]);

        Volt::test('pages.stock-adjustments.index')
            ->call('approve', $adjustment->id)
            ->assertDispatched('toast', message: 'Adjustment approved.', type: 'success');

        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'movement_type' => 'adjustment',
            'quantity' => 8,
            'reference_type' => StockAdjustment::class,
            'reference_id' => $adjustment->id,
            'performed_by' => $owner->id,
        ]);

        $stockLevel = StockLevel::where('item_id', $item->id)->where('branch_id', $branch->id)->firstOrFail();
        $this->assertEquals(8, $stockLevel->quantity_on_hand);

        $this->assertDatabaseHas('stock_adjustments', [
            'id' => $adjustment->id,
            'status' => 'approved',
            'approved_by' => $owner->id,
        ]);
    }

    public function test_approving_decrease_adjustment_with_sufficient_stock_decrements_stock(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $item = $this->makeItem($tenant);
        StockLevel::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'batch_id' => null,
            'quantity_on_hand' => 20,
        ]);

        $adjustment = StockAdjustment::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'batch_id' => null,
            'quantity_delta' => -6,
            'reason_code' => 'write_off',
            'status' => 'pending',
            'requested_by' => $owner->id,
        ]);

        Volt::test('pages.stock-adjustments.index')->call('approve', $adjustment->id);

        $stockLevel = StockLevel::where('item_id', $item->id)->where('branch_id', $branch->id)->firstOrFail();
        $this->assertEquals(14, $stockLevel->quantity_on_hand);

        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $item->id,
            'movement_type' => 'adjustment',
            'quantity' => -6,
        ]);
    }

    public function test_approving_decrease_beyond_available_stock_fails_validation(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $item = $this->makeItem($tenant);
        StockLevel::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'batch_id' => null,
            'quantity_on_hand' => 5,
        ]);

        $adjustment = StockAdjustment::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'batch_id' => null,
            'quantity_delta' => -10,
            'status' => 'pending',
            'requested_by' => $owner->id,
        ]);

        Volt::test('pages.stock-adjustments.index')
            ->call('approve', $adjustment->id)
            ->assertHasErrors(['approve']);

        $this->assertDatabaseHas('stock_adjustments', ['id' => $adjustment->id, 'status' => 'pending']);
        $this->assertDatabaseMissing('stock_movements', [
            'reference_type' => StockAdjustment::class,
            'reference_id' => $adjustment->id,
        ]);
    }

    public function test_store_keeper_cannot_approve_adjustment(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $keeper = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Store Keeper'));

        $item = $this->makeItem($tenant);
        $adjustment = StockAdjustment::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'batch_id' => null,
            'quantity_delta' => 4,
            'status' => 'pending',
            'requested_by' => $keeper->id,
        ]);

        Volt::test('pages.stock-adjustments.index')
            ->call('approve', $adjustment->id)
            ->assertForbidden();
    }

    public function test_rejecting_pending_adjustment_has_no_stock_effect(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $item = $this->makeItem($tenant);
        $adjustment = StockAdjustment::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'batch_id' => null,
            'quantity_delta' => 4,
            'status' => 'pending',
            'requested_by' => $owner->id,
        ]);

        Volt::test('pages.stock-adjustments.index')->call('reject', $adjustment->id);

        $this->assertDatabaseHas('stock_adjustments', [
            'id' => $adjustment->id,
            'status' => 'rejected',
            'approved_by' => $owner->id,
        ]);
        $this->assertDatabaseMissing('stock_movements', [
            'reference_type' => StockAdjustment::class,
            'reference_id' => $adjustment->id,
        ]);
        $this->assertDatabaseMissing('stock_levels', ['item_id' => $item->id]);
    }

    public function test_approving_already_resolved_adjustment_is_forbidden(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $item = $this->makeItem($tenant);
        $adjustment = StockAdjustment::factory()->approved()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'batch_id' => null,
            'quantity_delta' => 4,
            'requested_by' => $owner->id,
            'approved_by' => $owner->id,
        ]);

        Volt::test('pages.stock-adjustments.index')
            ->call('approve', $adjustment->id)
            ->assertForbidden();
    }

    public function test_adjustment_from_another_tenant_is_not_reachable(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $otherTenant = Tenant::factory()->create();
        $otherBranch = Branch::factory()->main()->create(['tenant_id' => $otherTenant->id]);
        $otherItem = $this->makeItem($otherTenant);
        $otherAdjustment = StockAdjustment::factory()->create([
            'tenant_id' => $otherTenant->id,
            'branch_id' => $otherBranch->id,
            'item_id' => $otherItem->id,
            'batch_id' => null,
            'requested_by' => User::factory()->create(['tenant_id' => $otherTenant->id, 'branch_id' => $otherBranch->id])->id,
        ]);

        $this->expectException(ModelNotFoundException::class);

        Volt::test('pages.stock-adjustments.index')->call('approve', $otherAdjustment->id);
    }
}
