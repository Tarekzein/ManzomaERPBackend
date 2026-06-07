<?php

namespace App\Modules\Subscriptions\DTOs;

readonly class SubscribeData
{
    public function __construct(
        public string $planSlug,
        public string $billingCycle,
    ) {}

    public static function from(array $data): self
    {
        return new self($data['plan_slug'], $data['billing_cycle']);
    }
}
