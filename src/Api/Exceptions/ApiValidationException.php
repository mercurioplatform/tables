<?php

namespace Mercurio\Tables\Api\Exceptions;

use Mercurio\Tables\Api\ApiErrorCode;
use Mercurio\Tables\Api\ApiErrorResponse;
use Mercurio\Tables\Api\ApiQueryParser;
use Mercurio\Tables\Http\Controllers\JsonApiController;
use RuntimeException;

/**
 * Бросается {@see ApiQueryParser} при невалидном запросе
 * и {@see JsonApiController} при провале
 * capabilities-проверки. Контроллер ловит и превращает в
 * {@see ApiErrorResponse} с правильным HTTP-статусом.
 */
final class ApiValidationException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly ApiErrorCode $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
