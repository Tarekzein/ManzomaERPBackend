<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Subscriptions\Contracts\PaymobGateway;
use App\Modules\Subscriptions\Models\SubscriptionPayment;

/**
 * Local/CI stand-in for Paymob. Enabled with PAYMOB_MODE=mock.
 */
class MockPaymobGateway implements PaymobGateway
{
    public function createCheckout(SubscriptionPayment $payment, array $options = []): array
    {
        return [
            'provider_order_id' => 'mock-order-'.$payment->reference,
            'checkout_url' => config('app.url').'/mock-paymob/'.$payment->reference,
            'client_secret' => 'mock-secret-'.$payment->reference,
            'mode' => 'mock',
        ];
    }

    public function supportsSavedCardCharges(): bool
    {
        return true;
    }

    public function chargeSavedCard(SubscriptionPayment $payment, string $cardToken): array
    {
        // Tokens prefixed with "decline" let tests exercise the dunning path.
        $declined = str_starts_with($cardToken, 'decline');

        return [
            'status' => $declined ? 'failed' : 'succeeded',
            'provider_order_id' => 'mock-order-'.$payment->reference,
            'provider_transaction_id' => 'mock-txn-'.$payment->reference,
            'message' => $declined ? 'Mock card declined.' : null,
            'raw' => ['mock' => true, 'success' => ! $declined],
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
        $type = strtolower((string) data_get($payload, 'type', 'transaction'));
        $object = is_array(data_get($payload, 'obj')) ? data_get($payload, 'obj') : $payload;
        $success = filter_var(data_get($object, 'success', false), FILTER_VALIDATE_BOOL);

        return [
            'type' => $type === 'token' ? 'token' : 'transaction',
            'reference' => (string) data_get($object, 'merchant_order_id', data_get($object, 'reference')),
            'status' => $type === 'token' ? 'saved' : ($success ? 'succeeded' : 'failed'),
            'provider_order_id' => data_get($object, 'order.id', data_get($object, 'order_id')),
            'provider_transaction_id' => data_get($object, 'id'),
            'card_token' => data_get($object, 'token'),
            'card_brand' => data_get($object, 'card_subtype'),
            'card_last4' => data_get($object, 'masked_pan') ? substr(preg_replace('/\D/', '', (string) data_get($object, 'masked_pan')), -4) : null,
            'amount_cents' => is_numeric(data_get($object, 'amount_cents')) ? (int) data_get($object, 'amount_cents') : null,
            'message' => data_get($object, 'message'),
        ];
    }
}
