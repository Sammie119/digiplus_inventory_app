<?php

namespace Tests\Feature;

use App\Models\Branch;
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

class UnitOfMeasureManagementTest extends TestCase
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

    public function test_business_owner_can_view_units_index(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->createUserWithRole($tenant, $branch, 'Business Owner');

        $this->actingAs($owner)
            ->get('/units-of-measure')
            ->assertOk()
            ->assertSeeVolt('pages.units-of-measure.index');
    }

    public function test_business_owner_can_create_unit(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        Volt::test('pages.units-of-measure.index')
            ->set('name', 'Carton')
            ->set('abbreviation', 'ctn')
            ->call('save');

        $this->assertDatabaseHas('units_of_measure', [
            'tenant_id' => $tenant->id,
            'name' => 'Carton',
            'abbreviation' => 'ctn',
        ]);
    }

    public function test_cashier_can_view_but_not_create_unit(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Cashier'));

        $this->get('/units-of-measure')->assertOk()->assertDontSee('Add Unit');

        Volt::test('pages.units-of-measure.index')->call('openCreateModal')->assertForbidden();
    }

    public function test_unit_from_another_tenant_is_not_reachable(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $otherTenant = Tenant::factory()->create();
        $otherUnit = UnitOfMeasure::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->expectException(ModelNotFoundException::class);

        Volt::test('pages.units-of-measure.index')->call('editUnit', $otherUnit->id);
    }
}
