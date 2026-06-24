<?php

namespace App\Modules\Authentication\DTOs;

use App\Modules\Authentication\Enums\UserRole;

readonly class CreateUserData
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public UserRole $role,
        public ?int $companyId,
        public ?array $permissions = null,
    ) {}

    public static function from(array $data): self
    {
        return new self(
            $data['name'],
            $data['email'],
            $data['password'],
            UserRole::from($data['role']),
            $data['company_id'] ?? null,
            array_key_exists('permissions', $data) ? $data['permissions'] : null
        );
    }
}
