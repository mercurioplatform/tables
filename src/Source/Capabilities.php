<?php

namespace Mercurio\Tables\Source;

/**
 * Декларация возможностей Source-драйвера.
 *
 * UI и engine используют флаги для корректной деградации:
 * - mutate=false → bulk/row/cell-edit/undo возвращают 405; UI скрывает кнопки;
 * - count=false → пагинатор переключается в cursor-режим (total = null);
 * - cursor=true → пагинатор рендерит «← / →» без номеров страниц;
 * - sort=false → колонки без click-handler'а / aria-sort;
 * - search=false → search-input скрывается;
 * - stream=false → export-кнопка скрывается / 422;
 * - qbTree=false → JSON API возвращает 422 CAPABILITY_UNSUPPORTED при попытке
 *   передать полноценное QB-дерево (OR/NOT/вложенность) через POST body или
 *   ?qb=<base64>. Плоский ?filter[..] по-прежнему работает (см. capability `filter`).
 */
final class Capabilities
{
    public function __construct(
        public bool $filter = true,
        public bool $sort = true,
        public bool $search = true,
        public bool $count = true,
        public bool $cursor = false,
        public bool $mutate = false,
        public bool $stream = true,
        public bool $qbTree = false,
    ) {}

    /**
     * Сериализация флагов для блока `capabilities` JSON-envelope'а
     * (см. JsonRenderer / docs/json-api.md).
     *
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
