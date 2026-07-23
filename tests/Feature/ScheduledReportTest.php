<?php

namespace Tests\Feature;

use App\Console\Commands\SendScheduledReports;
use App\Mail\ScheduledSalesReportMail;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ScheduledReport;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Reports\SalesReportData;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ScheduledReportTest extends TestCase
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

    public function test_business_owner_can_create_a_schedule(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        Volt::test('pages.reports.scheduled')
            ->set('frequency', 'weekly')
            ->set('recipientsText', "owner@example.test\nmanager@example.test")
            ->call('save')
            ->assertDispatched('toast', message: 'Schedule created.', type: 'success');

        $this->assertDatabaseHas('scheduled_reports', [
            'tenant_id' => $tenant->id,
            'branch_id' => null,
            'report_type' => 'sales',
            'frequency' => 'weekly',
        ]);

        $schedule = ScheduledReport::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertEquals(['owner@example.test', 'manager@example.test'], $schedule->recipients);
    }

    public function test_invalid_email_in_recipients_fails_without_creating_a_schedule(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        Volt::test('pages.reports.scheduled')
            ->set('frequency', 'daily')
            ->set('recipientsText', 'not-an-email')
            ->call('save')
            ->assertHasErrors(['recipientsText']);

        $this->assertEquals(0, ScheduledReport::count());
    }

    public function test_branch_manager_schedule_is_locked_to_their_own_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $other = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Branch Manager'));

        Volt::test('pages.reports.scheduled')
            ->set('frequency', 'daily')
            ->set('recipientsText', 'manager@example.test')
            ->call('save');

        $this->assertDatabaseHas('scheduled_reports', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
        ]);
        $this->assertDatabaseMissing('scheduled_reports', ['branch_id' => $other->id]);
    }

    public function test_branch_manager_only_sees_their_own_branch_schedules(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $otherBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Branch Manager'));

        ScheduledReport::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
        ScheduledReport::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $otherBranch->id]);

        $rows = Volt::test('pages.reports.scheduled')->get('schedules');
        $this->assertCount(1, $rows);
        $this->assertEquals($branch->id, $rows->first()->branch_id);
    }

    public function test_cashier_cannot_reach_scheduled_reports_page(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->createUserWithRole($tenant, $branch, 'Cashier');

        $this->actingAs($cashier)->get('/reports/scheduled')->assertForbidden();
    }

    public function test_deleting_a_schedule_removes_it(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $schedule = ScheduledReport::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => null]);

        Volt::test('pages.reports.scheduled')
            ->call('confirmDelete', $schedule->id)
            ->call('delete')
            ->assertDispatched('toast', message: 'Schedule deleted.', type: 'success');

        $this->assertDatabaseMissing('scheduled_reports', ['id' => $schedule->id]);
    }

    public function test_sales_report_data_matches_the_live_report_page(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);
        $item = Item::factory()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'base_unit_id' => $unit->id,
            'cost_price' => 10, 'selling_price' => 20,
        ]);
        $shift = Shift::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'cashier_id' => $owner->id]);

        $sale = Sale::factory()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'shift_id' => $shift->id, 'cashier_id' => $owner->id,
            'status' => 'completed', 'grand_total' => 40,
        ]);
        SaleItem::factory()->create([
            'sale_id' => $sale->id, 'item_id' => $item->id, 'quantity' => 2, 'unit_price' => 20, 'line_total' => 40, 'cost_at_sale' => 10,
        ]);

        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();

        $direct = SalesReportData::build($tenant->id, null, $from, $to);

        $page = Volt::test('pages.reports.sales')->set('from', $from)->set('to', $to);

        $this->assertEquals($direct['summary'], $page->get('summary'));
        $this->assertEquals($direct['dailyBreakdown'], $page->get('dailyBreakdown'));
    }

    public function test_send_scheduled_reports_respects_frequency_due_check(): void
    {
        Mail::fake();

        $tenant = Tenant::factory()->create();
        Branch::factory()->main()->create(['tenant_id' => $tenant->id]);

        $due = ScheduledReport::factory()->create([
            'tenant_id' => $tenant->id, 'branch_id' => null, 'frequency' => 'daily', 'last_sent_at' => null,
            'recipients' => ['solo@example.test'],
        ]);
        $notDueWeekly = ScheduledReport::factory()->create([
            'tenant_id' => $tenant->id, 'branch_id' => null, 'frequency' => 'weekly',
            'last_sent_at' => now()->subDays(3),
        ]);
        $dueWeekly = ScheduledReport::factory()->create([
            'tenant_id' => $tenant->id, 'branch_id' => null, 'frequency' => 'weekly',
            'last_sent_at' => now()->subDays(8), 'recipients' => ['a@example.test', 'b@example.test'],
        ]);

        $this->artisan(SendScheduledReports::class)->assertSuccessful();

        Mail::assertSent(ScheduledSalesReportMail::class, fn ($mail) => $mail->schedule->is($due));
        Mail::assertNotSent(ScheduledSalesReportMail::class, fn ($mail) => $mail->schedule->is($notDueWeekly));
        Mail::assertSent(ScheduledSalesReportMail::class, 3);

        $due->refresh();
        $notDueWeekly->refresh();
        $dueWeekly->refresh();

        $this->assertNotNull($due->last_sent_at);
        $this->assertNotNull($notDueWeekly->last_sent_at, 'should be unchanged (still the original subDays(3))');
        $this->assertTrue($notDueWeekly->last_sent_at->diffInSeconds(now()->subDays(3)) < 2);
        $this->assertTrue($dueWeekly->last_sent_at->greaterThan(now()->subMinute()));
    }
}
