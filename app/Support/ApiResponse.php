<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'OK',
        array $meta = [],
        int $status = 200
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
            'errors' => null,
            'meta' => (object) $meta,
        ], $status);
    }

    public static function error(
        string $message,
        array $errors = [],
        int $status = 422,
        array $meta = []
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => $message,
            'errors' => (object) $errors,
            'meta' => (object) $meta,
        ], $status);
    }
}
