<?php

namespace App\Modules\Subscriptions\DTOs;

readonly class PlanData
{
    public function __construct(public array $attributes) {}

    public static function from(array $data): self
    {
        return new self($data);
    }
}
