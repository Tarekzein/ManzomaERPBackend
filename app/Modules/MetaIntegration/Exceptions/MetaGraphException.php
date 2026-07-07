<?php

namespace App\Modules\MetaIntegration\Exceptions;

use Exception;

class MetaGraphException extends Exception
{
    private const RETRYABLE_CODES = [1, 2, 4, 17, 341];

    public function __construct(
        string $message,
        private readonly int $errorCode = 0,
        private readonly int $errorSubcode = 0,
        private readonly ?string $fbtraceId = null,
        private readonly int $httpStatus = 0,
    ) {
        parent::__construct($message);
    }

    public static function fromResponseBody(array $body, int $httpStatus): self
    {
        $error = $body['error'] ?? [];

        return new self(
            $error['message'] ?? 'Meta Graph API request failed.',
            (int) ($error['code'] ?? 0),
            (int) ($error['error_subcode'] ?? 0),
            $error['fbtrace_id'] ?? null,
            $httpStatus,
        );
    }

    public function isRetryable(): bool
    {
        return in_array($this->errorCode, self::RETRYABLE_CODES, true);
    }

    public function isAuthFailure(): bool
    {
        return $this->errorCode === 190;
    }

    public function errorCode(): int
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function fbtraceId(): ?string
    {
        return $this->fbtraceId;
    }
}
