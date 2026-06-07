<?php

namespace App\Modules\Authentication\DTOs;

readonly class LoginData
{
    public function __construct(
        public string $email,
        public string $password,
        public string $deviceName,
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {}

    public static function from(array $data, ?string $ipAddress, ?string $userAgent): self
    {
        return new self($data['email'], $data['password'], $data['device_name'] ?? 'api', $ipAddress, $userAgent);
    }
}
