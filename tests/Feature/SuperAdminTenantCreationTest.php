<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SuperAdminTenantCreationTest extends TestCase
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

    public function test_super_admin_can_create_a_tenant(): void
    {
        Notification::fake();

        $this->actingAs($this->createSuperAdmin());

        Volt::test('pages.super-admin.tenants')
            ->set('new_business_name', 'Acme Retail')
            ->set('new_owner_name', 'Jane Owner')
            ->set('new_owner_email', 'jane@acme-retail.test')
            ->call('createTenant')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: 'Tenant created. A password-setup email has been sent to jane@acme-retail.test.', type: 'success');

        $tenant = Tenant::where('name', 'Acme Retail')->firstOrFail();
        $this->assertSame('trial', $tenant->status);

        $trialPlan = SubscriptionPlan::where('is_trial', true)->firstOrFail();

        $this->assertDatabaseHas('tenant_subscriptions', [
            'tenant_id' => $tenant->id,
            'plan_id' => $trialPlan->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('branches', [
            'tenant_id' => $tenant->id,
            'is_main_branch' => true,
        ]);

        $owner = User::where('email', 'jane@acme-retail.test')->firstOrFail();
        $this->assertSame($tenant->id, $owner->tenant_id);
        $this->assertTrue($owner->hasRole('Business Owner'));

        Notification::assertSentTo($owner, ResetPassword::class);
    }

    public function test_creating_a_tenant_with_a_duplicate_owner_email_fails_validation(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'email' => 'taken@demo.test']);

        $this->actingAs($this->createSuperAdmin());

        Volt::test('pages.super-admin.tenants')
            ->set('new_business_name', 'Another Business')
            ->set('new_owner_name', 'Someone')
            ->set('new_owner_email', 'taken@demo.test')
            ->call('createTenant')
            ->assertHasErrors('new_owner_email');

        $this->assertDatabaseMissing('tenants', ['name' => 'Another Business']);
    }
}
