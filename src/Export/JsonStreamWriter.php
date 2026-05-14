<?php

namespace Mercurio\Tables\Export;

use RuntimeException;

/**
 * Streams a JSON array of row objects directly to `php://output`.
 *
 * Output shape:
 *   [
 *     {"<header_1>": "<value>", "<header_2>": "<value>", ...},
 *     ...
 *   ]
 *
 * Header labels (from `writeHeader`) become the object keys for each row.
 * UTF-8 without BOM; whitespace-formatted for human-readability.
 */
final class JsonStreamWriter implements ExportWriter
{
    /** @var resource|null */
    private $handle = null;

    /** @var array<int, string> */
    private array $headers = [];

    private bool $firstRow = true;

    public function open(ExportRequest $request): void
    {
        $handle = fopen('php://output', 'w');
        if ($handle === false) {
            throw new RuntimeException('Cannot open php://output for export.');
        }
        $this->handle = $handle;
        $this->headers = [];
        $this->firstRow = true;
        fwrite($this->handle, "[\n");
    }

    public function writeHeader(array $labels): void
    {
        if ($this->handle === null) {
            throw new RuntimeException('JsonStreamWriter::writeHeader called before open().');
        }
        $this->headers = array_values(array_map('strval', $labels));
    }

    public function writeRow(array $values): void
    {
        if ($this->handle === null) {
            throw new RuntimeException('JsonStreamWriter::writeRow called before open().');
        }

        $obj = [];
        $cnt = count($this->headers);
        for ($i = 0; $i < $cnt; $i++) {
            $obj[$this->headers[$i]] = (string) ($values[$i] ?? '');
        }

        $prefix = $this->firstRow ? '  ' : ",\n  ";
        $this->firstRow = false;
        fwrite($this->handle, $prefix.json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fwrite($this->handle, "\n]\n");
            fclose($this->handle);
        }
        $this->handle = null;
    }

    public function contentType(): string
    {
        return 'application/json; charset=UTF-8';
    }

    public function fileExtension(): string
    {
        return 'json';
    }
}
