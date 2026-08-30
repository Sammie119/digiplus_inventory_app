<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TaxRateManagementTest extends TestCase
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

    public function test_business_owner_can_create_an_all_scoped_tax_rate(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        Volt::test('pages.tax-rates.index')
            ->set('name', 'VAT')
            ->set('rate_percentage', '15')
            ->set('applies_to', 'all')
            ->call('save')
            ->assertDispatched('toast', message: 'Tax rate created.', type: 'success');

        $this->assertDatabaseHas('tax_rates', [
            'tenant_id' => $tenant->id,
            'name' => 'VAT',
            'rate_percentage' => 15,
            'applies_to' => 'all',
            'category_id' => null,
        ]);
    }

    public function test_business_owner_can_create_a_category_scoped_tax_rate(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));
        $category = Category::factory()->create(['tenant_id' => $tenant->id]);

        Volt::test('pages.tax-rates.index')
            ->set('name', 'Luxury Tax')
            ->set('rate_percentage', '5')
            ->set('applies_to', 'category')
            ->set('category_id', $category->id)
            ->call('save');

        $this->assertDatabaseHas('tax_rates', [
            'tenant_id' => $tenant->id,
            'name' => 'Luxury Tax',
            'applies_to' => 'category',
            'category_id' => $category->id,
        ]);
    }

    public function test_category_scoped_tax_rate_requires_a_category(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        Volt::test('pages.tax-rates.index')
            ->set('name', 'Luxury Tax')
            ->set('rate_percentage', '5')
            ->set('applies_to', 'category')
            ->set('category_id', null)
            ->call('save')
            ->assertHasErrors(['category_id']);

        $this->assertEquals(0, \App\Models\TaxRate::count());
    }

    public function test_deleting_a_tax_rate_removes_it(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $taxRate = \App\Models\TaxRate::factory()->create(['tenant_id' => $tenant->id]);

        Volt::test('pages.tax-rates.index')
            ->call('confirmDelete', $taxRate->id)
            ->call('delete')
            ->assertDispatched('toast', message: 'Tax rate deleted.', type: 'success');

        $this->assertDatabaseMissing('tax_rates', ['id' => $taxRate->id]);
    }

    public function test_branch_manager_cannot_reach_tax_rates_page(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $manager = $this->createUserWithRole($tenant, $branch, 'Branch Manager');

        $this->actingAs($manager)->get('/tax-rates')->assertForbidden();
    }

    public function test_cashier_cannot_reach_tax_rates_page(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->createUserWithRole($tenant, $branch, 'Cashier');

        $this->actingAs($cashier)->get('/tax-rates')->assertForbidden();
    }
}
