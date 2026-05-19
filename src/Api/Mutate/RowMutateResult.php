<?php

namespace Mercurio\Tables\Api\Mutate;

use Mercurio\Tables\Action\ActionResult;

/**
 * Результат row-action через JSON API.
 *
 * Содержит `ActionResult`, возвращённый callback/handler'ом row-action'а,
 * — `MutateRenderer` извлекает из него `affected` / `message` / `payload`
 * для сериализации.
 */
final readonly class RowMutateResult implements MutateResult
{
    public function __construct(
        public int|string $id,
        public ActionResult $actionResult,
        public ?int $undoLogId = null,
    ) {}
}
