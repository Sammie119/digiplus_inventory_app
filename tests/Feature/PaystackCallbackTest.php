<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PaystackCallbackTest extends TestCase
{
    use RefreshDatabase;

    private function loginAs(User $user): void
    {
        $this->actingAs($user);
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);
        CurrentTenant::set($user->tenant_id);
    }

    public function test_successful_callback_activates_subscription_and_unblocks_the_tenant(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_fake']);

        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);

        app(RolePermissionSeeder::class)->run();
        app(RolePermissionSeeder::class)->seedForTeam($tenant->id);
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
        $owner->assignRole('Business Owner');

        $plan = SubscriptionPlan::factory()->create(['price' => 29]);

        $payment = SubscriptionPayment::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'initiated_by_user_id' => $owner->id,
            'reference' => 'DIGIPLUS-callback-test',
            'amount' => 29,
            'status' => 'pending',
        ]);

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'success', 'reference' => $payment->reference],
            ]),
        ]);

        $this->loginAs($owner);

        $this->get(route('billing.paystack.callback', ['reference' => $payment->reference]))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('subscription_payments', ['id' => $payment->id, 'status' => 'successful']);
        $this->assertDatabaseHas('tenant_subscriptions', ['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active']);

        // The tenant is now unblocked -- a normal page load succeeds instead
        // of redirecting back to billing.renew.
        $this->get('/dashboard')->assertOk();
    }

    public function test_failed_verification_redirects_back_to_billing_with_an_error(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_fake']);

        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);

        app(RolePermissionSeeder::class)->run();
        app(RolePermissionSeeder::class)->seedForTeam($tenant->id);
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
        $owner->assignRole('Business Owner');

        $plan = SubscriptionPlan::factory()->create();

        $payment = SubscriptionPayment::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'initiated_by_user_id' => $owner->id,
            'reference' => 'DIGIPLUS-failed-test',
            'status' => 'pending',
        ]);

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'failed', 'reference' => $payment->reference],
            ]),
        ]);

        $this->loginAs($owner);

        $this->get(route('billing.paystack.callback', ['reference' => $payment->reference]))
            ->assertRedirect(route('billing.renew'));

        $this->assertDatabaseHas('subscription_payments', ['id' => $payment->id, 'status' => 'failed']);
        $this->assertDatabaseCount('tenant_subscriptions', 0);
    }
}
