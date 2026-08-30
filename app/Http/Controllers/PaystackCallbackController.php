<?php

namespace App\Http\Controllers;

use App\Actions\Billing\ActivateSubscriptionFromPayment;
use App\Models\SubscriptionPayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PaystackCallbackController extends Controller
{
    public function __invoke(Request $request, ActivateSubscriptionFromPayment $activateSubscription): RedirectResponse
    {
        $reference = $request->query('reference');

        if ($reference) {
            $activateSubscription($reference);
        }

        $payment = $reference
            ? SubscriptionPayment::withoutTenantScope()->where('reference', $reference)->first()
            : null;

        if ($payment?->status === 'successful') {
            return redirect()->route('dashboard');
        }

        return redirect()->route('billing.renew')
            ->with('paymentError', __('We could not confirm your payment. Please try again or contact support.'));
    }
}
