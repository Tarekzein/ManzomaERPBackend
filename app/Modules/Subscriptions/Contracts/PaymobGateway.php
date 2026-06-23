<?php

namespace App\Modules\Subscriptions\Contracts;

use App\Modules\Subscriptions\Models\SubscriptionPayment;

interface PaymobGateway
{
    public function createOrder(SubscriptionPayment $payment): array;

    public function verifyCallback(array $payload, ?string $signature): bool;

    public function normalizeCallback(array $payload): array;
}
