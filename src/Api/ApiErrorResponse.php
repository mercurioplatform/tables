<?php

namespace Mercurio\Tables\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Mercurio\Tables\Api\Exceptions\ApiValidationException;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Factory единого JSON-envelope'а ошибок API.
 *
 * Body shape:
 * ```json
 * {"error": {"code": "VALIDATION_FAILED", "message": "...", "details": {...}}}
 * ```
 *
 * Status code маппится из {@see ApiErrorCode::httpStatus()}. Это единственная
 * точка логирования API-ошибок — парсеры не логируют отдельно перед throw'ом,
 * чтобы избежать дублирования. Уровень лога зависит от статуса: 5xx → error,
 * 4xx → warning.
 */
final class ApiErrorResponse
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function make(ApiErrorCode $code, string $message, array $details = []): JsonResponse
    {
        $status = $code->httpStatus();
        $level = $status >= Status::HTTP_INTERNAL_SERVER_ERROR ? 'error' : 'warning';

        Log::log($level, 'tables.api.error', [
            'code' => $code->value,
            'status' => $status,
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
            $status,
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
