<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockTransfer;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StockTransferManagementTest extends TestCase
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

    public function test_source_branch_manager_can_create_and_ship_transfer(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $sourceBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $destBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager = $this->loginAs($this->createUserWithRole($tenant, $sourceBranch, 'Branch Manager'));

        $item = $this->makeItem($tenant, costPrice: 12);
        $this->stockUp($tenant, $sourceBranch, $item, 20);

        Volt::test('pages.stock-transfers.show')
            ->set('from_branch_id', $sourceBranch->id)
            ->set('to_branch_id', $destBranch->id)
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity_sent', '8')
            ->call('saveDraft');

        $transfer = StockTransfer::where('tenant_id', $tenant->id)->firstOrFail();

        Volt::test('pages.stock-transfers.show', ['stockTransfer' => $transfer])->call('ship');

        $this->assertDatabaseHas('stock_movements', [
            'branch_id' => $sourceBranch->id,
            'item_id' => $item->id,
            'movement_type' => 'transfer_out',
            'quantity' => -8,
            'performed_by' => $manager->id,
        ]);

        $sourceLevel = StockLevel::where('branch_id', $sourceBranch->id)->where('item_id', $item->id)->firstOrFail();
        $this->assertEquals(12, $sourceLevel->quantity_on_hand);

        $destLevel = StockLevel::where('branch_id', $destBranch->id)->where('item_id', $item->id)->firstOrFail();
        $this->assertEquals(8, $destLevel->quantity_in_transit);
        $this->assertEquals(0, $destLevel->quantity_on_hand);

        $this->assertDatabaseHas('stock_transfers', ['id' => $transfer->id, 'status' => 'in_transit']);
    }

    public function test_shipping_with_insufficient_stock_fails_validation(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $sourceBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $destBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $sourceBranch, 'Branch Manager'));

        $item = $this->makeItem($tenant);
        $this->stockUp($tenant, $sourceBranch, $item, 3);

        Volt::test('pages.stock-transfers.show')
            ->set('from_branch_id', $sourceBranch->id)
            ->set('to_branch_id', $destBranch->id)
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity_sent', '10')
            ->call('saveDraft');

        $transfer = StockTransfer::where('tenant_id', $tenant->id)->firstOrFail();

        Volt::test('pages.stock-transfers.show', ['stockTransfer' => $transfer])
            ->call('ship')
            ->assertHasErrors(['ship']);

        $this->assertDatabaseHas('stock_transfers', ['id' => $transfer->id, 'status' => 'pending']);
        $this->assertDatabaseMissing('stock_movements', ['item_id' => $item->id, 'movement_type' => 'transfer_out']);
    }

    public function test_destination_branch_user_can_receive_with_short_delivery(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $sourceBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $destBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $sourceManager = $this->createUserWithRole($tenant, $sourceBranch, 'Branch Manager');
        $destManager = $this->createUserWithRole($tenant, $destBranch, 'Branch Manager');

        $item = $this->makeItem($tenant);
        $this->stockUp($tenant, $sourceBranch, $item, 20);

        $this->loginAs($sourceManager);
        Volt::test('pages.stock-transfers.show')
            ->set('from_branch_id', $sourceBranch->id)
            ->set('to_branch_id', $destBranch->id)
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity_sent', '10')
            ->call('saveDraft');

        $transfer = StockTransfer::where('tenant_id', $tenant->id)->firstOrFail();
        Volt::test('pages.stock-transfers.show', ['stockTransfer' => $transfer])->call('ship');

        $this->loginAs($destManager);
        Volt::test('pages.stock-transfers.show', ['stockTransfer' => $transfer->fresh()])
            ->set('lines.0.quantity_received', '9')
            ->call('receive');

        $this->assertDatabaseHas('stock_movements', [
            'branch_id' => $destBranch->id,
            'item_id' => $item->id,
            'movement_type' => 'transfer_in',
            'quantity' => 9,
            'performed_by' => $destManager->id,
        ]);

        $destLevel = StockLevel::where('branch_id', $destBranch->id)->where('item_id', $item->id)->firstOrFail();
        $this->assertEquals(0, $destLevel->quantity_in_transit);
        $this->assertEquals(9, $destLevel->quantity_on_hand);

        $this->assertDatabaseHas('stock_transfer_items', [
            'stock_transfer_id' => $transfer->id,
            'quantity_sent' => 10,
            'quantity_received' => 9,
        ]);

        $this->assertDatabaseHas('stock_transfers', [
            'id' => $transfer->id,
            'status' => 'received',
            'received_by' => $destManager->id,
        ]);
    }

    public function test_unrelated_branch_user_cannot_ship_or_receive(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $sourceBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $destBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $thirdBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);

        $item = $this->makeItem($tenant);
        $this->stockUp($tenant, $sourceBranch, $item, 20);

        $sourceManager = $this->createUserWithRole($tenant, $sourceBranch, 'Branch Manager');
        $this->loginAs($sourceManager);
        Volt::test('pages.stock-transfers.show')
            ->set('from_branch_id', $sourceBranch->id)
            ->set('to_branch_id', $destBranch->id)
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity_sent', '5')
            ->call('saveDraft');

        $transfer = StockTransfer::where('tenant_id', $tenant->id)->firstOrFail();

        $thirdBranchUser = $this->createUserWithRole($tenant, $thirdBranch, 'Branch Manager');
        $this->loginAs($thirdBranchUser);

        Volt::test('pages.stock-transfers.show', ['stockTransfer' => $transfer])
            ->call('ship')
            ->assertForbidden();

        $this->loginAs($sourceManager);
        Volt::test('pages.stock-transfers.show', ['stockTransfer' => $transfer->fresh()])->call('ship');

        $this->loginAs($thirdBranchUser);
        Volt::test('pages.stock-transfers.show', ['stockTransfer' => $transfer->fresh()])
            ->call('receive')
            ->assertForbidden();

        // The source branch cannot receive its own outgoing transfer either.
        $this->loginAs($sourceManager);
        Volt::test('pages.stock-transfers.show', ['stockTransfer' => $transfer->fresh()])
            ->call('receive')
            ->assertForbidden();
    }

    public function test_cancelling_pending_transfer_has_no_stock_effect(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $sourceBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $destBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $sourceBranch, 'Branch Manager'));

        $item = $this->makeItem($tenant);
        $this->stockUp($tenant, $sourceBranch, $item, 20);

        Volt::test('pages.stock-transfers.show')
            ->set('from_branch_id', $sourceBranch->id)
            ->set('to_branch_id', $destBranch->id)
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity_sent', '5')
            ->call('saveDraft');

        $transfer = StockTransfer::where('tenant_id', $tenant->id)->firstOrFail();

        Volt::test('pages.stock-transfers.show', ['stockTransfer' => $transfer])->call('cancel');

        $this->assertDatabaseHas('stock_transfers', ['id' => $transfer->id, 'status' => 'cancelled']);

        $sourceLevel = StockLevel::where('branch_id', $sourceBranch->id)->where('item_id', $item->id)->firstOrFail();
        $this->assertEquals(20, $sourceLevel->quantity_on_hand);
    }

    public function test_shipping_an_already_shipped_transfer_is_forbidden(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $sourceBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $destBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $sourceBranch, 'Branch Manager'));

        $item = $this->makeItem($tenant);
        $this->stockUp($tenant, $sourceBranch, $item, 20);

        Volt::test('pages.stock-transfers.show')
            ->set('from_branch_id', $sourceBranch->id)
            ->set('to_branch_id', $destBranch->id)
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity_sent', '5')
            ->call('saveDraft');

        $transfer = StockTransfer::where('tenant_id', $tenant->id)->firstOrFail();
        Volt::test('pages.stock-transfers.show', ['stockTransfer' => $transfer])->call('ship');

        Volt::test('pages.stock-transfers.show', ['stockTransfer' => $transfer->fresh()])
            ->call('ship')
            ->assertForbidden();
    }

    public function test_from_and_to_branch_must_differ(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $item = $this->makeItem($tenant);

        Volt::test('pages.stock-transfers.show')
            ->set('from_branch_id', $branch->id)
            ->set('to_branch_id', $branch->id)
            ->set('lines.0.item_id', $item->id)
            ->set('lines.0.quantity_sent', '5')
            ->call('saveDraft')
            ->assertHasErrors(['to_branch_id']);
    }

    public function test_cashier_cannot_create_transfer(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->createUserWithRole($tenant, $branch, 'Cashier');

        $this->actingAs($cashier)->get('/stock-transfers/create')->assertForbidden();
    }

    public function test_transfer_from_another_tenant_is_not_reachable(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->createUserWithRole($tenant, $branch, 'Business Owner');

        $otherTenant = Tenant::factory()->licensed()->create();
        $otherSource = Branch::factory()->main()->create(['tenant_id' => $otherTenant->id]);
        $otherDest = Branch::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherTransfer = StockTransfer::factory()->create([
            'tenant_id' => $otherTenant->id,
            'from_branch_id' => $otherSource->id,
            'to_branch_id' => $otherDest->id,
        ]);

        $this->actingAs($owner)
            ->get("/stock-transfers/{$otherTransfer->uuid}")
            ->assertNotFound();
    }
}
