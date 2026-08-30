<?php

use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Support\Paystack;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.minimal')] class extends Component
{
    public ?string $paymentError = null;

    public function mount(): void
    {
        $this->paymentError = session('paymentError');
    }

    #[Computed]
    public function tenant()
    {
        return Auth::user()->tenant;
    }

    #[Computed]
    public function isOwner(): bool
    {
        return Auth::user()->hasRole('Business Owner');
    }

    #[Computed]
    public function isBlocked(): bool
    {
        $tenant = $this->tenant;

        if (! $tenant) {
            return false;
        }

        if ($tenant->status === 'suspended') {
            return true;
        }

        $subscription = $tenant->activeSubscription;

        return (bool) ($subscription && $subscription->period_end->lt(now()->startOfDay()));
    }

    #[Computed]
    public function currentPlanId(): ?int
    {
        return $this->tenant?->activeSubscription?->plan_id;
    }

    #[Computed]
    public function plans()
    {
        return SubscriptionPlan::where('is_trial', false)->orderBy('price')->get();
    }

    public function pay(int $planId): void
    {
        if (! $this->isOwner) {
            return;
        }

        $plan = SubscriptionPlan::where('is_trial', false)->findOrFail($planId);
        $actor = Auth::user();

        $payment = SubscriptionPayment::create([
            'tenant_id' => $actor->tenant_id,
            'plan_id' => $plan->id,
            'initiated_by_user_id' => $actor->id,
            'reference' => 'DIGIPLUS-'.Str::random(24),
            'amount' => $plan->price,
            'currency' => 'GHS',
            'status' => 'pending',
        ]);

        try {
            $subAccount = config('services.paystack.subaccount_code');

            $result = Paystack::initializeTransaction([
                'email' => $actor->email,
                'amount' => (int) round($plan->price * 100),
//                'subaccount' => $subAccount,
                'currency' => $payment->currency,
                'reference' => $payment->reference,
                'callback_url' => route('billing.paystack.callback'),
                'metadata' => [
                    'tenant_id' => $actor->tenant_id,
                    'plan_id' => $plan->id,
                ],
            ]);
        } catch (\RuntimeException) {
            $this->dispatch('toast', message: __('Payments are not configured yet. Please contact support.'), type: 'error');

            return;
        }

        $this->redirect($result['data']['authorization_url']);
    }
}; ?>

<div class="space-y-6">
    @if ($this->tenant?->status === 'suspended')
        <div class="bg-white dark:bg-gray-800 shadow-sm rounded-lg p-10 text-center space-y-3">
            <div class="mx-auto h-12 w-12 rounded-full bg-red-100 dark:bg-red-900/40 flex items-center justify-center">
                <x-icon.ban class="h-6 w-6 text-red-600 dark:text-red-400" />
            </div>
            <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ __('Account Suspended') }}</h1>
            <p class="text-gray-600 dark:text-gray-400 max-w-md mx-auto">{{ __('Your account has been suspended. Please contact support to resolve this.') }}</p>
        </div>
    @elseif (! $this->isOwner)
        <div class="bg-white dark:bg-gray-800 shadow-sm rounded-lg p-10 text-center space-y-3">
            <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ __('Subscription Renewal Needed') }}</h1>
            <p class="text-gray-600 dark:text-gray-400 max-w-md mx-auto">{{ __("Your business's subscription needs attention. Please ask your Business Owner to renew or upgrade it to continue.") }}</p>
        </div>
    @else
        <div class="text-center space-y-2">
            <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                {{ $this->isBlocked ? __('Your license has expired') : __('Billing & Subscription') }}
            </h1>
            <p class="text-gray-600 dark:text-gray-400">
                @if ($this->isBlocked)
                    {{ __('Renew or upgrade your plan below to get :tenant back up and running.', ['tenant' => $this->tenant?->name]) }}
                @else
                    {{ __('Renew your current plan early, or upgrade to a higher tier at any time.') }}
                @endif
            </p>
        </div>

        @if ($paymentError)
            <div class="max-w-lg mx-auto p-4 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 rounded-md text-sm">
                {{ $paymentError }}
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-3">
            @foreach ($this->plans as $plan)
                @php $isCurrent = $plan->id === $this->currentPlanId; @endphp
                <div
                    @class([
                        'bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6 flex flex-col border-2',
                        'border-indigo-500' => $isCurrent,
                        'border-transparent' => ! $isCurrent,
                    ])
                    wire:key="plan-{{ $plan->id }}"
                >
                    @if ($isCurrent)
                        <span class="self-start mb-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-50 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-400">{{ __('Current Plan') }}</span>
                    @endif

                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $plan->name }}</h2>

                    <p class="mt-1 text-3xl font-bold text-gray-900 dark:text-gray-100">
                        GHS {{ number_format($plan->price, 2) }}
                        <span class="text-sm font-normal text-gray-500 dark:text-gray-400">/{{ __('month') }}</span>
                    </p>

                    <ul class="mt-4 space-y-1 text-sm text-gray-600 dark:text-gray-400 flex-1">
                        <li>{{ trans_choice('{1} :count branch|[2,*] :count branches', $plan->max_branches, ['count' => $plan->max_branches]) }}</li>
                        <li>{{ trans_choice('{1} :count user|[2,*] :count users', $plan->max_users, ['count' => $plan->max_users]) }}</li>
                        <li>{{ __(':count transactions/month', ['count' => number_format($plan->max_transactions_per_month)]) }}</li>
                    </ul>

                    <x-primary-button type="button" wire:click="pay({{ $plan->id }})" wire:loading.attr="disabled" wire:target="pay({{ $plan->id }})" class="mt-6 justify-center">
                        {{ $isCurrent ? __('Renew') : __('Switch & Pay') }}
                    </x-primary-button>
                </div>
            @endforeach
        </div>
    @endif
</div>
