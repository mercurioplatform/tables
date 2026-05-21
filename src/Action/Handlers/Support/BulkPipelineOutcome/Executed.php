<?php

namespace Mercurio\Tables\Action\Handlers\Support\BulkPipelineOutcome;

use Mercurio\Tables\Action\ActionResult;
use Mercurio\Tables\Action\Handlers\Support\BulkPipelineOutcome;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 */
final class Executed extends BulkPipelineOutcome
{
    /**
     * @param  array<int, mixed>  $ids
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly ?ActionResult $result,
        public readonly ?int $undoLogId,
        public readonly array $ids,
        public readonly array $payload,
    ) {}
}
