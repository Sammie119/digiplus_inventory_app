<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BillingRenewalTest extends TestCase
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

    private function loginAs(User $user): void
    {
        $this->actingAs($user);
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);
        CurrentTenant::set($user->tenant_id);
    }

    public function test_business_owner_sees_purchasable_plans_and_current_plan_highlighted(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        SubscriptionPlan::factory()->trial()->create();
        $basicPlan = SubscriptionPlan::factory()->create(['name' => 'Basic', 'price' => 29]);
        SubscriptionPlan::factory()->create(['name' => 'Standard', 'price' => 99]);

        TenantSubscription::create([
            'tenant_id' => $tenant->id, 'plan_id' => $basicPlan->id,
            'period_start' => now()->subDays(40)->toDateString(), 'period_end' => now()->subDay()->toDateString(),
            'status' => 'active',
        ]);

        $owner = $this->createUserWithRole($tenant, $branch, 'Business Owner');
        $this->loginAs($owner);

        Volt::test('pages.billing.renew')
            ->assertSee('Basic')
            ->assertSee('Standard')
            ->assertDontSee('Trial')
            ->assertSee('Current Plan');
    }

    public function test_non_owner_sees_read_only_message_without_plans(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        SubscriptionPlan::factory()->create(['name' => 'Basic']);

        $cashier = $this->createUserWithRole($tenant, $branch, 'Cashier');
        $this->loginAs($cashier);

        Volt::test('pages.billing.renew')
            ->assertSee('Subscription Renewal Needed')
            ->assertDontSee('Basic');
    }

    public function test_suspended_tenant_sees_contact_support_message_without_plans(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'suspended']);
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        SubscriptionPlan::factory()->create(['name' => 'Basic']);

        $owner = $this->createUserWithRole($tenant, $branch, 'Business Owner');
        $this->loginAs($owner);

        Volt::test('pages.billing.renew')
            ->assertSee('Account Suspended')
            ->assertDontSee('Basic');
    }

    public function test_paying_initializes_a_paystack_transaction_and_redirects_to_checkout(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_fake']);

        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/fake-ref',
                    'reference' => 'DIGIPLUS-fake',
                ],
            ]),
        ]);

        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $plan = SubscriptionPlan::factory()->create(['name' => 'Basic', 'price' => 29]);

        $owner = $this->createUserWithRole($tenant, $branch, 'Business Owner');
        $this->loginAs($owner);

        Volt::test('pages.billing.renew')
            ->call('pay', $plan->id)
            ->assertRedirect('https://checkout.paystack.com/fake-ref');

        $this->assertDatabaseHas('subscription_payments', [
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'initiated_by_user_id' => $owner->id,
            'status' => 'pending',
            'amount' => 29,
        ]);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.paystack.co/transaction/initialize'
            && $request['amount'] === 2900
            && $request['email'] === $owner->email);
    }

    public function test_paying_without_configured_keys_shows_a_friendly_error(): void
    {
        config(['services.paystack.secret_key' => null]);

        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $plan = SubscriptionPlan::factory()->create(['name' => 'Basic']);

        $owner = $this->createUserWithRole($tenant, $branch, 'Business Owner');
        $this->loginAs($owner);

        Volt::test('pages.billing.renew')
            ->call('pay', $plan->id)
            ->assertDispatched('toast', type: 'error');

        $this->assertDatabaseMissing('subscription_payments', ['tenant_id' => $tenant->id, 'status' => 'successful']);
    }
}
