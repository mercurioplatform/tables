<?php

namespace Mercurio\Tables\Source\Support;

use Generator;
use Mercurio\Tables\Source\FileSource;

/**
 * Внутренний контракт построчного чтения файла для {@see FileSource}.
 *
 * Yield семантика: одна row — один ассоциативный массив `array<string, mixed>`.
 * Reader сам открывает / закрывает file handle, проверяет читаемость, decode'ит
 * формат-специфичные тонкости (BOM в CSV, JSON parse errors в JSONL).
 *
 * Все реализации — `final`, без public API за пределами пакета (interface живёт
 * в `Source/Support/` и НЕ экспортируется через фасадные `use`-импорты пакета).
 * FileSource в ctor сам конструирует подходящий reader из `$format` —
 * host НЕ инжектит reader-instance.
 *
 * @internal
 */
interface FileReader
{
    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function read(string $path): Generator;
}
