@php
    $tenant = auth()->user()->tenant;
    $subscription = $tenant?->activeSubscription;
    $isTrial = $tenant?->status === 'trial';
    $daysLeft = $subscription?->period_end
        ? now()->startOfDay()->diffInDays($subscription->period_end, false)
        : null;
@endphp
@php
    $isExpired = $daysLeft !== null && $daysLeft < 0;
@endphp
@if ($tenant)
    <span
        @class([
            'shrink-0 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
            'bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-400' => $isExpired,
            'bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400' => ! $isExpired && $isTrial,
            'bg-indigo-50 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-400' => ! $isExpired && ! $isTrial && $subscription,
            'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300' => ! $subscription,
        ])
    >
        @if ($subscription)
            {{ $subscription->plan->name }}
            @if ($isExpired)
                &middot; {{ __('Expired') }}
            @elseif ($isTrial && $daysLeft !== null)
                &middot; {{ trans_choice('{0} :count days left|{1} :count day left|[2,*] :count days left', $daysLeft, ['count' => $daysLeft]) }}
            @endif
        @else
            {{ __('No Plan') }}
        @endif
    </span>
@endif
