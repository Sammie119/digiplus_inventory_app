<?php

namespace App\Actions\Billing;

use App\Models\SubscriptionPayment;
use App\Models\TenantSubscription;
use App\Support\Paystack;
use Illuminate\Support\Facades\DB;

/**
 * Shared by both the browser callback and the Paystack webhook, so a
 * subscription is never activated twice no matter which path fires first
 * (or if both fire).
 */
class ActivateSubscriptionFromPayment
{
    public function __invoke(string $reference): void
    {
        $payment = SubscriptionPayment::withoutTenantScope()->where('reference', $reference)->first();

        if (! $payment || $payment->status === 'successful') {
            return;
        }

        // Always re-verify server-side with Paystack directly -- never trust
        // the webhook payload or the callback query string alone.
        $result = Paystack::verifyTransaction($reference);

        if (($result['data']['status'] ?? null) !== 'success') {
            $payment->update(['status' => 'failed']);

            return;
        }

        DB::transaction(function () use ($payment) {
            $payment->update(['status' => 'successful', 'paid_at' => now()]);

            TenantSubscription::withoutTenantScope()
                ->where('tenant_id', $payment->tenant_id)
                ->where('status', 'active')
                ->update(['status' => 'cancelled']);

            TenantSubscription::create([
                'tenant_id' => $payment->tenant_id,
                'plan_id' => $payment->plan_id,
                'period_start' => now()->toDateString(),
                'period_end' => now()->addMonth()->toDateString(),
                'status' => 'active',
            ]);
        });
    }
}
