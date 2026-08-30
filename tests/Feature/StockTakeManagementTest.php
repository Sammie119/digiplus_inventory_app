<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Branch;
use App\Models\Category;
use App\Models\FinancialYear;
use App\Models\Item;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockTake;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StockTakeManagementTest extends TestCase
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

    private function makeItem(Tenant $tenant, float $costPrice = 10, bool $tracksBatches = false): Item
    {
        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);

        return Item::factory()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'base_unit_id' => $unit->id,
            'cost_price' => $costPrice,
            'track_batches' => $tracksBatches,
            'status' => 'active',
        ]);
    }

    private function openFinancialYear(Tenant $tenant): FinancialYear
    {
        return FinancialYear::factory()->create(['tenant_id' => $tenant->id, 'status' => 'open']);
    }

    public function test_starting_a_stock_take_pre_populates_every_active_item_with_its_system_quantity(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Store Keeper'));

        $this->openFinancialYear($tenant);
        $stockedItem = $this->makeItem($tenant);
        $unstockedItem = $this->makeItem($tenant);
        $discontinuedItem = $this->makeItem($tenant);
        $discontinuedItem->update(['status' => 'discontinued']);

        StockLevel::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'item_id' => $stockedItem->id, 'batch_id' => null, 'quantity_on_hand' => 42]);

        Volt::test('pages.stock-takings.show')
            ->set('branch_id', $branch->id)
            ->call('start');

        $stockTake = StockTake::firstOrFail();

        $this->assertSame('in_progress', $stockTake->status);
        $this->assertNotNull($stockTake->started_at);
        $this->assertMatchesRegularExpression('/^ST-\d{6}$/', $stockTake->reference);

        $this->assertDatabaseHas('stock_take_items', [
            'stock_take_id' => $stockTake->id, 'item_id' => $stockedItem->id, 'system_quantity' => 42, 'counted_quantity' => null,
        ]);
        $this->assertDatabaseHas('stock_take_items', [
            'stock_take_id' => $stockTake->id, 'item_id' => $unstockedItem->id, 'system_quantity' => 0, 'counted_quantity' => null,
        ]);
        $this->assertDatabaseMissing('stock_take_items', ['stock_take_id' => $stockTake->id, 'item_id' => $discontinuedItem->id]);
    }

    public function test_starting_a_stock_take_creates_one_line_per_batch_for_batch_tracked_items(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Store Keeper'));

        $this->openFinancialYear($tenant);
        $item = $this->makeItem($tenant, tracksBatches: true);
        $batchA = Batch::create(['tenant_id' => $tenant->id, 'item_id' => $item->id, 'batch_number' => 'A']);
        $batchB = Batch::create(['tenant_id' => $tenant->id, 'item_id' => $item->id, 'batch_number' => 'B']);

        StockLevel::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'item_id' => $item->id, 'batch_id' => $batchA->id, 'quantity_on_hand' => 5]);
        StockLevel::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'item_id' => $item->id, 'batch_id' => $batchB->id, 'quantity_on_hand' => 9]);

        Volt::test('pages.stock-takings.show')
            ->set('branch_id', $branch->id)
            ->call('start');

        $stockTake = StockTake::firstOrFail();

        $this->assertDatabaseHas('stock_take_items', ['stock_take_id' => $stockTake->id, 'item_id' => $item->id, 'batch_id' => $batchA->id, 'system_quantity' => 5]);
        $this->assertDatabaseHas('stock_take_items', ['stock_take_id' => $stockTake->id, 'item_id' => $item->id, 'batch_id' => $batchB->id, 'system_quantity' => 9]);
        $this->assertSame(2, $stockTake->items()->count());
    }

    public function test_starting_without_an_open_financial_year_is_blocked(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Store Keeper'));

        Volt::test('pages.stock-takings.show')
            ->set('branch_id', $branch->id)
            ->call('start')
            ->assertHasErrors('branch_id');

        $this->assertDatabaseCount('stock_takes', 0);
    }

    public function test_saving_progress_persists_partial_counts_without_changing_status(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $financialYear = $this->openFinancialYear($tenant);
        $itemA = $this->makeItem($tenant);
        $itemB = $this->makeItem($tenant);

        $stockTake = StockTake::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'financial_year_id' => $financialYear->id, 'created_by' => $owner->id]);
        $lineA = $stockTake->items()->create(['tenant_id' => $tenant->id, 'item_id' => $itemA->id, 'system_quantity' => 10]);
        $lineB = $stockTake->items()->create(['tenant_id' => $tenant->id, 'item_id' => $itemB->id, 'system_quantity' => 20]);

        Volt::test('pages.stock-takings.show', ['stockTake' => $stockTake])
            ->set('lines.0.counted_quantity', '12')
            ->set('lines.0.notes', 'Counted twice')
            ->call('saveProgress')
            ->assertDispatched('toast', message: 'Progress saved.', type: 'success');

        $this->assertDatabaseHas('stock_take_items', ['id' => $lineA->id, 'counted_quantity' => 12, 'variance' => 2, 'notes' => 'Counted twice']);
        $this->assertDatabaseHas('stock_take_items', ['id' => $lineB->id, 'counted_quantity' => null]);
        $this->assertDatabaseHas('stock_takes', ['id' => $stockTake->id, 'status' => 'in_progress']);
    }

    public function test_completing_is_blocked_while_any_line_is_uncounted(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $financialYear = $this->openFinancialYear($tenant);
        $item = $this->makeItem($tenant);

        $stockTake = StockTake::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'financial_year_id' => $financialYear->id, 'created_by' => $owner->id]);
        $stockTake->items()->create(['tenant_id' => $tenant->id, 'item_id' => $item->id, 'system_quantity' => 10]);

        Volt::test('pages.stock-takings.show', ['stockTake' => $stockTake])
            ->call('complete')
            ->assertDispatched('toast', type: 'error');

        $this->assertDatabaseHas('stock_takes', ['id' => $stockTake->id, 'status' => 'in_progress']);
    }

    public function test_completing_with_all_lines_counted_moves_to_pending_approval(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $financialYear = $this->openFinancialYear($tenant);
        $item = $this->makeItem($tenant);

        $stockTake = StockTake::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'financial_year_id' => $financialYear->id, 'created_by' => $owner->id]);
        $stockTake->items()->create(['tenant_id' => $tenant->id, 'item_id' => $item->id, 'system_quantity' => 10]);

        Volt::test('pages.stock-takings.show', ['stockTake' => $stockTake])
            ->set('lines.0.counted_quantity', '10')
            ->call('complete')
            ->assertDispatched('toast', message: 'Stock take completed and sent for approval.', type: 'success');

        $stockTake->refresh();
        $this->assertSame('pending_approval', $stockTake->status);
        $this->assertNotNull($stockTake->completed_at);
    }

    public function test_cancelling_an_in_progress_stock_take_has_no_stock_effect(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $financialYear = $this->openFinancialYear($tenant);
        $item = $this->makeItem($tenant);

        $stockTake = StockTake::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'financial_year_id' => $financialYear->id, 'created_by' => $owner->id]);
        $stockTake->items()->create(['tenant_id' => $tenant->id, 'item_id' => $item->id, 'system_quantity' => 10]);

        Volt::test('pages.stock-takings.show', ['stockTake' => $stockTake])->call('cancel');

        $this->assertDatabaseHas('stock_takes', ['id' => $stockTake->id, 'status' => 'cancelled']);
        $this->assertDatabaseMissing('stock_movements', ['reference_type' => StockTake::class, 'reference_id' => $stockTake->id]);
    }

    public function test_approving_applies_variance_as_stock_movements_and_updates_stock_levels(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $financialYear = $this->openFinancialYear($tenant);
        $increasedItem = $this->makeItem($tenant, costPrice: 12);
        $unchangedItem = $this->makeItem($tenant);

        StockLevel::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'item_id' => $increasedItem->id, 'batch_id' => null, 'quantity_on_hand' => 5]);
        StockLevel::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'item_id' => $unchangedItem->id, 'batch_id' => null, 'quantity_on_hand' => 15]);

        $stockTake = StockTake::factory()->pendingApproval()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'financial_year_id' => $financialYear->id, 'created_by' => $owner->id,
        ]);
        $stockTake->items()->create(['tenant_id' => $tenant->id, 'item_id' => $increasedItem->id, 'system_quantity' => 5, 'counted_quantity' => 9, 'variance' => 4]);
        $stockTake->items()->create(['tenant_id' => $tenant->id, 'item_id' => $unchangedItem->id, 'system_quantity' => 15, 'counted_quantity' => 15, 'variance' => 0]);

        Volt::test('pages.stock-takings.show', ['stockTake' => $stockTake])
            ->call('approve')
            ->assertDispatched('toast', message: 'Stock take approved — stock levels updated.', type: 'success');

        $this->assertDatabaseHas('stock_takes', ['id' => $stockTake->id, 'status' => 'approved', 'approved_by' => $owner->id]);
        $this->assertEquals(9, StockLevel::where('item_id', $increasedItem->id)->value('quantity_on_hand'));
        $this->assertEquals(15, StockLevel::where('item_id', $unchangedItem->id)->value('quantity_on_hand'));

        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $increasedItem->id, 'movement_type' => 'adjustment', 'quantity' => 4,
            'reason_code' => 'count_correction', 'reference_type' => StockTake::class, 'reference_id' => $stockTake->id,
        ]);
        $this->assertDatabaseMissing('stock_movements', ['item_id' => $unchangedItem->id]);
    }

    public function test_approving_a_decrease_beyond_available_stock_is_blocked(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $financialYear = $this->openFinancialYear($tenant);
        $item = $this->makeItem($tenant);
        StockLevel::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'item_id' => $item->id, 'batch_id' => null, 'quantity_on_hand' => 3]);

        $stockTake = StockTake::factory()->pendingApproval()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'financial_year_id' => $financialYear->id, 'created_by' => $owner->id,
        ]);
        $stockTake->items()->create(['tenant_id' => $tenant->id, 'item_id' => $item->id, 'system_quantity' => 5, 'counted_quantity' => 0, 'variance' => -5]);

        Volt::test('pages.stock-takings.show', ['stockTake' => $stockTake])
            ->call('approve')
            ->assertHasErrors(['approve']);

        $this->assertDatabaseHas('stock_takes', ['id' => $stockTake->id, 'status' => 'pending_approval']);
    }

    public function test_approving_a_stock_take_still_in_progress_is_forbidden(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $financialYear = $this->openFinancialYear($tenant);
        $item = $this->makeItem($tenant);

        $stockTake = StockTake::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'financial_year_id' => $financialYear->id, 'created_by' => $owner->id]);
        $stockTake->items()->create(['tenant_id' => $tenant->id, 'item_id' => $item->id, 'system_quantity' => 5]);

        Volt::test('pages.stock-takings.show', ['stockTake' => $stockTake])
            ->call('approve')
            ->assertForbidden();
    }

    public function test_store_keeper_cannot_approve_a_stock_take(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $keeper = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Store Keeper'));

        $financialYear = $this->openFinancialYear($tenant);
        $item = $this->makeItem($tenant);

        $stockTake = StockTake::factory()->pendingApproval()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'financial_year_id' => $financialYear->id, 'created_by' => $keeper->id,
        ]);
        $stockTake->items()->create(['tenant_id' => $tenant->id, 'item_id' => $item->id, 'system_quantity' => 5, 'counted_quantity' => 5, 'variance' => 0]);

        Volt::test('pages.stock-takings.show', ['stockTake' => $stockTake])
            ->call('approve')
            ->assertForbidden();
    }

    public function test_rejecting_a_pending_approval_stock_take_has_no_stock_effect(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $financialYear = $this->openFinancialYear($tenant);
        $item = $this->makeItem($tenant);

        $stockTake = StockTake::factory()->pendingApproval()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'financial_year_id' => $financialYear->id, 'created_by' => $owner->id,
        ]);
        $stockTake->items()->create(['tenant_id' => $tenant->id, 'item_id' => $item->id, 'system_quantity' => 5, 'counted_quantity' => 9, 'variance' => 4]);

        Volt::test('pages.stock-takings.show', ['stockTake' => $stockTake])->call('reject');

        $this->assertDatabaseHas('stock_takes', ['id' => $stockTake->id, 'status' => 'cancelled', 'approved_by' => $owner->id]);
        $this->assertDatabaseMissing('stock_movements', ['reference_type' => StockTake::class, 'reference_id' => $stockTake->id]);
    }

    public function test_stock_take_from_another_tenant_is_not_reachable(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $otherTenant = Tenant::factory()->licensed()->create();
        $otherBranch = Branch::factory()->main()->create(['tenant_id' => $otherTenant->id]);
        $otherFinancialYear = FinancialYear::factory()->create(['tenant_id' => $otherTenant->id, 'status' => 'open']);
        $otherOwner = User::factory()->create(['tenant_id' => $otherTenant->id, 'branch_id' => $otherBranch->id]);

        $otherStockTake = StockTake::factory()->create([
            'tenant_id' => $otherTenant->id, 'branch_id' => $otherBranch->id, 'financial_year_id' => $otherFinancialYear->id, 'created_by' => $otherOwner->id,
        ]);

        $this->actingAs($owner)
            ->get("/stock-takings/{$otherStockTake->id}")
            ->assertNotFound();
    }
}
