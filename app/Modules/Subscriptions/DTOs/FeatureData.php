<?php

namespace App\Modules\Subscriptions\DTOs;

readonly class FeatureData
{
    public function __construct(public array $attributes) {}

    public static function from(array $data): self
    {
        return new self($data);
    }
}
