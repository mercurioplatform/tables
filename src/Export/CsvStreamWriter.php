<?php

namespace Mercurio\Tables\Export;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Mercurio\Tables\Field\Field;
use RuntimeException;

final class CsvStreamWriter
{
    public static function stream(
        ExportRequest $req,
        Builder $query,
        ?Closure $logger = null,
    ): void {
        $handle = fopen('php://output', 'w');
        if ($handle === false) {
            throw new RuntimeException('Cannot open php://output for export.');
        }

        try {
            if ($req->bom) {
                fwrite($handle, "\xEF\xBB\xBF");
            }

            $headers = array_map(
                fn (Field $f) => self::escapeCell($f->label),
                $req->columns,
            );
            fputcsv($handle, $headers, $req->delimiter, $req->enclosure, $req->escape);

            $totalRows = 0;
            $startedAt = microtime(true);

            $query->chunkById($req->chunkSize, function ($rows) use ($handle, $req, &$totalRows, $logger): void {
                foreach ($rows as $row) {
                    $line = [];
                    foreach ($req->columns as $field) {
                        $raw = $row->{$field->name} ?? null;
                        $value = $field->exportValue($raw, $row);
                        $line[] = self::escapeCell($value);
                    }
                    fputcsv($handle, $line, $req->delimiter, $req->enclosure, $req->escape);
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
            if (is_resource($handle)) {
                fclose($handle);
            }
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
