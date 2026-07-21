<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserManagementTest extends TestCase
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

    public function test_business_owner_can_create_user_with_any_role_in_any_branch(): void
    {
        Notification::fake();

        $tenant = Tenant::factory()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $secondBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $mainBranch, 'Business Owner'));

        Volt::test('pages.users.index')
            ->set('name', 'New Manager')
            ->set('email', 'newmanager@example.com')
            ->set('branch_id', $secondBranch->id)
            ->set('role', 'Branch Manager')
            ->call('save');

        $newUser = User::where('email', 'newmanager@example.com')->firstOrFail();

        $this->assertSame($secondBranch->id, $newUser->branch_id);
        $this->assertTrue($newUser->hasRole('Branch Manager'));

        Notification::assertSentTo($newUser, ResetPassword::class);
    }

    public function test_branch_manager_can_only_create_allowed_roles_in_own_branch(): void
    {
        Notification::fake();

        $tenant = Tenant::factory()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $otherBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $mainBranch, 'Branch Manager'));

        // Attempting to create a Business Owner (outside the allowed role set) fails validation.
        Volt::test('pages.users.index')
            ->set('name', 'Sneaky User')
            ->set('email', 'sneaky@example.com')
            ->set('branch_id', $mainBranch->id)
            ->set('role', 'Business Owner')
            ->call('save')
            ->assertHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);

        // Attempting to place a user in a branch outside the manager's own branch fails validation.
        Volt::test('pages.users.index')
            ->set('name', 'Other Branch Cashier')
            ->set('email', 'other-branch@example.com')
            ->set('branch_id', $otherBranch->id)
            ->set('role', 'Cashier')
            ->call('save')
            ->assertHasErrors('branch_id');

        $this->assertDatabaseMissing('users', ['email' => 'other-branch@example.com']);

        // A Cashier in the manager's own branch succeeds.
        Volt::test('pages.users.index')
            ->set('name', 'New Cashier')
            ->set('email', 'cashier@example.com')
            ->set('branch_id', $mainBranch->id)
            ->set('role', 'Cashier')
            ->call('save');

        $this->assertDatabaseHas('users', ['email' => 'cashier@example.com', 'branch_id' => $mainBranch->id]);
    }

    public function test_user_cannot_deactivate_their_own_account(): void
    {
        $tenant = Tenant::factory()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $mainBranch, 'Business Owner'));

        Volt::test('pages.users.index')
            ->call('confirmToggleStatus', $owner->id)
            ->call('toggleStatus');

        $this->assertDatabaseHas('users', ['id' => $owner->id, 'status' => 'active']);
    }

    public function test_last_active_business_owner_cannot_be_deactivated(): void
    {
        $tenant = Tenant::factory()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->createUserWithRole($tenant, $mainBranch, 'Business Owner');
        $secondOwner = $this->loginAs($this->createUserWithRole($tenant, $mainBranch, 'Business Owner'));

        Volt::test('pages.users.index')
            ->call('confirmToggleStatus', $owner->id)
            ->call('toggleStatus');

        $this->assertDatabaseHas('users', ['id' => $owner->id, 'status' => 'disabled']);

        Volt::test('pages.users.index')
            ->call('confirmToggleStatus', $secondOwner->id)
            ->call('toggleStatus');

        $this->assertDatabaseHas('users', ['id' => $secondOwner->id, 'status' => 'active']);
    }

    public function test_users_from_another_tenant_are_not_visible_or_reachable(): void
    {
        $tenant = Tenant::factory()->create();
        $mainBranch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->createUserWithRole($tenant, $mainBranch, 'Business Owner');

        $otherTenant = Tenant::factory()->create();
        $otherBranch = Branch::factory()->main()->create(['tenant_id' => $otherTenant->id]);
        $otherUser = $this->createUserWithRole($otherTenant, $otherBranch, 'Business Owner');

        $this->actingAs($owner)
            ->get('/users')
            ->assertOk()
            ->assertDontSee($otherUser->email);
    }
}
