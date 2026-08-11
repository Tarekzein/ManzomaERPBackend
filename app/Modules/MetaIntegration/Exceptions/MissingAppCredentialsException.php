<?php

namespace App\Modules\MetaIntegration\Exceptions;

use RuntimeException;

/**
 * Raised when a company tries to use its Meta integration before saving its own
 * App ID and secret. There is no platform-wide app to fall back on: every
 * tenant connects their own.
 */
class MissingAppCredentialsException extends RuntimeException
{
    public static function forCompany(int $companyId): self
    {
        return new self(
            "Company {$companyId} has no Meta App credentials. Each company saves its own App ID and "
            .'secret under Company profile → Meta Integration → App setup.'
        );
    }
}
