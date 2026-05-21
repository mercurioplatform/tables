<?php

namespace Mercurio\Tables\Action\Handlers\Support\BulkPipelineOutcome;

use Mercurio\Tables\Action\Handlers\Support\BulkPipelineOutcome;
use Mercurio\Tables\Action\Handlers\Support\BulkRejectReason;
use Mercurio\Tables\Action\Helpers\ActionResponseBuilder;
use Throwable;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Для {@see BulkRejectReason::CallbackThrew} HTML-адаптер использует
 * сохранённый `$exception` для прокидывания в onError callback через
 * {@see ActionResponseBuilder::flashFromException()}.
 * API-адаптеру оригинальный exception не нужен — `$details['exception']`
 * уже содержит class-name.
 */
final class Rejected extends BulkPipelineOutcome
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly BulkRejectReason $reason,
        public readonly string $message,
        public readonly array $details = [],
        public readonly ?Throwable $exception = null,
    ) {}
}
