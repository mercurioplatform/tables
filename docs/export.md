# Export — multi-format streaming

Engine стримит экспорт текущего состояния списка (search + view + chips + qb + sort + visible columns) через единый pipeline `ExportHandler` → `ExportWriter`. Формат выбирается query-параметром `?format=`.

## Встроенные форматы

| Формат  | Класс                  | Content-Type                                                                | Расширение | Включён по умолчанию |
|---------|------------------------|------------------------------------------------------------------------------|------------|----------------------|
| `csv`   | `CsvStreamWriter`      | `text/csv; charset=UTF-8`                                                    | `.csv`     | Да (всегда зарегистрирован) |
| `json`  | `JsonStreamWriter`     | `application/json; charset=UTF-8`                                            | `.json`    | Да (`config/tables.php`) |
| `xlsx`  | `XlsxStreamWriter`     | `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`          | `.xlsx`    | Нет (требует `composer require openspout/openspout`) |

CSV — default. Меняется через `config('tables.export.default_format')`.

## URL-параметр

```
/admin/products/export?format=csv
/admin/products/export?format=json
/admin/products/export?format=xlsx
```

Неизвестный `format` → `Log::error('tables.export.unknown_format', ...)` + автоматический fallback на `default_format`. Никаких 4xx.

## Включение XLSX

XLSX-зависимость не в `require` пакета — host сам ставит `openspout/openspout`:

```bash
composer require openspout/openspout
```

И раскомментирует в `config/tables.php`:

```php
'export' => [
    'writers' => [
        'json' => \Mercurio\Tables\Export\JsonStreamWriter::class,
        'xlsx' => \Mercurio\Tables\Export\XlsxStreamWriter::class,
    ],
],
```

Без зависимости `XlsxStreamWriter::open()` бросает `RuntimeException` с инструкцией.

Особенность XLSX-стриминга: формат требует финализации central directory, поэтому writer пишет во временный файл и `readfile()` в `close()`. Streaming-чанки по-прежнему работают (memory-footprint низкий), но первый байт ответа уходит позже, чем у CSV/JSON.

## Custom writer

Минимальный pipeline:

```php
namespace App\Tables\Export;

use Mercurio\Tables\Export\ExportRequest;
use Mercurio\Tables\Export\ExportWriter;

final class TsvStreamWriter implements ExportWriter
{
    /** @var resource|null */ private $handle = null;

    public function open(ExportRequest $request): void
    {
        $h = fopen('php://output', 'w');
        if ($h === false) throw new \RuntimeException('fopen failed');
        $this->handle = $h;
    }

    public function writeHeader(array $labels): void
    {
        fwrite($this->handle, implode("\t", $labels)."\n");
    }

    public function writeRow(array $values): void
    {
        fwrite($this->handle, implode("\t", $values)."\n");
    }

    public function close(): void
    {
        if (is_resource($this->handle)) fclose($this->handle);
        $this->handle = null;
    }

    public function contentType(): string { return 'text/tab-separated-values; charset=UTF-8'; }
    public function fileExtension(): string { return 'tsv'; }
}
```

Регистрация в `config/tables.php`:

```php
'export' => [
    'writers' => [
        'json' => \Mercurio\Tables\Export\JsonStreamWriter::class,
        'tsv'  => \App\Tables\Export\TsvStreamWriter::class,
    ],
    'formats' => [
        'csv' => 'tables::export.format_csv',
        'json' => 'tables::export.format_json',
        'tsv' => 'TSV',
    ],
],
```

Готово — `?format=tsv` стримится через `TsvStreamWriter`, dropdown показывает «TSV».

## Dropdown UI

`<x-tables.export-button>` рендерит:

- **single-format** — обычная кнопка-ссылка с прямым URL экспорта;
- **multi-format** — Bootstrap 5 split-button + dropdown с вариантами.

Порядок dropdown определяется массивом `config('tables.export.formats')`. Форматы, отсутствующие в `ExportWriterRegistry`, пропускаются молча.

## Что отвечает `ExportHandler`

1. `Auth::guard($resource->effectiveGuard())` → 401 если null user.
2. `Gate::check(config('tables.export.ability'))` → 403 если запрещено.
3. `total > sync_limit` → опциональный `ExportJobDispatcher` (202 queued) или 413.
4. `?format=` → `ExportWriterRegistry::has()` → fallback (`Log::error('tables.export.unknown_format', ...)`).
5. `Content-Type` / `Content-Disposition` берутся из `$writer->contentType()` / `$writer->fileExtension()`.
6. Стриминг через `chunkById($chunkSize, ...)`. Любая `\Throwable` в pipeline → `Log::error('tables.export.write_failed', ['filename', 'format', 'error'])` + rethrow.
7. `$writer->close()` гарантированно вызывается в `finally`.

## Логирование

`Log::error` для unrecoverable-ошибок, `Log::warning` для denied/no-columns/too-large. События:

- `tables.export.unknown_format` — неизвестный `?format=`, fallback к default (`Log::error`).
- `tables.export.write_failed` — исключение в writer'е (после rethrow клиент получит 500) (`Log::error`).
- `tables.export.dispatch_failed` — falling-back async dispatcher бросил (`Log::error`).
- `tables.export.forbidden`/`no_columns`/`too_large` — `Log::warning`.
