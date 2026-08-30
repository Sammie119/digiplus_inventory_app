<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProfitLossReportExportTest extends TestCase
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

    private function makeSale(Tenant $tenant, Branch $branch, User $cashier, float $grandTotal, float $costAtSale): Sale
    {
        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);
        $item = Item::factory()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'base_unit_id' => $unit->id]);
        $shift = Shift::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'cashier_id' => $cashier->id]);

        $sale = Sale::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'shift_id' => $shift->id,
            'cashier_id' => $cashier->id,
            'subtotal' => $grandTotal,
            'grand_total' => $grandTotal,
            'status' => 'completed',
        ]);

        SaleItem::factory()->create([
            'sale_id' => $sale->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'unit_price' => $grandTotal,
            'line_total' => $grandTotal,
            'cost_at_sale' => $costAtSale,
        ]);

        return $sale;
    }

    public function test_reports_view_holder_can_export_csv_with_correct_figures(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $this->makeSale($tenant, $branch, $owner, 100, 60);

        $component = Volt::test('pages.reports.profit-loss')->call('exportCsv');
        $component->assertFileDownloaded(contentType: 'text/csv');

        $content = base64_decode(data_get($component->effects, 'download.content'));
        $this->assertStringContainsString('100.00', $content);
        $this->assertStringContainsString('40.00', $content); // gross profit
    }

    public function test_reports_view_holder_can_export_pdf(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $this->makeSale($tenant, $branch, $owner, 100, 60);

        $component = Volt::test('pages.reports.profit-loss')->call('exportPdf');
        $component->assertFileDownloaded(contentType: 'application/pdf');

        $content = base64_decode(data_get($component->effects, 'download.content'));
        $this->assertNotEmpty($content);
        $this->assertStringStartsWith('%PDF', $content);
    }

    public function test_cashier_cannot_reach_the_report_to_export(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->createUserWithRole($tenant, $branch, 'Cashier');

        $this->actingAs($cashier)->get('/reports/profit-loss')->assertForbidden();
    }
}
