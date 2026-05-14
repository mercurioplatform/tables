<?php

namespace Mercurio\Tables\Export;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;

/**
 * XLSX export writer based on `openspout/openspout`.
 *
 * Streaming XLSX cannot write directly to `php://output` because the
 * archive's central directory is finalised at close time. We buffer to a
 * temp file inside `open()…writeRow()…` and `readfile()` the result in
 * `close()`.
 *
 * Opt-in: the package itself does not `require` openspout — host must
 * `composer require openspout/openspout`. Missing the class triggers a
 * `RuntimeException` with the install hint.
 */
final class XlsxStreamWriter implements ExportWriter
{
    private ?XlsxWriter $writer = null;

    private ?string $tempPath = null;

    public function open(ExportRequest $request): void
    {
        if (! class_exists(XlsxWriter::class)) {
            throw new RuntimeException(
                'openspout/openspout is required for XLSX export. '
                .'Install it in the host project: composer require openspout/openspout'
            );
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'tables-xlsx-');
        if ($tempPath === false) {
            throw new RuntimeException('Cannot create temp file for XLSX export.');
        }

        $writer = new XlsxWriter;
        $writer->openToFile($tempPath);

        $this->writer = $writer;
        $this->tempPath = $tempPath;
    }

    public function writeHeader(array $labels): void
    {
        if ($this->writer === null) {
            throw new RuntimeException('XlsxStreamWriter::writeHeader called before open().');
        }
        $this->writer->addRow(Row::fromValues(array_map('strval', $labels)));
    }

    public function writeRow(array $values): void
    {
        if ($this->writer === null) {
            throw new RuntimeException('XlsxStreamWriter::writeRow called before open().');
        }
        $this->writer->addRow(Row::fromValues(array_map('strval', $values)));
    }

    public function close(): void
    {
        if ($this->writer !== null) {
            $this->writer->close();
            $this->writer = null;
        }

        if ($this->tempPath !== null && is_file($this->tempPath)) {
            readfile($this->tempPath);
            @unlink($this->tempPath);
        }
        $this->tempPath = null;
    }

    public function contentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function fileExtension(): string
    {
        return 'xlsx';
    }
}
