<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SuperAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private function createSuperAdmin(): User
    {
        return User::factory()->create([
            'tenant_id' => null,
            'branch_id' => null,
            'is_super_admin' => true,
        ]);
    }

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

    public function test_super_admin_can_reach_all_super_admin_pages(): void
    {
        $admin = $this->createSuperAdmin();
        $this->actingAs($admin);

        $this->get('/super-admin')->assertOk();
        $this->get('/super-admin/tenants')->assertOk();
        $this->get('/super-admin/subscription-plans')->assertOk();
    }

    public function test_business_owner_is_forbidden_from_super_admin_pages(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->createUserWithRole($tenant, $branch, 'Business Owner');

        $this->actingAs($owner);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        CurrentTenant::set($tenant->id);

        $this->get('/super-admin')->assertForbidden();
        $this->get('/super-admin/tenants')->assertForbidden();
        $this->get('/super-admin/subscription-plans')->assertForbidden();
    }

    public function test_login_redirects_super_admin_to_super_admin_dashboard(): void
    {
        $admin = $this->createSuperAdmin();

        Volt::test('pages.auth.login')
            ->set('form.email', $admin->email)
            ->set('form.password', 'password')
            ->call('login')
            ->assertRedirect('/super-admin');
    }

    public function test_login_redirects_regular_user_to_tenant_dashboard(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->createUserWithRole($tenant, $branch, 'Business Owner');

        Volt::test('pages.auth.login')
            ->set('form.email', $owner->email)
            ->set('form.password', 'password')
            ->call('login')
            ->assertRedirect('/dashboard');
    }
}
