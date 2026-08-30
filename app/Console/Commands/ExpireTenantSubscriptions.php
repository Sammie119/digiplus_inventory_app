<?php

namespace App\Console\Commands;

use App\Models\TenantSubscription;
use App\Support\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;

class ExpireTenantSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Flag active subscriptions whose period has ended as expired';

    public function handle(): int
    {
        $subscriptions = TenantSubscription::withoutTenantScope()
            ->where('status', 'active')
            ->where('period_end', '<', now()->toDateString())
            ->get();

        $failures = 0;

        foreach ($subscriptions as $subscription) {
            try {
                CurrentTenant::set($subscription->tenant_id);
                app(PermissionRegistrar::class)->setPermissionsTeamId($subscription->tenant_id);

                $subscription->update(['status' => 'expired']);

                $this->info("Expired subscription #{$subscription->id} for tenant {$subscription->tenant_id}.");
            } catch (\Throwable $e) {
                $failures++;

                Log::error('Subscription expiry failed', [
                    'subscription_id' => $subscription->id,
                    'tenant_id' => $subscription->tenant_id,
                    'exception' => $e,
                ]);

                $this->error("Failed to expire subscription {$subscription->id}: {$e->getMessage()}");
            } finally {
                CurrentTenant::clear();
            }
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
