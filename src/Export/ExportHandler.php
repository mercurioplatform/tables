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
    public function __construct(
        private ActionPayloadResolver $payloads,
        private ExportWriterRegistry $writers,
    ) {}

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
        $source = $state['source'];

        if (! $source->capabilities()->stream) {
            Log::warning('tables.export.stream_denied', [
                'resource' => $resourceClass,
                'user_id' => $userId,
            ]);
            abort(422, 'Источник данных не поддерживает экспорт.');
        }

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

        $format = $this->resolveFormat($request, $resourceClass);
        $writer = $this->writers->make($format);

        $exportRequest = new ExportRequest(
            filename: $this->payloads->buildExportFilename($resource->key(), $writer->fileExtension()),
            delimiter: (string) config('tables.export.csv_delimiter', ','),
            enclosure: (string) config('tables.export.csv_enclosure', '"'),
            escape: (string) config('tables.export.csv_escape', '\\'),
            bom: (bool) config('tables.export.csv_bom', true),
            chunkSize: (int) config('tables.export.chunk_size', 500),
            logChunks: (bool) config('tables.export.log_chunks', false),
            columns: $columns,
            format: $format,
        );

        return new StreamedResponse(
            function () use ($exportRequest, $source, $writer, $resourceClass): void {
                try {
                    $writer->open($exportRequest);
                    $writer->writeHeader(array_map(fn (Field $f) => $f->label, $exportRequest->columns));

                    $rowsInBatch = 0;
                    foreach ($source->stream($exportRequest->chunkSize) as $row) {
                        $line = [];
                        foreach ($exportRequest->columns as $field) {
                            $raw = data_get($row, $field->name);
                            $line[] = $field->exportValue($raw, $row);
                        }
                        $writer->writeRow($line);

                        $rowsInBatch++;
                        if ($rowsInBatch >= $exportRequest->chunkSize) {
                            $rowsInBatch = 0;
                            if (function_exists('flush')) {
                                @ob_flush();
                                @flush();
                            }
                        }
                    }

                    if (function_exists('flush')) {
                        @ob_flush();
                        @flush();
                    }
                } catch (Throwable $e) {
                    Log::error('tables.export.write_failed', [
                        'resource' => $resourceClass,
                        'filename' => $exportRequest->filename,
                        'format' => $exportRequest->format,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                } finally {
                    $writer->close();
                }
            },
            200,
            [
                'Content-Type' => $writer->contentType(),
                'Content-Disposition' => 'attachment; filename="'.$exportRequest->filename.'"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    private function resolveFormat(Request $request, string $resourceClass): string
    {
        $default = (string) config('tables.export.default_format', 'csv');
        $requested = (string) $request->query('format', $default);
        if ($requested === '') {
            $requested = $default;
        }

        if (! $this->writers->has($requested)) {
            Log::error('tables.export.unknown_format', [
                'resource' => $resourceClass,
                'requested' => $requested,
                'fallback' => $default,
            ]);
            $requested = $default;
        }

        if (! $this->writers->has($requested)) {
            // default itself is missing — last-resort csv (always registered).
            $requested = 'csv';
        }

        return $requested;
    }
}
