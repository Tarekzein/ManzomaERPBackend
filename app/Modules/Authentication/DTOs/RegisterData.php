<?php

namespace App\Modules\Authentication\DTOs;

readonly class RegisterData
{
    public function __construct(
        public string $companyName,
        public string $name,
        public string $email,
        public string $password,
        public string $deviceName,
    ) {}

    public static function from(array $data): self
    {
        return new self($data['company_name'], $data['name'], $data['email'], $data['password'], $data['device_name'] ?? 'api');
    }
}
