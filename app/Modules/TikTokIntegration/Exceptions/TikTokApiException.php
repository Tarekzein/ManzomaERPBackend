<?php

namespace App\Modules\TikTokIntegration\Exceptions;

use RuntimeException;

/**
 * TikTok reports failures inside a 200 response body (`code` != 0) as well as
 * through HTTP status codes, so both paths funnel through here.
 */
class TikTokApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $apiCode = 0,
        private readonly int $httpStatus = 0,
        private readonly ?string $requestId = null,
    ) {
        parent::__construct($message);
    }

    public static function fromBody(array $body, int $httpStatus): self
    {
        return new self(
            $body['message'] ?? 'TikTok API request failed.',
            (int) ($body['code'] ?? 0),
            $httpStatus,
            $body['request_id'] ?? null,
        );
    }

    /** Rate limiting and transient server-side faults are worth retrying. */
    public function isRetryable(): bool
    {
        return in_array($this->apiCode, [40100, 40133, 50000, 51000], true)
            || $this->httpStatus === 429
            || $this->httpStatus >= 500;
    }

    /** Token expired, revoked, or not authorised for this advertiser. */
    public function isAuthFailure(): bool
    {
        return in_array($this->apiCode, [40001, 40002, 40105, 40110], true) || $this->httpStatus === 401;
    }

    public function apiCode(): int
    {
        return $this->apiCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }
}
