<?php

namespace App\Http\Controllers;

use App\Actions\Billing\ActivateSubscriptionFromPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request, ActivateSubscriptionFromPayment $activateSubscription): JsonResponse
    {
        $signature = $request->header('x-paystack-signature');
        $secretKey = config('services.paystack.secret_key');

        if (! $signature || ! $secretKey) {
            abort(401);
        }

        $expected = hash_hmac('sha512', $request->getContent(), $secretKey);

        if (! hash_equals($expected, $signature)) {
            abort(401);
        }

        if ($request->input('event') === 'charge.success') {
            $reference = $request->input('data.reference');

            if ($reference) {
                $activateSubscription($reference);
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
