<?php

namespace Mercurio\Tables\Api\Mutate;

/**
 * Результат выполнения cell-edit-операции через JSON API.
 *
 * `$undoLogId` всегда null — cell-edit не undoable
 * (`CellUpdateHandler` не зовёт `ActionLogWriter::write`).
 */
final readonly class CellMutateResult implements MutateResult
{
    /**
     * @param  array<string, mixed>|null  $freshRow
     */
    public function __construct(
        public int|string $id,
        public ?array $freshRow,
        public ?int $undoLogId = null,
    ) {}
}
