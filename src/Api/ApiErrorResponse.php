<?php

namespace Mercurio\Tables\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Mercurio\Tables\Api\Exceptions\ApiValidationException;

/**
 * Factory единого JSON-envelope'а ошибок API.
 *
 * Body shape:
 * ```json
 * {"error": {"code": "VALIDATION_FAILED", "message": "...", "details": {...}}}
 * ```
 *
 * Status code маппится из {@see ApiErrorCode::httpStatus()}.
 */
final class ApiErrorResponse
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function make(ApiErrorCode $code, string $message, array $details = []): JsonResponse
    {
        Log::warning('tables.api.error', [
            'code' => $code->value,
            'status' => $code->httpStatus(),
            'message' => $message,
            'details' => $details,
        ]);

        return new JsonResponse(
            [
                'error' => [
                    'code' => $code->value,
                    'message' => $message,
                    'details' => (object) $details,
                ],
            ],
            $code->httpStatus(),
        );
    }

    /**
     * @param  array<int, string>  $allowed
     */
    public static function fieldNotAllowed(string $field, array $allowed): JsonResponse
    {
        return self::make(
            ApiErrorCode::ValidationFailed,
            "Field '{$field}' is not allowed by API config.",
            ['field' => $field, 'allowed' => array_values($allowed)],
        );
    }

    /**
     * @param  array<int, string>  $allowed
     */
    public static function operatorNotAllowed(string $field, string $operator, array $allowed): JsonResponse
    {
        return self::make(
            ApiErrorCode::ValidationFailed,
            "Operator '{$operator}' is not allowed for field '{$field}'.",
            ['field' => $field, 'operator' => $operator, 'allowed' => array_values($allowed)],
        );
    }

    public static function capabilityUnsupported(string $capability): JsonResponse
    {
        return self::make(
            ApiErrorCode::CapabilityUnsupported,
            "Source does not support capability '{$capability}'.",
            ['capability' => $capability],
        );
    }

    public static function fromException(ApiValidationException $e): JsonResponse
    {
        return self::make($e->errorCode, $e->getMessage(), $e->details);
    }
}
