<?php

namespace Mercurio\Tables\Source\Support;

use Generator;
use Illuminate\Support\Facades\Log;
use JsonException;
use LogicException;
use Mercurio\Tables\Source\FileSource;

/**
 * JSONL / NDJSON reader для {@see FileSource}.
 *
 * Семантика:
 * - `fopen` + `fgets` per-line.
 * - Пустые строки (после `trim`) — skip + DEBUG (не считаются `invalid_json_line`).
 * - Каждая непустая строка декодируется через `json_decode($line, true, ...)`.
 * - Режимы parse error'ов:
 *   - `$strictJson = true` (default) — `JSON_THROW_ON_ERROR` + завёрнут в
 *     `LogicException` с номером строки. Идея — fail-fast для известно-валидных
 *     audit-логов / трастового источника.
 *   - `$strictJson = false` — WARN `tables.source.file.read.invalid_json_line`
 *     + skip row. Идея — толерантный режим для дампов внешнего происхождения
 *     с возможным «битым хвостом» (consumer вычистит данные по WARN'ам).
 * - Каждый успешно-декодированный entry проверяется на `is_array($decoded)`:
 *   primitives / objects (после `assoc: true` ожидается array) → WARN
 *   `tables.source.file.read.invalid_json_line` + skip.
 * - `read.open` / `read.eof` DEBUG-каналы (открытие / закрытие через finally,
 *   как в {@see CsvFileReader}).
 *
 * Encoding: только UTF-8 (по спецификации JSON / JSONL). BOM в JSONL встречается
 * редко, но `json_decode` его не любит — поэтому стрипаем с первой строки, если
 * она содержит BOM.
 *
 * @internal
 */
final class JsonlFileReader implements FileReader
{
    public function __construct(
        private readonly bool $strictJson = true,
    ) {}

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function read(string $path): Generator
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new LogicException("JsonlFileReader: failed to open file for reading: {$path}");
        }

        $rowsYielded = 0;
        $lineNumber = 0;
        $isFirstLine = true;

        try {
            while (($raw = fgets($handle)) !== false) {
                $lineNumber++;

                if ($isFirstLine) {
                    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
                    $isFirstLine = false;
                }

                $line = trim($raw);
                if ($line === '') {
                    continue;
                }

                try {
                    $flags = $this->strictJson ? JSON_THROW_ON_ERROR : 0;
                    $decoded = json_decode($line, true, 512, $flags);
                } catch (JsonException $e) {
                    throw new LogicException(
                        "JsonlFileReader: invalid JSON on line {$lineNumber} of ".basename($path).' (strict mode): '.$e->getMessage(),
                        previous: $e,
                    );
                }

                if (! is_array($decoded)) {
                    Log::warning('tables.source.file.read.invalid_json_line', [
                        'path' => basename($path),
                        'line' => $lineNumber,
                        'reason' => 'json_decode returned non-array (likely primitive or null)',
                    ]);

                    continue;
                }

                if ($this->isList($decoded)) {
                    Log::warning('tables.source.file.read.invalid_json_line', [
                        'path' => basename($path),
                        'line' => $lineNumber,
                        'reason' => 'expected JSON object (associative), got list',
                    ]);

                    continue;
                }

                /** @var array<string, mixed> $decoded */
                yield $decoded;
                $rowsYielded++;
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * @param  array<int|string, mixed>  $arr
     */
    private function isList(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return array_is_list($arr);
    }
}
