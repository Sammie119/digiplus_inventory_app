<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
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

class ItemVelocityReportTest extends TestCase
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

    private function makeItem(Tenant $tenant, float $sellingPrice = 20, int $reorderLevel = 5): Item
    {
        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);

        return Item::factory()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'base_unit_id' => $unit->id,
            'selling_price' => $sellingPrice,
            'reorder_level' => $reorderLevel,
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

    private function makeSale(Tenant $tenant, Branch $branch, User $cashier, Item $item, float $quantity, string $status = 'completed', ?\DateTimeInterface $createdAt = null): Sale
    {
        $unitPrice = (float) $item->selling_price;

        $shift = Shift::firstOrCreate(
            ['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'cashier_id' => $cashier->id, 'status' => 'open'],
            ['opening_float' => 0, 'opened_at' => now()],
        );

        $sale = Sale::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'shift_id' => $shift->id,
            'cashier_id' => $cashier->id,
            'receipt_number' => 'MAIN-'.now()->year.'-'.random_int(100000, 999999),
            'subtotal' => $quantity * $unitPrice,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => $quantity * $unitPrice,
            'status' => $status,
            'created_at' => $createdAt ?? now(),
        ]);

        $sale->items()->create([
            'item_id' => $item->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount_amount' => 0,
            'line_total' => $quantity * $unitPrice,
            'cost_at_sale' => $item->cost_price,
        ]);

        return $sale;
    }

    public function test_fast_movers_excludes_voided_sales(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $sold = $this->makeItem($tenant, sellingPrice: 20);
        $this->makeSale($tenant, $branch, $owner, $sold, 5);

        $voided = $this->makeItem($tenant, sellingPrice: 20);
        $this->makeSale($tenant, $branch, $owner, $voided, 5, status: 'void');

        $fastMovers = Volt::test('pages.reports.velocity')->get('fastMovers');
        $ids = collect($fastMovers)->pluck('item.id')->all();
        $row = collect($fastMovers)->firstWhere('item.id', $sold->id);

        $this->assertContains($sold->id, $ids);
        $this->assertNotContains($voided->id, $ids);
        $this->assertEquals(5, $row['units_sold']);
        $this->assertEquals(100, $row['revenue']);
    }

    public function test_slow_moving_lists_stocked_items_with_no_sales_in_window(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $dead = $this->makeItem($tenant);
        $this->stockUp($tenant, $branch, $dead, 10);

        $active = $this->makeItem($tenant);
        $this->stockUp($tenant, $branch, $active, 10);
        $this->makeSale($tenant, $branch, $owner, $active, 2);

        $slowMoving = Volt::test('pages.reports.velocity')->get('slowMoving');
        $ids = collect($slowMoving)->pluck('item.id')->all();

        $this->assertContains($dead->id, $ids);
        $this->assertNotContains($active->id, $ids);
    }

    public function test_abc_analysis_ranks_top_revenue_contributor_as_class_a(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $dominant = $this->makeItem($tenant, sellingPrice: 100);
        $this->makeSale($tenant, $branch, $owner, $dominant, 10); // 1000 revenue

        $minor = $this->makeItem($tenant, sellingPrice: 10);
        $this->makeSale($tenant, $branch, $owner, $minor, 5); // 50 revenue

        $abc = Volt::test('pages.reports.velocity')->get('abcAnalysis');
        $byId = collect($abc)->keyBy('item.id');

        $this->assertEquals('A', $byId[$dominant->id]['class']);
    }

    public function test_reorder_suggestion_uses_velocity_when_available_and_falls_back_otherwise(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $withVelocity = $this->makeItem($tenant, reorderLevel: 10);
        $this->stockUp($tenant, $branch, $withVelocity, 3);
        $this->makeSale($tenant, $branch, $owner, $withVelocity, 12);

        $noVelocity = $this->makeItem($tenant, reorderLevel: 10);
        $this->stockUp($tenant, $branch, $noVelocity, 4);

        $suggestions = Volt::test('pages.reports.velocity')->get('reorderSuggestions');
        $byId = collect($suggestions)->keyBy('item.id');

        // units_sold (12) - on_hand (3) = 9
        $this->assertEquals(9, $byId[$withVelocity->id]['suggested_qty']);
        // reorder_level (10) - on_hand (4) = 6
        $this->assertEquals(6, $byId[$noVelocity->id]['suggested_qty']);
    }

    public function test_export_csv_and_pdf(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $item = $this->makeItem($tenant);
        $this->makeSale($tenant, $branch, $owner, $item, 3);

        $csvComponent = Volt::test('pages.reports.velocity')->call('exportCsv');
        $csvComponent->assertFileDownloaded(contentType: 'text/csv');
        $csvContent = base64_decode(data_get($csvComponent->effects, 'download.content'));
        $this->assertStringContainsString($item->sku, $csvContent);

        $pdfComponent = Volt::test('pages.reports.velocity')->call('exportPdf');
        $pdfComponent->assertFileDownloaded(contentType: 'application/pdf');
        $pdfContent = base64_decode(data_get($pdfComponent->effects, 'download.content'));
        $this->assertStringStartsWith('%PDF', $pdfContent);
    }

    public function test_cashier_cannot_view_item_velocity_report(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->createUserWithRole($tenant, $branch, 'Cashier');

        $this->actingAs($cashier)->get('/reports/velocity')->assertForbidden();
    }
}
