<?php

namespace App\Console\Commands;

use App\Mail\SubscriptionRenewalReminderMail;
use App\Models\TenantSubscription;
use App\Support\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;

class SendSubscriptionRenewalReminders extends Command
{
    protected $signature = 'subscriptions:send-renewal-reminders';

    protected $description = 'Email tenant owners whose subscription renews within 7 days';

    public function handle(): int
    {
        $subscriptions = TenantSubscription::withoutTenantScope()
            ->with(['tenant', 'plan'])
            ->where('status', 'active')
            ->whereBetween('period_end', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->get();

        foreach ($subscriptions as $subscription) {
            CurrentTenant::set($subscription->tenant_id);
            app(PermissionRegistrar::class)->setPermissionsTeamId($subscription->tenant_id);

            $owners = $subscription->tenant->users()
                ->whereHas('roles', fn ($q) => $q->where('name', 'Business Owner'))
                ->get();

            foreach ($owners as $owner) {
                Mail::to($owner->email)->send(new SubscriptionRenewalReminderMail($subscription));
            }

            CurrentTenant::clear();

            $this->info("Sent renewal reminder for {$subscription->tenant->name} to {$owners->count()} owner(s).");
        }

        return self::SUCCESS;
    }
}
