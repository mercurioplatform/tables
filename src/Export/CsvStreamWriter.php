<?php

namespace Mercurio\Tables\Export;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Mercurio\Tables\Field\Field;
use RuntimeException;

final class CsvStreamWriter implements ExportWriter
{
    /** @var resource|null */
    private $handle = null;

    private string $delimiter = ',';

    private string $enclosure = '"';

    private string $escape = '\\';

    public function open(ExportRequest $request): void
    {
        $handle = fopen('php://output', 'w');
        if ($handle === false) {
            throw new RuntimeException('Cannot open php://output for export.');
        }

        $this->handle = $handle;
        $this->delimiter = $request->delimiter;
        $this->enclosure = $request->enclosure;
        $this->escape = $request->escape;

        if ($request->bom) {
            fwrite($this->handle, "\xEF\xBB\xBF");
        }
    }

    public function writeHeader(array $labels): void
    {
        if ($this->handle === null) {
            throw new RuntimeException('CsvStreamWriter::writeHeader called before open().');
        }
        fputcsv(
            $this->handle,
            array_map(fn ($l) => self::escapeCell((string) $l), $labels),
            $this->delimiter,
            $this->enclosure,
            $this->escape,
        );
    }

    public function writeRow(array $values): void
    {
        if ($this->handle === null) {
            throw new RuntimeException('CsvStreamWriter::writeRow called before open().');
        }
        fputcsv(
            $this->handle,
            array_map(fn ($v) => self::escapeCell((string) $v), $values),
            $this->delimiter,
            $this->enclosure,
            $this->escape,
        );
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }

    public function contentType(): string
    {
        return 'text/csv; charset=UTF-8';
    }

    public function fileExtension(): string
    {
        return 'csv';
    }

    /**
     * @deprecated Use `ExportHandler` which drives `ExportWriter` instances.
     *             Kept for backwards-compatibility with hosts that called
     *             `CsvStreamWriter::stream()` directly.
     */
    public static function stream(
        ExportRequest $req,
        Builder $query,
        ?Closure $logger = null,
    ): void {
        $writer = new self;
        $writer->open($req);

        try {
            $writer->writeHeader(array_map(fn (Field $f) => $f->label, $req->columns));

            $totalRows = 0;
            $startedAt = microtime(true);

            $query->chunkById($req->chunkSize, function ($rows) use ($writer, $req, &$totalRows, $logger): void {
                foreach ($rows as $row) {
                    $line = [];
                    foreach ($req->columns as $field) {
                        $raw = $row->{$field->name} ?? null;
                        $line[] = $field->exportValue($raw, $row);
                    }
                    $writer->writeRow($line);
                    $totalRows++;
                }
                if ($req->logChunks && $logger !== null) {
                    $logger('chunk', ['rows_so_far' => $totalRows]);
                }
                if (function_exists('flush')) {
                    @ob_flush();
                    @flush();
                }
            });

            if ($logger !== null) {
                $logger('complete', [
                    'rows' => $totalRows,
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ]);
            }
        } finally {
            $writer->close();
        }
    }

    private static function escapeCell(string $value): string
    {
        if ($value === '') {
            return $value;
        }
        $first = $value[0];
        if (in_array($first, ['=', '+', '-', '@'], true) || $first === "\t" || $first === "\r") {
            return "'".$value;
        }

        return $value;
    }
}
