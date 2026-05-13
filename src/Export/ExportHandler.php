<?php

namespace Mercurio\Tables\Export;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Mercurio\Tables\Action\Helpers\ActionPayloadResolver;
use Mercurio\Tables\Concerns\HandlesResourceListing;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\ListResource;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Public surface: {@see HandlesResourceListing::export()} (плюс {@see ExportRequest}).
 */
class ExportHandler
{
    public function __construct(private ActionPayloadResolver $payloads) {}

    public function handle(Request $request, ListResource $resource): Response
    {
        $resourceClass = $resource::class;
        $guard = $resource->effectiveGuard();
        $userId = Auth::guard($guard)->id();
        if ($userId === null) {
            abort(401);
        }

        $ability = config('tables.export.ability');
        if ($ability !== null && ! Gate::check($ability)) {
            Log::warning('tables.export.forbidden', [
                'resource' => $resourceClass,
                'user_id' => $userId,
                'ability' => $ability,
            ]);
            abort(403);
        }

        $state = $resource->exportState($request);
        $total = (int) $state['total'];
        $columns = $state['columns'];
        $builder = $state['builder'];

        if ($columns === []) {
            Log::warning('tables.export.no_columns', ['resource' => $resourceClass]);
            abort(422, 'Нет колонок для экспорта.');
        }

        $syncLimit = (int) config('tables.export.sync_limit', 10000);
        if ($total > $syncLimit) {
            $dispatcherClass = config('tables.export.async_dispatcher');
            if (is_string($dispatcherClass) && $dispatcherClass !== '' && class_exists($dispatcherClass)) {
                try {
                    /** @var ExportJobDispatcher $dispatcher */
                    $dispatcher = app($dispatcherClass);
                    $jobId = $dispatcher->dispatch(
                        $resourceClass,
                        (array) $state['queryParams'],
                        (int) $userId,
                        $total,
                    );
                    Log::info('tables.export.dispatched', [
                        'resource' => $resourceClass,
                        'user_id' => $userId,
                        'rows' => $total,
                        'job_id' => $jobId,
                    ]);

                    return response()->json([
                        'status' => 'queued',
                        'message' => "Экспорт ({$total} строк) запущен в фоне. Уведомление придёт по готовности.",
                        'job_id' => $jobId,
                    ], 202);
                } catch (Throwable $e) {
                    Log::error('tables.export.dispatch_failed', [
                        'resource' => $resourceClass,
                        'error' => $e->getMessage(),
                    ]);
                    abort(500, 'Не удалось запустить фоновый экспорт.');
                }
            }

            Log::warning('tables.export.too_large', [
                'resource' => $resourceClass,
                'user_id' => $userId,
                'rows' => $total,
                'sync_limit' => $syncLimit,
            ]);

            return response()->json([
                'status' => 'too_large',
                'message' => "Слишком много строк для экспорта ({$total}). Лимит: {$syncLimit}. Уточните фильтр или обратитесь к администратору.",
            ], 413);
        }

        $exportRequest = new ExportRequest(
            filename: $this->payloads->buildExportFilename($resource->key()),
            delimiter: (string) config('tables.export.csv_delimiter', ','),
            enclosure: (string) config('tables.export.csv_enclosure', '"'),
            escape: (string) config('tables.export.csv_escape', '\\'),
            bom: (bool) config('tables.export.csv_bom', true),
            chunkSize: (int) config('tables.export.chunk_size', 500),
            logChunks: (bool) config('tables.export.log_chunks', false),
            columns: $columns,
        );

        Log::info('tables.export.start', [
            'resource' => $resourceClass,
            'user_id' => $userId,
            'rows' => $total,
            'columns' => array_map(fn (Field $f) => $f->name, $columns),
            'filename' => $exportRequest->filename,
        ]);

        $logger = function (string $event, array $ctx) use ($exportRequest): void {
            Log::info('tables.export.'.$event, ['filename' => $exportRequest->filename] + $ctx);
        };

        return new StreamedResponse(
            function () use ($exportRequest, $builder, $logger): void {
                try {
                    CsvStreamWriter::stream($exportRequest, $builder, $logger);
                } catch (Throwable $e) {
                    Log::error('tables.export.error', [
                        'filename' => $exportRequest->filename,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }
            },
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$exportRequest->filename.'"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }
}
