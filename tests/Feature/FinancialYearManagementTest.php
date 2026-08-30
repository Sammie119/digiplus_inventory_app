<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FinancialYear;
use App\Models\StockTake;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinancialYearManagementTest extends TestCase
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

    public function test_business_owner_can_create_a_financial_year(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        Volt::test('pages.financial-years.index')
            ->set('name', 'FY2026')
            ->set('start_date', '2026-01-01')
            ->set('end_date', '2026-12-31')
            ->call('save')
            ->assertDispatched('toast', message: 'Financial year created.', type: 'success');

        $this->assertDatabaseHas('financial_years', [
            'tenant_id' => $tenant->id,
            'name' => 'FY2026',
            'status' => 'open',
        ]);
    }

    public function test_non_owner_cannot_view_financial_years(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        $this->get('/financial-years')->assertForbidden();
    }

    public function test_only_one_open_financial_year_is_allowed_at_a_time(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        FinancialYear::factory()->create(['tenant_id' => $tenant->id, 'status' => 'open']);

        Volt::test('pages.financial-years.index')
            ->set('name', 'FY2027')
            ->set('start_date', '2027-01-01')
            ->set('end_date', '2027-12-31')
            ->call('save')
            ->assertHasErrors('name');

        $this->assertDatabaseMissing('financial_years', ['tenant_id' => $tenant->id, 'name' => 'FY2027']);
    }

    public function test_closing_is_blocked_while_a_stock_take_is_pending(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $financialYear = FinancialYear::factory()->create(['tenant_id' => $tenant->id, 'status' => 'open']);
        StockTake::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'financial_year_id' => $financialYear->id,
            'status' => 'in_progress',
            'created_by' => $owner->id,
        ]);

        Volt::test('pages.financial-years.index')
            ->call('confirmClose', $financialYear->id)
            ->call('close')
            ->assertDispatched('toast', type: 'error');

        $this->assertDatabaseHas('financial_years', ['id' => $financialYear->id, 'status' => 'open']);
    }

    public function test_closing_with_no_pending_stock_takes_succeeds_and_can_be_reopened(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $financialYear = FinancialYear::factory()->create(['tenant_id' => $tenant->id, 'status' => 'open']);

        Volt::test('pages.financial-years.index')
            ->call('confirmClose', $financialYear->id)
            ->call('close')
            ->assertDispatched('toast', message: 'Financial year closed.', type: 'success');

        $this->assertDatabaseHas('financial_years', ['id' => $financialYear->id, 'status' => 'closed']);

        Volt::test('pages.financial-years.index')
            ->call('reopen', $financialYear->id)
            ->assertDispatched('toast', message: 'Financial year reopened.', type: 'success');

        $this->assertDatabaseHas('financial_years', ['id' => $financialYear->id, 'status' => 'open']);
    }
}
