<?php

namespace Mercurio\Tables\Export;

/**
 * Streaming writer contract for the table export pipeline.
 *
 * `ExportHandler` calls `open()` once, then `writeHeader()` once with the
 * column labels, then `writeRow()` per data row, then `close()` exactly
 * once (in try/finally) — regardless of success or failure.
 *
 * Implementations should write directly to `php://output` (or buffer to
 * a temp file and `readfile()` in `close()` when streaming is not
 * possible, e.g. XLSX which requires a finalised central directory).
 *
 * Header values (`writeHeader`) and row values (`writeRow`) come as
 * `array<int, string>` of already exported strings (Field::exportValue
 * has been applied upstream).
 */
interface ExportWriter
{
    public function open(ExportRequest $request): void;

    /**
     * @param  array<int, string>  $labels
     */
    public function writeHeader(array $labels): void;

    /**
     * @param  array<int, string>  $values
     */
    public function writeRow(array $values): void;

    public function close(): void;

    public function contentType(): string;

    public function fileExtension(): string;
}
