<?php

namespace App\Modules\Authentication\DTOs;

readonly class CreateUserData
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public string $role,
        public ?int $companyId,
        public ?array $allowedPermissions = null,
        public ?array $deniedPermissions = null,
    ) {}

    public static function from(array $data): self
    {
        return new self(
            $data['name'],
            $data['email'],
            $data['password'],
            $data['role'],
            $data['company_id'] ?? null,
            $data['allowed_permissions'] ?? $data['permissions'] ?? null,
            $data['denied_permissions'] ?? null,
        );
    }
}
