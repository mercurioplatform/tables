<?php

namespace Mercurio\Tables\Action\Handlers\Support;

use Mercurio\Tables\Api\ApiErrorCode;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Карта reject-причин для bulk-action pipeline. Каждый case описывает один из
 * preflight/execution-сценариев отказа в едином orchestrator'е и кладёт
 * парную пару (log-suffix, ApiErrorCode) для адаптеров.
 *
 * HTTP-статус не дублируется здесь: вызов `$reason->apiErrorCode()->httpStatus()`
 * — единственный source-of-truth.
 */
enum BulkRejectReason: string
{
    case UnknownAction = 'unknown_action';
    case MutateDenied = 'mutate_denied';
    case EmptyIds = 'empty_ids';
    case ProbeMissing = 'probe_missing';
    case Forbidden = 'forbidden';
    case NoHandlerConfigured = 'no_handler';
    case CallbackThrew = 'callback_threw';

    public function logSuffix(): string
    {
        return $this->value;
    }

    public function apiErrorCode(): ApiErrorCode
    {
        return match ($this) {
            self::UnknownAction => ApiErrorCode::ActionNotFound,
            self::MutateDenied => ApiErrorCode::CapabilityUnsupported,
            self::EmptyIds => ApiErrorCode::ValidationFailed,
            self::ProbeMissing => ApiErrorCode::RecordNotFound,
            self::Forbidden => ApiErrorCode::PolicyDenied,
            self::NoHandlerConfigured,
            self::CallbackThrew => ApiErrorCode::MutationFailed,
        };
    }
}
