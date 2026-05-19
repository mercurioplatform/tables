<?php

namespace Mercurio\Tables\Api;

use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Стабильные машинно-читаемые коды ошибок JSON API.
 *
 * Используются в `error.code` envelope (см. {@see ApiErrorResponse}). Каждому
 * коду соответствует один HTTP-статус — см. {@see self::httpStatus()}.
 */
enum ApiErrorCode: string
{
    case MalformedQuery = 'MALFORMED_QUERY';
    case ValidationFailed = 'VALIDATION_FAILED';
    case MutationsDisabled = 'MUTATIONS_DISABLED';
    case ResourceNotFound = 'RESOURCE_NOT_FOUND';
    case SourceError = 'SOURCE_ERROR';
    case CapabilityUnsupported = 'CAPABILITY_UNSUPPORTED';
    case PolicyDenied = 'POLICY_DENIED';
    case RecordNotFound = 'RECORD_NOT_FOUND';
    case ActionNotFound = 'ACTION_NOT_FOUND';
    case MutationFailed = 'MUTATION_FAILED';

    public function httpStatus(): int
    {
        return match ($this) {
            self::MalformedQuery => Status::HTTP_BAD_REQUEST,
            self::ValidationFailed,
            self::CapabilityUnsupported => Status::HTTP_UNPROCESSABLE_ENTITY,
            self::MutationsDisabled,
            self::PolicyDenied => Status::HTTP_FORBIDDEN,
            self::ResourceNotFound,
            self::RecordNotFound,
            self::ActionNotFound => Status::HTTP_NOT_FOUND,
            self::SourceError,
            self::MutationFailed => Status::HTTP_INTERNAL_SERVER_ERROR,
        };
    }
}
