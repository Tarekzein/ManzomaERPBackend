<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Subscriptions\Contracts\PaymobGateway;
use App\Modules\Subscriptions\Models\SubscriptionPayment;

class MockPaymobGateway implements PaymobGateway
{
    public function createOrder(SubscriptionPayment $payment): array
    {
        return [
            'provider_order_id' => 'mock-order-'.$payment->reference,
            'checkout_url' => config('app.url').'/mock-paymob/'.$payment->reference,
            'mode' => 'mock',
        ];
    }

    public function verifyCallback(array $payload, ?string $signature): bool
    {
        $secret = (string) config('services.paymob.hmac_secret');

        if ($secret === '') {
            return true;
        }

        if (! $signature) {
            return false;
        }

        $expected = hash_hmac('sha512', json_encode($payload, JSON_UNESCAPED_SLASHES), $secret);

        return hash_equals($expected, $signature);
    }

    public function normalizeCallback(array $payload): array
    {
        $success = filter_var(data_get($payload, 'success', false), FILTER_VALIDATE_BOOL);

        return [
            'reference' => (string) data_get($payload, 'merchant_order_id', data_get($payload, 'reference')),
            'status' => $success ? 'succeeded' : 'failed',
            'provider_order_id' => data_get($payload, 'order.id'),
            'provider_transaction_id' => data_get($payload, 'id'),
        ];
    }
}
