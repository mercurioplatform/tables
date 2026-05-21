<?php

namespace Mercurio\Tables\Action\Handlers\Support;

use Mercurio\Tables\Api\Mutate\BulkMutateResult;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Sealed-style outcome для {@see BulkActionPipeline}: каждый pipeline-метод
 * возвращает один из финальных подклассов (см.
 * {@see BulkPipelineOutcome\Rejected}, {@see BulkPipelineOutcome\Queued},
 * {@see BulkPipelineOutcome\Executed}), а адаптер маппит его в Response
 * (HTML) или {@see BulkMutateResult} (JSON-API).
 */
abstract class BulkPipelineOutcome {}
