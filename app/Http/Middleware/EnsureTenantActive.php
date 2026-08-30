<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_super_admin) {
            if ($user->tenant?->status === 'suspended') {
                return redirect()->route('billing.renew');
            }

            $subscription = $user->tenant?->activeSubscription;

            // period_end is date-only and valid through the end of that day, so
            // compare dates rather than $subscription->period_end->isPast() —
            // the latter treats the expiry day's midnight timestamp as already
            // past and would cut the subscription short by almost a full day.
            // A tenant with no active subscription at all is treated the same
            // as an expired one, not as unrestricted.
            if (! $subscription || $subscription->period_end->lt(now()->startOfDay())) {
                return redirect()->route('billing.renew');
            }
        }

        return $next($request);
    }
}
