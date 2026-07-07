<?php

namespace App\Modules\MetaIntegration\Services;

class MetaHashingService
{
    public function hash(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return hash('sha256', $value);
    }

    public function normalizeEmail(?string $email): ?string
    {
        return $email ? strtolower(trim($email)) : null;
    }

    public function normalizePhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        return preg_replace('/[^0-9]/', '', $phone) ?: null;
    }

    public function normalizeName(?string $name): ?string
    {
        return $name ? strtolower(trim($name)) : null;
    }

    /**
     * Build a Meta CAPI `user_data` object. PII fields (em/ph/fn/ln) are hashed;
     * fbc/fbp/client_ip_address/client_user_agent are sent unhashed per Meta's spec.
     */
    public function hashedUserData(
        ?string $email = null,
        ?string $phone = null,
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $fbc = null,
        ?string $fbp = null,
        ?string $clientIp = null,
        ?string $userAgent = null,
        ?string $externalId = null,
    ): array {
        return array_filter([
            'em' => $this->hash($this->normalizeEmail($email)),
            'ph' => $this->hash($this->normalizePhone($phone)),
            'fn' => $this->hash($this->normalizeName($firstName)),
            'ln' => $this->hash($this->normalizeName($lastName)),
            'external_id' => $this->hash($externalId),
            'fbc' => $fbc,
            'fbp' => $fbp,
            'client_ip_address' => $clientIp,
            'client_user_agent' => $userAgent,
        ]);
    }
}
