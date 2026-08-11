<?php

namespace App\Modules\TikTokIntegration\Exceptions;

use RuntimeException;

/**
 * Raised when a company tries to use its TikTok integration before saving its
 * own App ID and secret. As with Meta, there is no shared platform app.
 */
class MissingAppCredentialsException extends RuntimeException
{
    public static function forCompany(int $companyId): self
    {
        return new self(
            "Company {$companyId} has no TikTok App credentials. Each company saves its own App ID and "
            .'secret under Company profile → TikTok Integration.'
        );
    }
}
