<?php

namespace App\Modules\Companies\DTOs;

readonly class CreateCompanyData
{
    public function __construct(
        public string $name,
        public string $timezone,
        public string $locale,
        public string $currency,
    ) {}
}
