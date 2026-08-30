<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StockReportTest extends TestCase
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

    private function makeItem(Tenant $tenant, float $costPrice = 10, int $reorderLevel = 5): Item
    {
        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);

        return Item::factory()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'base_unit_id' => $unit->id,
            'cost_price' => $costPrice,
            'reorder_level' => $reorderLevel,
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

    private function makeMovement(Tenant $tenant, Branch $branch, Item $item, User $actor, float $qty, string $type = 'purchase', ?\DateTimeInterface $createdAt = null): StockMovement
    {
        return StockMovement::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'batch_id' => null,
            'movement_type' => $type,
            'quantity' => $qty,
            'unit_cost' => $item->cost_price,
            'reference_type' => 'Test',
            'reference_id' => 1,
            'performed_by' => $actor->id,
            'created_at' => $createdAt ?? now(),
        ]);
    }

    public function test_valuation_total_is_correct(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $itemA = $this->makeItem($tenant, costPrice: 10);
        $this->stockUp($tenant, $branch, $itemA, 5);

        $itemB = $this->makeItem($tenant, costPrice: 4);
        $this->stockUp($tenant, $branch, $itemB, 20);

        $valuation = Volt::test('pages.reports.stock')->get('valuation');

        // (5*10) + (20*4) = 130
        $this->assertEquals(130, $valuation['total_value']);
        $this->assertEquals(25, $valuation['total_units']);
        $this->assertEquals(2, $valuation['item_count']);
    }

    public function test_reorder_exceptions_lists_only_low_and_out_items(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $lowItem = $this->makeItem($tenant, reorderLevel: 10);
        $this->stockUp($tenant, $branch, $lowItem, 3);

        $outItem = $this->makeItem($tenant, reorderLevel: 10);
        // no stock at all -> out

        $okItem = $this->makeItem($tenant, reorderLevel: 10);
        $this->stockUp($tenant, $branch, $okItem, 50);

        $exceptions = Volt::test('pages.reports.stock')->get('reorderExceptions');
        $ids = collect($exceptions)->pluck('item.id')->all();

        $this->assertContains($lowItem->id, $ids);
        $this->assertContains($outItem->id, $ids);
        $this->assertNotContains($okItem->id, $ids);
    }

    public function test_expiring_batches_classification(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $item = $this->makeItem($tenant);
        $item->update(['track_batches' => true]);

        $expiringSoon = Batch::create(['tenant_id' => $tenant->id, 'item_id' => $item->id, 'batch_number' => 'SOON', 'expiry_date' => now()->addDays(10)]);
        $this->stockUp($tenant, $branch, $item, 5, $expiringSoon->id);

        $expired = Batch::create(['tenant_id' => $tenant->id, 'item_id' => $item->id, 'batch_number' => 'GONE', 'expiry_date' => now()->subDays(5)]);
        $this->stockUp($tenant, $branch, $item, 3, $expired->id);

        $farOut = Batch::create(['tenant_id' => $tenant->id, 'item_id' => $item->id, 'batch_number' => 'FAR', 'expiry_date' => now()->addDays(60)]);
        $this->stockUp($tenant, $branch, $item, 5, $farOut->id);

        $zeroStock = Batch::create(['tenant_id' => $tenant->id, 'item_id' => $item->id, 'batch_number' => 'EMPTY', 'expiry_date' => now()->addDays(5)]);
        $this->stockUp($tenant, $branch, $item, 0, $zeroStock->id);

        $rows = Volt::test('pages.reports.stock')->get('expiringBatches');
        $byNumber = collect($rows)->keyBy(fn ($row) => $row['batch']->batch_number);

        $this->assertTrue($byNumber->has('SOON'));
        $this->assertFalse($byNumber['SOON']['expired']);
        $this->assertTrue($byNumber->has('GONE'));
        $this->assertTrue($byNumber['GONE']['expired']);
        $this->assertFalse($byNumber->has('FAR'));
        $this->assertFalse($byNumber->has('EMPTY'));
    }

    public function test_movement_history_respects_date_filter(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $item = $this->makeItem($tenant);
        $this->makeMovement($tenant, $branch, $item, $owner, 10, 'purchase', now());
        $this->makeMovement($tenant, $branch, $item, $owner, -3, 'sale', now());
        $this->makeMovement($tenant, $branch, $item, $owner, 20, 'purchase', now()->subDays(45));

        $movements = Volt::test('pages.reports.stock')
            ->set('from', now()->startOfMonth()->toDateString())
            ->set('to', now()->endOfMonth()->toDateString())
            ->get('movements');

        $this->assertCount(2, $movements);
    }

    public function test_export_csv_and_pdf_for_movements(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $item = $this->makeItem($tenant);
        $this->makeMovement($tenant, $branch, $item, $owner, 10, 'purchase');

        $csvComponent = Volt::test('pages.reports.stock')->call('exportCsv');
        $csvComponent->assertFileDownloaded(contentType: 'text/csv');
        $csvContent = base64_decode(data_get($csvComponent->effects, 'download.content'));
        $this->assertStringContainsString($item->sku, $csvContent);

        $pdfComponent = Volt::test('pages.reports.stock')->call('exportPdf');
        $pdfComponent->assertFileDownloaded(contentType: 'application/pdf');
        $pdfContent = base64_decode(data_get($pdfComponent->effects, 'download.content'));
        $this->assertStringStartsWith('%PDF', $pdfContent);
    }

    public function test_cashier_cannot_view_stock_report(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->createUserWithRole($tenant, $branch, 'Cashier');

        $this->actingAs($cashier)->get('/reports/stock')->assertForbidden();
    }

    public function test_branch_manager_sees_only_their_branch(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $otherBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager = $this->loginAs($this->createUserWithRole($tenant, $mainBranch, 'Branch Manager'));

        $item = $this->makeItem($tenant, costPrice: 10);
        $this->stockUp($tenant, $mainBranch, $item, 5);
        $this->stockUp($tenant, $otherBranch, $item, 999);

        $valuation = Volt::test('pages.reports.stock')->get('valuation');

        $this->assertEquals(50, $valuation['total_value']);
    }

    public function test_tenant_isolation(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $otherTenant = Tenant::factory()->licensed()->create();
        $otherBranch = Branch::factory()->main()->create(['tenant_id' => $otherTenant->id]);
        $otherItem = $this->makeItem($otherTenant, costPrice: 1000);
        $this->stockUp($otherTenant, $otherBranch, $otherItem, 100);

        $valuation = Volt::test('pages.reports.stock')->get('valuation');

        $this->assertEquals(0, $valuation['total_value']);
    }
}
