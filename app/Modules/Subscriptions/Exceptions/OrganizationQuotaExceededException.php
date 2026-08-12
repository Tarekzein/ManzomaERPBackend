<?php

namespace App\Modules\Subscriptions\Exceptions;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class OrganizationQuotaExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $details,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        $response = ApiResponse::error(
            $this->getMessage(),
            [$this->resourceKey() => [$this->getMessage()]],
            422,
            ['code' => $this->errorCode, 'details' => $this->details],
        );

        // Keep the error code at the top level for clients that dispatch on it,
        // while retaining the application's standard API response envelope.
        $payload = $response->getData(true);
        $payload['code'] = $this->errorCode;
        $payload['details'] = $this->details;

        return response()->json($payload, 422);
    }

    private function resourceKey(): string
    {
        return match ($this->errorCode) {
            'COMPANY_LIMIT_REACHED' => 'company',
            'USER_LIMIT_REACHED' => 'user',
            default => 'plan',
        };
    }
}
