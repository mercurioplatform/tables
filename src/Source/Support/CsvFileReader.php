<?php

namespace Mercurio\Tables\Source\Support;

use Generator;
use Illuminate\Support\Facades\Log;
use LogicException;
use Mercurio\Tables\Source\FileSource;

/**
 * CSV-reader для {@see FileSource}.
 *
 * Семантика:
 * - `fgetcsv` для парсинга (`\n` / `\r\n` / `\r` line endings обрабатываются автоматически в PHP 8.3).
 * - Если `$columns === null` — header читается из первой строки; BOM `\xEF\xBB\xBF`
 *   стрипается с первой колонки header'а.
 * - Если `$columns !== null` — header в файле НЕ ожидается; все строки трактуются
 *   как данные с заранее объявленными именами колонок.
 * - Yield-ит associative arrays через `array_combine($columns, $row)`. Если число
 *   значений в строке не совпадает с числом колонок — WARN
 *   `tables.source.file.read.column_count_mismatch` + skip row.
 * - На каждый `read()` пишет один `tables.source.file.read.open` DEBUG (с info о
 *   формате/колонках) и один `tables.source.file.read.eof` DEBUG (в `finally`,
 *   с числом выданных rows) — даже при раннем break'е consumer'а (Generator
 *   получает `__destruct()` → `finally` срабатывает).
 * - File handle всегда закрывается через `try { yield ... } finally { fclose ... }`.
 *
 * Encoding: только UTF-8. BOM стрипается с первой ячейки header'а. Не-UTF-8
 * кодировки host обязан конвертировать `iconv`-ом ДО прокидывания пути.
 *
 * @internal
 */
final class CsvFileReader implements FileReader
{
    /**
     * @param  array<int, string>|null  $columns  Если null — header читается из первой строки.
     */
    public function __construct(
        private readonly string $delimiter = ',',
        private readonly string $enclosure = '"',
        private readonly string $escape = '\\',
        private readonly ?array $columns = null,
    ) {}

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function read(string $path): Generator
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new LogicException("CsvFileReader: failed to open file for reading: {$path}");
        }

        $columns = $this->columns;
        $rowsYielded = 0;
        $lineNumber = 0;

        try {
            if ($columns === null) {
                $headerRow = fgetcsv(
                    $handle,
                    0,
                    $this->delimiter,
                    $this->enclosure,
                    $this->escape,
                );
                $lineNumber++;

                if ($headerRow === false || $headerRow === [null]) {
                    return;
                }

                if (isset($headerRow[0]) && is_string($headerRow[0])) {
                    $headerRow[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headerRow[0]) ?? $headerRow[0];
                }

                $columns = array_map(static fn ($v): string => is_string($v) ? $v : (string) $v, $headerRow);
            }

            $expectedCount = count($columns);

            while (($row = fgetcsv($handle, 0, $this->delimiter, $this->enclosure, $this->escape)) !== false) {
                $lineNumber++;

                if ($row === [null]) {
                    continue;
                }

                $actualCount = count($row);
                if ($actualCount !== $expectedCount) {
                    Log::warning('tables.source.file.read.column_count_mismatch', [
                        'path' => basename($path),
                        'line' => $lineNumber,
                        'expected' => $expectedCount,
                        'actual' => $actualCount,
                    ]);

                    continue;
                }

                /** @var array<string, mixed> $combined */
                $combined = array_combine($columns, $row);
                yield $combined;
                $rowsYielded++;
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }
}
