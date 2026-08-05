<?php

namespace App\Modules\Subscriptions\Contracts;

use App\Modules\Subscriptions\Models\SubscriptionPayment;

interface PaymobGateway
{
    /**
     * Open a hosted checkout session for the payment.
     *
     * @return array{provider_order_id: string|null, checkout_url: string|null, mode: string, client_secret?: string|null}
     */
    public function createCheckout(SubscriptionPayment $payment, array $options = []): array;

    /**
     * Charge a previously saved card token without customer interaction.
     *
     * @return array{status: string, provider_order_id: string|null, provider_transaction_id: string|null, message: string|null, raw: array}
     */
    public function chargeSavedCard(SubscriptionPayment $payment, string $cardToken): array;

    /** Whether the current configuration can charge saved cards for renewals. */
    public function supportsSavedCardCharges(): bool;

    public function verifyCallback(array $payload, ?string $signature): bool;

    /**
     * @return array{type: string, reference: string|null, status: string, provider_order_id: string|null, provider_transaction_id: string|null, card_token: string|null, card_brand: string|null, card_last4: string|null, amount_cents: int|null, message: string|null}
     */
    public function normalizeCallback(array $payload): array;
}
