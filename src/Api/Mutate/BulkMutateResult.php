<?php

namespace Mercurio\Tables\Api\Mutate;

/**
 * Результат bulk-action через JSON API.
 *
 * Два режима:
 * - **sync** — `progressId === null`, counts заполнены, `affectedIds` опционален;
 * - **queued** — `progressId !== null`, counts всегда 0 (job сам напишет лог по завершению).
 */
final readonly class BulkMutateResult implements MutateResult
{
    /**
     * @param  array<int, int|string>  $affectedIds
     */
    public function __construct(
        public int $affected,
        public int $missing,
        public int $denied,
        public int $skipped,
        public ?string $progressId,
        public array $affectedIds,
        public ?int $undoLogId = null,
    ) {}
}
