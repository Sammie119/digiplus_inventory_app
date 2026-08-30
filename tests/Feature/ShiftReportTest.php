<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ShiftReportTest extends TestCase
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

    private function makeClosedShift(Tenant $tenant, Branch $branch, User $cashier, float $openingFloat, float $expectedCash, float $countedCash, ?\DateTimeInterface $openedAt = null): Shift
    {
        return Shift::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'cashier_id' => $cashier->id,
            'opening_float' => $openingFloat,
            'opened_at' => $openedAt ?? now(),
            'closing_float_counted' => $countedCash,
            'expected_cash' => $expectedCash,
            'variance' => round($countedCash - $expectedCash, 2),
            'status' => 'closed',
            'closed_at' => now(),
        ]);
    }

    public function test_closed_shift_shows_correct_variance_figures(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $this->makeClosedShift($tenant, $branch, $cashier, 100, 140, 135);

        $shifts = Volt::test('pages.reports.shifts')->get('shifts');
        $shift = $shifts->first();

        $this->assertEquals(140, $shift->expected_cash);
        $this->assertEquals(135, $shift->closing_float_counted);
        $this->assertEquals(-5, $shift->variance);
    }

    public function test_open_shift_appears_with_blank_variance(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        Shift::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'cashier_id' => $cashier->id,
        ]);

        $shifts = Volt::test('pages.reports.shifts')->get('shifts');
        $shift = $shifts->first();

        $this->assertEquals('open', $shift->status);
        $this->assertNull($shift->variance);
        $this->assertNull($shift->expected_cash);
    }

    public function test_date_range_excludes_shifts_outside_it(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $this->makeClosedShift($tenant, $branch, $cashier, 100, 100, 100, now());
        $this->makeClosedShift($tenant, $branch, $cashier, 100, 100, 100, now()->subDays(45));

        $shifts = Volt::test('pages.reports.shifts')
            ->set('from', now()->startOfMonth()->toDateString())
            ->set('to', now()->endOfMonth()->toDateString())
            ->get('shifts');

        $this->assertCount(1, $shifts);
    }

    public function test_branch_and_cashier_filters_narrow_the_list(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $otherBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $mainBranch, 'Business Owner'));
        $mainCashier = $this->createUserWithRole($tenant, $mainBranch, 'Cashier');
        $otherCashier = $this->createUserWithRole($tenant, $otherBranch, 'Cashier');

        $this->makeClosedShift($tenant, $mainBranch, $mainCashier, 100, 100, 100);
        $this->makeClosedShift($tenant, $otherBranch, $otherCashier, 100, 100, 100);

        $mainOnly = Volt::test('pages.reports.shifts')
            ->set('branch_id', $mainBranch->id)
            ->get('shifts');
        $this->assertCount(1, $mainOnly);
        $this->assertEquals($mainBranch->id, $mainOnly->first()->branch_id);

        $byCashier = Volt::test('pages.reports.shifts')
            ->set('cashier_id', $otherCashier->id)
            ->get('shifts');
        $this->assertCount(1, $byCashier);
        $this->assertEquals($otherCashier->id, $byCashier->first()->cashier_id);
    }

    public function test_summary_counts_discrepancies_and_sums_variance(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $this->makeClosedShift($tenant, $branch, $owner, 100, 140, 135); // -5 shortage
        $this->makeClosedShift($tenant, $branch, $owner, 100, 100, 110); // +10 overage
        $this->makeClosedShift($tenant, $branch, $owner, 100, 100, 100); // exact, no discrepancy

        $summary = Volt::test('pages.reports.shifts')->get('summary');

        $this->assertEquals(3, $summary['count']);
        $this->assertEquals(2, $summary['discrepancies']);
        $this->assertEquals(5, $summary['total_variance']); // -5 + 10 + 0
    }

    public function test_export_csv_and_pdf(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $this->makeClosedShift($tenant, $branch, $owner, 100, 140, 135);

        $csvComponent = Volt::test('pages.reports.shifts')->call('exportCsv');
        $csvComponent->assertFileDownloaded(contentType: 'text/csv');
        $csvContent = base64_decode(data_get($csvComponent->effects, 'download.content'));
        $this->assertStringContainsString('-5.00', $csvContent);

        $pdfComponent = Volt::test('pages.reports.shifts')->call('exportPdf');
        $pdfComponent->assertFileDownloaded(contentType: 'application/pdf');
        $pdfContent = base64_decode(data_get($pdfComponent->effects, 'download.content'));
        $this->assertStringStartsWith('%PDF', $pdfContent);
    }

    public function test_cashier_cannot_view_shift_report(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->createUserWithRole($tenant, $branch, 'Cashier');

        $this->actingAs($cashier)->get('/reports/shifts')->assertForbidden();
    }

    public function test_tenant_isolation(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $otherTenant = Tenant::factory()->licensed()->create();
        $otherBranch = Branch::factory()->main()->create(['tenant_id' => $otherTenant->id]);
        $otherCashier = User::factory()->create(['tenant_id' => $otherTenant->id, 'branch_id' => $otherBranch->id]);
        $this->makeClosedShift($otherTenant, $otherBranch, $otherCashier, 100, 100, 100);

        $shifts = Volt::test('pages.reports.shifts')->get('shifts');

        $this->assertCount(0, $shifts);
    }
}
