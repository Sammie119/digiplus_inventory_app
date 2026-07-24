<?php

namespace App\Mail;

use App\Models\TenantSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SubscriptionRenewalReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public TenantSubscription $subscription) {}

    public function build(): static
    {
        return $this
            ->subject(__('Your subscription renews soon'))
            ->markdown('mail.subscription-renewal-reminder', [
                'tenantName' => $this->subscription->tenant->name,
                'planName' => $this->subscription->plan->name,
                'periodEnd' => $this->subscription->period_end->format('d M Y'),
            ]);
    }
}
