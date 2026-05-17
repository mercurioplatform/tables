<?php

namespace Mercurio\Tables\Source\Support;

use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Internal DTO для нормализации payload'а fetch-closure {@see HttpSource}.
 *
 * Контракт payload'а:
 * ```
 * fn(Query $q, ?string $cursor): array{
 *     rows: array<int, mixed>,
 *     nextCursor: ?string,
 *     prevCursor: ?string,
 *     total: ?int,
 * }
 * ```
 *
 * Мягкая валидация: отсутствующие `nextCursor`/`prevCursor`/`total`
 * нормализуются в `null`. Жёсткая валидация: невалидный `rows`
 * (не массив, отсутствует ключ) → `Log::error` + {@see LogicException}.
 */
final class HttpFetchResult
{
    /**
     * @param  array<int, mixed>  $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly ?string $nextCursor = null,
        public readonly ?string $prevCursor = null,
        public readonly ?int $total = null,
    ) {}

    public static function fromArray(mixed $payload, ?string $resourceKey = null): self
    {
        if (! is_array($payload)) {
            Log::error('tables.source.http.fetch.invalid_payload', [
                'resource' => $resourceKey,
                'reason' => 'fetch-closure вернул не-массив',
                'type' => get_debug_type($payload),
            ]);

            throw new LogicException(
                'HttpSource fetch-closure must return an array; got '.get_debug_type($payload).'.'
            );
        }

        if (! array_key_exists('rows', $payload) || ! is_array($payload['rows'])) {
            Log::error('tables.source.http.fetch.invalid_payload', [
                'resource' => $resourceKey,
                'reason' => 'отсутствует или невалиден ключ `rows`',
                'keys' => array_keys($payload),
            ]);

            throw new LogicException(
                'HttpSource fetch-closure payload must contain `rows` (array<int, mixed>).'
            );
        }

        $nextCursor = $payload['nextCursor'] ?? null;
        $prevCursor = $payload['prevCursor'] ?? null;
        $total = $payload['total'] ?? null;

        return new self(
            rows: array_values($payload['rows']),
            nextCursor: is_string($nextCursor) ? $nextCursor : null,
            prevCursor: is_string($prevCursor) ? $prevCursor : null,
            total: is_int($total) ? $total : null,
        );
    }
}
