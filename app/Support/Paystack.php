<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around Paystack's REST API. No SDK dependency -- Laravel's
 * HTTP client (Guzzle, already shipped with the framework) is enough for
 * the two endpoints this app needs.
 */
class Paystack
{
    protected static function secretKey(): string
    {
        $key = config('services.paystack.secret_key');

        if (empty($key)) {
            throw new RuntimeException('Paystack is not configured yet. Set PAYSTACK_SECRET_KEY in the environment.');
        }

        return $key;
    }

    protected static function client()
    {
        return Http::withToken(static::secretKey())
            ->baseUrl('https://api.paystack.co')
            ->throw();
    }

    public static function initializeTransaction(array $payload): array
    {
        return static::client()->post('/transaction/initialize', $payload)->json();
    }

    public static function verifyTransaction(string $reference): array
    {
        return static::client()->get("/transaction/verify/{$reference}")->json();
    }
}
