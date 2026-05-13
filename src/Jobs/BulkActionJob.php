<?php

namespace Mercurio\Tables\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Mercurio\Tables\Action\Action;
use Mercurio\Tables\Action\ActionResult;
use Mercurio\Tables\Action\BulkAction;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Models\ActionProgress;
use Mercurio\Tables\Services\ActionLogWriter;
use Throwable;

class BulkActionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public int $timeout;

    /**
     * @param  array<int, mixed>  $ids
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $resourceClass,
        public readonly string $actionName,
        public readonly array $ids,
        public readonly array $payload,
        public readonly ?int $actorId,
        public readonly string $progressId,
    ) {
        $this->tries = (int) config('tables.bulk_progress.job_tries', 1);
        $this->timeout = (int) config('tables.bulk_progress.job_timeout_seconds', 600);
    }

    public function handle(): void
    {
        /** @var ActionProgress|null $progress */
        $progress = ActionProgress::query()->find($this->progressId);

        if ($progress === null) {
            Log::warning('tables.bulk_progress.job.progress_missing', [
                'progress_id' => $this->progressId,
                'resource' => $this->resourceClass,
            ]);

            return;
        }

        if ($progress->status !== 'pending') {
            Log::warning('tables.bulk_progress.job.unexpected_status', [
                'progress_id' => $this->progressId,
                'status' => $progress->status,
            ]);

            return;
        }

        $progress->forceFill([
            'status' => 'running',
            'started_at' => now(),
        ])->save();

        /** @var ListResource $resource */
        $resource = app($this->resourceClass);

        Log::info('tables.bulk_progress.job.start', [
            'progress_id' => $this->progressId,
            'resource' => $this->resourceClass,
            'action' => $this->actionName,
            'total' => count($this->ids),
            'actor_id' => $this->actorId,
        ]);

        $guard = $resource->effectiveGuard();

        if ($this->actorId !== null) {
            try {
                Auth::shouldUse($guard);
                Auth::guard($guard)->onceUsingId($this->actorId);
            } catch (Throwable $e) {
                Log::warning('tables.bulk_progress.job.impersonate_failed', [
                    'progress_id' => $this->progressId,
                    'actor_id' => $this->actorId,
                    'guard' => $guard,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        /** @var BulkAction|null $action */
        $action = null;
        foreach ($resource->bulkActions() as $candidate) {
            if ($candidate instanceof BulkAction && $candidate->name === $this->actionName) {
                $action = $candidate;
                break;
            }
        }

        if ($action === null) {
            $this->markFailed($progress, "BulkAction '{$this->actionName}' not found on resource.");

            return;
        }

        $handlerClass = $action->getHandler();

        if ($handlerClass === null) {
            $this->markFailed($progress, "BulkAction '{$this->actionName}' has no handler class.");

            return;
        }

        try {
            /** @var Action $handler */
            $handler = app($handlerClass);
        } catch (Throwable $e) {
            $this->markFailed($progress, "Handler resolve failed: {$e->getMessage()}");

            return;
        }

        $chunkSize = $action->getQueueChunkSize() ?? (int) config('tables.bulk_progress.default_chunk_size', 0);
        $chunks = $chunkSize > 0
            ? array_chunk($this->ids, $chunkSize)
            : [$this->ids];

        $totalAffected = 0;
        $totalMissing = 0;
        $totalDenied = 0;
        $totalSkipped = 0;
        $totalRequested = 0;

        foreach ($chunks as $i => $chunk) {
            try {
                /** @var ActionResult $result */
                $result = $handler->execute($chunk, $this->payload);
            } catch (Throwable $e) {
                Log::error('tables.bulk_progress.job.chunk_failed', [
                    'progress_id' => $this->progressId,
                    'chunk_index' => $i,
                    'chunk_size' => count($chunk),
                    'error' => $e->getMessage(),
                ]);

                $this->markFailed($progress, "Chunk {$i} failed: {$e->getMessage()}");

                throw $e;
            }

            $progress->recordChunkResult($result, $chunk);

            $totalAffected += $result->affected;
            $totalMissing += $result->missing;
            $totalDenied += $result->denied;
            $totalSkipped += $result->skipped;
            $totalRequested += $result->requested ?? count($chunk);
        }

        $progress->forceFill([
            'status' => 'done',
            'finished_at' => now(),
        ])->save();

        Log::info('tables.bulk_progress.job.done', [
            'progress_id' => $this->progressId,
            'resource' => $this->resourceClass,
            'action' => $this->actionName,
            'total' => count($this->ids),
            'affected' => $totalAffected,
            'duration_s' => $progress->started_at !== null
                ? (int) abs(now()->diffInSeconds($progress->started_at))
                : null,
        ]);

        $aggregate = new ActionResult(
            affected: $totalAffected,
            missing: $totalMissing,
            denied: $totalDenied,
            skipped: $totalSkipped,
            requested: $totalRequested > 0 ? $totalRequested : null,
        );

        ActionLogWriter::write(
            resourceKey: $resource->key(),
            actionName: $this->actionName,
            kind: 'bulk',
            actorId: $this->actorId,
            ids: $this->ids,
            payload: $this->payload,
            result: $aggregate,
        );
    }

    public function failed(Throwable $e): void
    {
        /** @var ActionProgress|null $progress */
        $progress = ActionProgress::query()->find($this->progressId);

        if ($progress === null) {
            return;
        }

        if ($progress->status === 'done') {
            return;
        }

        $progress->forceFill([
            'status' => 'failed',
            'finished_at' => now(),
            'error_message' => $e->getMessage(),
        ])->save();

        Log::warning('tables.bulk_progress.job.failed_callback', [
            'progress_id' => $this->progressId,
            'resource' => $this->resourceClass,
            'action' => $this->actionName,
            'error' => $e->getMessage(),
        ]);
    }

    private function markFailed(ActionProgress $progress, string $message): void
    {
        $progress->forceFill([
            'status' => 'failed',
            'finished_at' => now(),
            'error_message' => $message,
        ])->save();

        Log::error('tables.bulk_progress.job.error', [
            'progress_id' => $this->progressId,
            'resource' => $this->resourceClass,
            'action' => $this->actionName,
            'error' => $message,
        ]);
    }
}
