<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A request that is valid but cannot run right now because an identical one is
 * already in flight. 409 tells the client to retry rather than to change the
 * payload, which is what separates it from a 422.
 */
class ConflictException extends RuntimeException implements HttpExceptionInterface
{
    public function __construct(
        string $message = 'The request conflicts with one already in progress.',
        private readonly string $errorCode = 'CONFLICT',
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return 409;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return [];
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
