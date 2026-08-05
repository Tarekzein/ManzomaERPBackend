<?php

namespace App\Modules\Subscriptions\Exceptions;

use RuntimeException;

class PaymobException extends RuntimeException
{
    public function __construct(string $message, private readonly array $context = [], int $code = 0)
    {
        parent::__construct($message, $code);
    }

    public static function fromResponse(string $endpoint, int $status, mixed $body): self
    {
        $message = is_array($body)
            ? (data_get($body, 'detail') ?? data_get($body, 'message') ?? json_encode($body, JSON_UNESCAPED_SLASHES))
            : (string) $body;

        return new self(
            "Paymob request to [{$endpoint}] failed with status {$status}: {$message}",
            ['endpoint' => $endpoint, 'status' => $status, 'body' => $body],
            $status,
        );
    }

    public static function misconfigured(string $message): self
    {
        return new self($message, ['reason' => 'configuration']);
    }

    public function context(): array
    {
        return $this->context;
    }
}
