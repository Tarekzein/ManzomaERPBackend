<?php

namespace App\Modules\Subscriptions\Services\Paymob;

use App\Modules\Subscriptions\Exceptions\PaymobException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin transport layer over the Paymob Accept API. It covers both the modern
 * Unified Checkout (intention) flow used for interactive checkouts and the
 * legacy three-step flow, which is still the only way to charge a saved card
 * token for automatic renewals.
 */
class PaymobClient
{
    private ?string $authToken = null;

    public function hasUnifiedCheckoutCredentials(): bool
    {
        return (bool) ($this->config('secret_key') && $this->config('public_key') && $this->config('integration_id'));
    }

    public function hasLegacyCredentials(): bool
    {
        return (bool) ($this->config('api_key') && $this->config('integration_id') && $this->config('iframe_id'));
    }

    public function hasSavedCardCredentials(): bool
    {
        return (bool) ($this->config('api_key') && $this->config('moto_integration_id'));
    }

    /**
     * Create a payment intention and return the payload containing the
     * `client_secret` that Unified Checkout is rendered with.
     */
    public function createIntention(array $payload): array
    {
        $response = $this->request()
            ->withToken($this->config('secret_key'), 'Token')
            ->post($this->intentionUrl(), $payload);

        if ($response->failed()) {
            throw PaymobException::fromResponse('v1/intention', $response->status(), $response->json() ?? $response->body());
        }

        return $response->json() ?? [];
    }

    public function checkoutUrl(string $clientSecret): string
    {
        return rtrim((string) $this->config('checkout_url'), '/').'/?'.http_build_query([
            'publicKey' => $this->config('public_key'),
            'clientSecret' => $clientSecret,
        ]);
    }

    public function iframeUrl(string $paymentKey): string
    {
        return $this->url("acceptance/iframes/{$this->config('iframe_id')}").'?'.http_build_query([
            'payment_token' => $paymentKey,
        ]);
    }

    /** Legacy step 1: exchange the API key for a short-lived auth token. */
    public function authenticate(): string
    {
        if ($this->authToken) {
            return $this->authToken;
        }

        if (! $this->config('api_key')) {
            throw PaymobException::misconfigured('PAYMOB_API_KEY is required for this operation.');
        }

        $response = $this->request()->post($this->url('auth/tokens'), [
            'api_key' => $this->config('api_key'),
        ]);

        if ($response->failed()) {
            throw PaymobException::fromResponse('auth/tokens', $response->status(), $response->json() ?? $response->body());
        }

        $token = $response->json('token');

        if (! $token) {
            throw PaymobException::fromResponse('auth/tokens', $response->status(), $response->json() ?? []);
        }

        return $this->authToken = $token;
    }

    /** Legacy step 2: register the order against the merchant account. */
    public function createOrder(array $payload): array
    {
        $response = $this->request()->post($this->url('ecommerce/orders'), array_replace([
            'auth_token' => $this->authenticate(),
            'delivery_needed' => false,
        ], $payload));

        if ($response->failed()) {
            throw PaymobException::fromResponse('ecommerce/orders', $response->status(), $response->json() ?? $response->body());
        }

        return $response->json() ?? [];
    }

    /** Legacy step 3: mint the payment key used by the iframe or MOTO charge. */
    public function createPaymentKey(array $payload): string
    {
        $response = $this->request()->post($this->url('acceptance/payment_keys'), array_replace([
            'auth_token' => $this->authenticate(),
            'expiration' => 3600,
        ], $payload));

        if ($response->failed()) {
            throw PaymobException::fromResponse('acceptance/payment_keys', $response->status(), $response->json() ?? $response->body());
        }

        $token = $response->json('token');

        if (! $token) {
            throw PaymobException::fromResponse('acceptance/payment_keys', $response->status(), $response->json() ?? []);
        }

        return $token;
    }

    /** Charge a stored card token (card-on-file / MOTO transaction). */
    public function payWithToken(string $paymentKey, string $cardToken): array
    {
        $response = $this->request()->post($this->url('acceptance/payments/pay'), [
            'source' => ['identifier' => $cardToken, 'subtype' => 'TOKEN'],
            'payment_token' => $paymentKey,
        ]);

        // A declined card is a business outcome, not a transport failure, so
        // 4xx bodies that still describe a transaction are handed back intact.
        $body = $response->json();

        if ($response->failed() && ! is_array($body)) {
            throw PaymobException::fromResponse('acceptance/payments/pay', $response->status(), $response->body());
        }

        return $body ?? [];
    }

    /** Fetch a transaction so a callback can be confirmed server-side. */
    public function transaction(string|int $transactionId): array
    {
        $response = $this->request()
            ->withToken($this->authenticate())
            ->get($this->url("acceptance/transactions/{$transactionId}"));

        if ($response->failed()) {
            throw PaymobException::fromResponse("acceptance/transactions/{$transactionId}", $response->status(), $response->json() ?? $response->body());
        }

        return $response->json() ?? [];
    }

    public function refund(string|int $transactionId, int $amountCents): array
    {
        $response = $this->request()->post($this->url('acceptance/void_refund/refund'), [
            'auth_token' => $this->authenticate(),
            'transaction_id' => $transactionId,
            'amount_cents' => $amountCents,
        ]);

        if ($response->failed()) {
            throw PaymobException::fromResponse('acceptance/void_refund/refund', $response->status(), $response->json() ?? $response->body());
        }

        return $response->json() ?? [];
    }

    private function request(): PendingRequest
    {
        return Http::asJson()
            ->acceptJson()
            ->timeout((int) $this->config('timeout', 30))
            ->retry(2, 300, throw: false);
    }

    private function url(string $path): string
    {
        return rtrim((string) $this->config('base_url'), '/').'/'.ltrim($path, '/');
    }

    private function intentionUrl(): string
    {
        return (string) $this->config('intention_url');
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config("services.paymob.{$key}", $default);
    }

    public function log(string $message, array $context = []): void
    {
        Log::channel(config('logging.default'))->info("[paymob] {$message}", $context);
    }
}
