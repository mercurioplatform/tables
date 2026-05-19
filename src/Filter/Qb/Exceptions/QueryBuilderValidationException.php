<?php

namespace Mercurio\Tables\Filter\Qb\Exceptions;

use Mercurio\Tables\Api\ApiQueryParser;
use Mercurio\Tables\Filter\Qb\QueryBuilderParser;
use RuntimeException;
use Throwable;

/**
 * Брошен {@see QueryBuilderParser} в strict-режиме,
 * когда QB-tree нарушает контракт: неизвестное поле / запрещённый operator /
 * сломанная структура / превышение лимитов.
 *
 * Strict-режим активируется только из API-пути ({@see ApiQueryParser}),
 * UI-путь (SavedView, FilterPipeline) использует silent-skip и не получает это исключение.
 *
 * ApiQueryParser маппит `$kind` в HTTP-ответ:
 * - whitelist-провалы (`unknown_field`, `operator_not_allowed`, `empty_value`,
 *   `value_not_normalizable`) → 422 `VALIDATION_FAILED`;
 * - структурные/лимитные ошибки (`unknown_node_type`, `invalid_op`, `depth_exceeded`,
 *   `atoms_exceeded`, `malformed_base64`, `malformed_json`, `payload_too_large`)
 *   → 400 `MALFORMED_QUERY`.
 *
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 */
final class QueryBuilderValidationException extends RuntimeException
{
    public const KIND_UNKNOWN_FIELD = 'unknown_field';

    public const KIND_OPERATOR_NOT_ALLOWED = 'operator_not_allowed';

    public const KIND_UNKNOWN_NODE_TYPE = 'unknown_node_type';

    public const KIND_DEPTH_EXCEEDED = 'depth_exceeded';

    public const KIND_ATOMS_EXCEEDED = 'atoms_exceeded';

    public const KIND_MALFORMED_BASE64 = 'malformed_base64';

    public const KIND_MALFORMED_JSON = 'malformed_json';

    public const KIND_PAYLOAD_TOO_LARGE = 'payload_too_large';

    public const KIND_INVALID_OP = 'invalid_op';

    public const KIND_EMPTY_VALUE = 'empty_value';

    public const KIND_VALUE_NOT_NORMALIZABLE = 'value_not_normalizable';

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?string $field = null,
        public readonly ?string $operator = null,
        public readonly array $details = [],
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : self::defaultMessage($kind, $field, $operator),
            0,
            $previous,
        );
    }

    private static function defaultMessage(string $kind, ?string $field, ?string $operator): string
    {
        return match ($kind) {
            self::KIND_UNKNOWN_FIELD => "QB-tree: unknown field '{$field}'.",
            self::KIND_OPERATOR_NOT_ALLOWED => "QB-tree: operator '{$operator}' is not allowed for field '{$field}'.",
            self::KIND_UNKNOWN_NODE_TYPE => 'QB-tree: unknown node type.',
            self::KIND_DEPTH_EXCEEDED => 'QB-tree: max depth exceeded.',
            self::KIND_ATOMS_EXCEEDED => 'QB-tree: max atoms exceeded.',
            self::KIND_MALFORMED_BASE64 => 'QB-tree: malformed base64 payload.',
            self::KIND_MALFORMED_JSON => 'QB-tree: malformed JSON payload.',
            self::KIND_PAYLOAD_TOO_LARGE => 'QB-tree: payload too large.',
            self::KIND_INVALID_OP => 'QB-tree: invalid group op (expected AND or OR).',
            self::KIND_EMPTY_VALUE => "QB-tree: empty value for field '{$field}'.",
            self::KIND_VALUE_NOT_NORMALIZABLE => "QB-tree: value cannot be normalized for field '{$field}'.",
            default => "QB-tree validation failed: {$kind}.",
        };
    }
}
