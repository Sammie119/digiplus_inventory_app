<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaystackWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function makePendingPayment(): array
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
            'reference' => 'DIGIPLUS-webhook-test',
            'amount' => 29,
            'status' => 'pending',
        ]);

        return [$tenant, $plan, $payment];
    }

    private function signedPayload(array $payload, string $secret): array
    {
        $body = json_encode($payload);
        $signature = hash_hmac('sha512', $body, $secret);

        return [$body, $signature];
    }

    public function test_valid_charge_success_event_activates_the_subscription(): void
    {
        [$tenant, $plan, $payment] = $this->makePendingPayment();

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'success', 'reference' => $payment->reference],
            ]),
        ]);

        $payload = ['event' => 'charge.success', 'data' => ['reference' => $payment->reference]];
        [$body, $signature] = $this->signedPayload($payload, 'sk_test_fake');

        $this->call('POST', route('webhooks.paystack'), [], [], [], [
            'HTTP_x-paystack-signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $this->assertDatabaseHas('subscription_payments', ['id' => $payment->id, 'status' => 'successful']);
        $this->assertDatabaseHas('tenant_subscriptions', ['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active']);
    }

    public function test_invalid_signature_is_rejected_and_changes_nothing(): void
    {
        [, , $payment] = $this->makePendingPayment();

        $payload = ['event' => 'charge.success', 'data' => ['reference' => $payment->reference]];
        [$body] = $this->signedPayload($payload, 'sk_test_fake');

        $this->call('POST', route('webhooks.paystack'), [], [], [], [
            'HTTP_x-paystack-signature' => 'not-the-real-signature',
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(401);

        $this->assertDatabaseHas('subscription_payments', ['id' => $payment->id, 'status' => 'pending']);
        $this->assertDatabaseCount('tenant_subscriptions', 0);
    }

    public function test_processing_the_same_reference_twice_only_activates_once(): void
    {
        [$tenant, $plan, $payment] = $this->makePendingPayment();

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'success', 'reference' => $payment->reference],
            ]),
        ]);

        $payload = ['event' => 'charge.success', 'data' => ['reference' => $payment->reference]];
        [$body, $signature] = $this->signedPayload($payload, 'sk_test_fake');

        $headers = ['HTTP_x-paystack-signature' => $signature, 'CONTENT_TYPE' => 'application/json'];

        $this->call('POST', route('webhooks.paystack'), [], [], [], $headers, $body)->assertOk();
        $this->call('POST', route('webhooks.paystack'), [], [], [], $headers, $body)->assertOk();

        $this->assertDatabaseCount('tenant_subscriptions', 1);
        $this->assertDatabaseHas('tenant_subscriptions', ['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active']);
    }
}
