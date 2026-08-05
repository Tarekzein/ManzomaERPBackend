<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Subscriptions\Contracts\PaymobGateway;
use App\Modules\Subscriptions\Exceptions\PaymobException;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Services\Paymob\PaymobClient;
use App\Modules\Subscriptions\Support\PaymobSignature;
use Illuminate\Support\Str;

class PaymobCheckoutGateway implements PaymobGateway
{
    public function __construct(private readonly PaymobClient $client) {}

    public function createCheckout(SubscriptionPayment $payment, array $options = []): array
    {
        if ($this->client->hasUnifiedCheckoutCredentials()) {
            return $this->createUnifiedCheckout($payment, $options);
        }

        if ($this->client->hasLegacyCredentials()) {
            return $this->createLegacyCheckout($payment, $options);
        }

        throw PaymobException::misconfigured(
            'Paymob is not configured. Set PAYMOB_SECRET_KEY + PAYMOB_PUBLIC_KEY + PAYMOB_INTEGRATION_ID (Unified Checkout) '
            .'or PAYMOB_API_KEY + PAYMOB_INTEGRATION_ID + PAYMOB_IFRAME_ID (legacy checkout).'
        );
    }

    public function supportsSavedCardCharges(): bool
    {
        return $this->client->hasSavedCardCredentials();
    }

    public function chargeSavedCard(SubscriptionPayment $payment, string $cardToken): array
    {
        if (! $this->supportsSavedCardCharges()) {
            throw PaymobException::misconfigured(
                'Saved-card renewals need PAYMOB_API_KEY and PAYMOB_MOTO_INTEGRATION_ID to be configured.'
            );
        }

        $order = $this->client->createOrder([
            'amount_cents' => $this->amountCents($payment),
            'currency' => $payment->currency,
            'merchant_order_id' => $payment->providerReference(),
            'items' => $this->items($payment),
        ]);

        $paymentKey = $this->client->createPaymentKey([
            'amount_cents' => $this->amountCents($payment),
            'currency' => $payment->currency,
            'order_id' => $order['id'] ?? null,
            'billing_data' => $this->billingData($payment),
            'integration_id' => (int) config('services.paymob.moto_integration_id'),
        ]);

        $result = $this->client->payWithToken($paymentKey, $cardToken);

        return [
            'status' => $this->transactionStatus($result),
            'provider_order_id' => (string) (data_get($result, 'order.id') ?? $order['id'] ?? ''),
            'provider_transaction_id' => (string) (data_get($result, 'id') ?? ''),
            'message' => $this->declineMessage($result),
            'raw' => $result,
        ];
    }

    public function verifyCallback(array $payload, ?string $signature): bool
    {
        $secret = (string) config('services.paymob.hmac_secret');

        if ($secret === '') {
            throw PaymobException::misconfigured('PAYMOB_HMAC_SECRET must be set before Paymob callbacks can be trusted.');
        }

        [$object, $fields] = $this->signedObject($payload);

        return PaymobSignature::matches($object, $fields, $secret, $signature);
    }

    public function normalizeCallback(array $payload): array
    {
        $type = strtolower((string) data_get($payload, 'type', 'transaction'));
        $object = is_array(data_get($payload, 'obj')) ? data_get($payload, 'obj') : $payload;

        return $type === 'token'
            ? $this->normalizeTokenCallback($object)
            : $this->normalizeTransactionCallback($object);
    }

    private function createUnifiedCheckout(SubscriptionPayment $payment, array $options): array
    {
        $intention = $this->client->createIntention([
            'amount' => $this->amountCents($payment),
            'currency' => $payment->currency,
            'payment_methods' => $this->paymentMethods($options),
            'items' => $this->items($payment),
            'billing_data' => $this->billingData($payment),
            'customer' => [
                'first_name' => $this->firstName($payment),
                'last_name' => $this->lastName($payment),
                'email' => $this->email($payment),
                'extras' => ['company_id' => $payment->company_id],
            ],
            'extras' => [
                'payment_reference' => $payment->reference,
                'provider_reference' => $payment->providerReference(),
                'company_id' => $payment->company_id,
                'purpose' => $payment->purpose,
            ],
            'special_reference' => $payment->providerReference(),
            'notification_url' => $options['notification_url'] ?? null,
            'redirection_url' => $options['redirection_url'] ?? null,
        ]);

        $clientSecret = (string) data_get($intention, 'client_secret');

        if ($clientSecret === '') {
            throw PaymobException::fromResponse('v1/intention', 200, $intention);
        }

        return [
            'provider_order_id' => (string) (data_get($intention, 'intention_order_id') ?? data_get($intention, 'id') ?? ''),
            'checkout_url' => $this->client->checkoutUrl($clientSecret),
            'client_secret' => $clientSecret,
            'intention_id' => data_get($intention, 'id'),
            'mode' => 'unified',
        ];
    }

    private function createLegacyCheckout(SubscriptionPayment $payment, array $options): array
    {
        $order = $this->client->createOrder([
            'amount_cents' => $this->amountCents($payment),
            'currency' => $payment->currency,
            'merchant_order_id' => $payment->providerReference(),
            'items' => $this->items($payment),
        ]);

        $paymentKey = $this->client->createPaymentKey([
            'amount_cents' => $this->amountCents($payment),
            'currency' => $payment->currency,
            'order_id' => $order['id'] ?? null,
            'billing_data' => $this->billingData($payment),
            'integration_id' => (int) config('services.paymob.integration_id'),
        ]);

        return [
            'provider_order_id' => (string) ($order['id'] ?? ''),
            'checkout_url' => $this->client->iframeUrl($paymentKey),
            'client_secret' => null,
            'mode' => 'legacy',
            'redirection_url' => $options['redirection_url'] ?? null,
        ];
    }

    /** @return array{0: array, 1: array} the signed object and the field order to hash */
    private function signedObject(array $payload): array
    {
        $type = strtolower((string) data_get($payload, 'type', 'transaction'));
        $object = is_array(data_get($payload, 'obj')) ? data_get($payload, 'obj') : $payload;

        return $type === 'token'
            ? [$object, PaymobSignature::TOKEN_FIELDS]
            : [$object, PaymobSignature::TRANSACTION_FIELDS];
    }

    private function normalizeTransactionCallback(array $object): array
    {
        $sourceData = (array) data_get($object, 'source_data', []);

        return [
            'type' => 'transaction',
            'reference' => $this->reference($object),
            'status' => $this->transactionStatus($object),
            'provider_order_id' => $this->stringOrNull(data_get($object, 'order.id') ?? data_get($object, 'order')),
            'provider_transaction_id' => $this->stringOrNull(data_get($object, 'id')),
            'card_token' => $this->stringOrNull(data_get($object, 'payment_key_claims.extra.token')),
            'card_brand' => $this->stringOrNull($sourceData['sub_type'] ?? null),
            'card_last4' => $this->last4($sourceData['pan'] ?? null),
            'amount_cents' => is_numeric(data_get($object, 'amount_cents')) ? (int) data_get($object, 'amount_cents') : null,
            'message' => $this->declineMessage($object),
        ];
    }

    private function normalizeTokenCallback(array $object): array
    {
        return [
            'type' => 'token',
            'reference' => $this->stringOrNull(data_get($object, 'merchant_order_id')),
            'status' => 'saved',
            'provider_order_id' => $this->stringOrNull(data_get($object, 'order_id')),
            'provider_transaction_id' => null,
            'card_token' => $this->stringOrNull(data_get($object, 'token')),
            'card_brand' => $this->stringOrNull(data_get($object, 'card_subtype')),
            'card_last4' => $this->last4(data_get($object, 'masked_pan')),
            'amount_cents' => null,
            'message' => null,
        ];
    }

    private function transactionStatus(array $object): string
    {
        $success = filter_var(data_get($object, 'success', false), FILTER_VALIDATE_BOOL);
        $pending = filter_var(data_get($object, 'pending', false), FILTER_VALIDATE_BOOL);

        return match (true) {
            filter_var(data_get($object, 'is_refunded', false), FILTER_VALIDATE_BOOL) => 'refunded',
            filter_var(data_get($object, 'is_voided', false), FILTER_VALIDATE_BOOL) => 'voided',
            $success && ! $pending => 'succeeded',
            $pending => 'pending',
            default => 'failed',
        };
    }

    private function reference(array $object): ?string
    {
        return $this->stringOrNull(
            data_get($object, 'order.merchant_order_id')
                ?? data_get($object, 'merchant_order_id')
                ?? data_get($object, 'payment_key_claims.extra.payment_reference')
                ?? data_get($object, 'payment_key_claims.billing_data.extra_description')
        );
    }

    private function declineMessage(array $object): ?string
    {
        $message = data_get($object, 'data.message')
            ?? data_get($object, 'data.acq_response_code_description')
            ?? data_get($object, 'message');

        return $message ? Str::limit((string) $message, 250, '') : null;
    }

    private function paymentMethods(array $options): array
    {
        $methods = $options['payment_methods'] ?? [config('services.paymob.integration_id')];

        return collect($methods)
            ->filter()
            ->map(fn ($method) => is_numeric($method) ? (int) $method : $method)
            ->values()
            ->all();
    }

    private function items(SubscriptionPayment $payment): array
    {
        $payment->loadMissing('plan');

        return [[
            'name' => Str::limit(($payment->plan?->name ?? 'Subscription').' ('.$payment->billing_cycle.')', 50, ''),
            'amount' => $this->amountCents($payment),
            'description' => Str::limit('ManzomaERP '.($payment->purpose ?? 'subscription').' payment', 120, ''),
            'quantity' => 1,
        ]];
    }

    private function billingData(SubscriptionPayment $payment): array
    {
        $payment->loadMissing('user', 'company');
        $settings = (array) ($payment->company?->settings ?? []);

        return [
            'first_name' => $this->firstName($payment),
            'last_name' => $this->lastName($payment),
            'email' => $this->email($payment),
            'phone_number' => (string) ($settings['contact_phone'] ?? '+201000000000'),
            'street' => (string) ($settings['address'] ?? 'NA'),
            'building' => 'NA',
            'floor' => 'NA',
            'apartment' => 'NA',
            'city' => (string) ($settings['city'] ?? 'NA'),
            'state' => (string) ($settings['state'] ?? 'NA'),
            'country' => (string) ($settings['country'] ?? 'EG'),
            'postal_code' => (string) ($settings['postal_code'] ?? 'NA'),
            'shipping_method' => 'NA',
            'extra_description' => $payment->providerReference(),
        ];
    }

    private function firstName(SubscriptionPayment $payment): string
    {
        return Str::before(trim((string) ($payment->user?->name ?: 'ManzomaERP')), ' ') ?: 'ManzomaERP';
    }

    private function lastName(SubscriptionPayment $payment): string
    {
        $name = trim((string) ($payment->user?->name ?: ''));
        $last = Str::contains($name, ' ') ? Str::after($name, ' ') : '';

        return $last !== '' ? $last : ($payment->company?->name ?: 'Customer');
    }

    private function email(SubscriptionPayment $payment): string
    {
        return (string) ($payment->user?->email ?: 'billing@'.parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'billing@manzoma.local');
    }

    private function amountCents(SubscriptionPayment $payment): int
    {
        return (int) round(((float) $payment->amount) * 100);
    }

    private function last4(mixed $pan): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $pan);

        return $digits ? substr($digits, -4) : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : (string) $value;
    }
}
