<?php

namespace Mercurio\Tables\Api;

use Mercurio\Tables\Source\Query;

/**
 * Результат разбора Request'а в {@see ApiQueryParser}.
 *
 * Содержит:
 * - `$query` — neutral `Query` VO с применённым search/sort/conditions/savedViewKey;
 * - `$includes` — массив запрошенных блоков envelope'а (data/page/summary/savedViews/capabilities/schema);
 * - `$fields` — sparse-fieldsets (whitelist + порядок). Если в URL не указан — равен `ApiConfig::getAllowFields()`;
 * - `$format` — base/fallback режим сериализации значений ({@see FormatMode}). Применяется к полю,
 *   если для него нет per-field override в `$perFieldFormats`.
 * - `$perPage` — размер страницы (валидированный);
 * - `$page` — номер страницы (1-based);
 * - `$perFieldFormats` — overrides per field (`field-name => FormatMode`). Если для имени
 *   поля есть запись — рендер использует её, иначе fallback на `$format`. Семантика:
 *   `$rendererMode = $perFieldFormats[$fieldName] ?? $format;`.
 *
 * Cursor-токен на уровне Parser'а не вводим — для cursor-primary Source'ов
 * прозрачно работает `$source->page($page, $perPage)` (cursor резолвится
 * самим Source через свой внутренний state).
 */
final class ParsedApiQuery
{
    /**
     * @param  array<int, string>  $includes
     * @param  array<int, string>  $fields
     * @param  array<string, FormatMode>  $perFieldFormats
     */
    public function __construct(
        public readonly Query $query,
        public readonly array $includes,
        public readonly array $fields,
        public readonly FormatMode $format,
        public readonly int $perPage,
        public readonly int $page,
        public readonly array $perFieldFormats = [],
    ) {}

    public function wantsInclude(string $block): bool
    {
        return in_array($block, $this->includes, true);
    }
}
