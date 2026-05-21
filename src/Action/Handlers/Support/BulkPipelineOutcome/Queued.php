<?php

namespace Mercurio\Tables\Action\Handlers\Support\BulkPipelineOutcome;

use Mercurio\Tables\Action\Handlers\Support\BulkPipelineOutcome;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 */
final class Queued extends BulkPipelineOutcome
{
    public function __construct(
        public readonly string $progressId,
    ) {}
}
