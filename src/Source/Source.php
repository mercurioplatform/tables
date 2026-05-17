<?php

namespace Mercurio\Tables\Source;

use Generator;

/**
 * Контракт источника данных для ResourceListing.
 *
 * Реализации:
 * - {@see EloquentSource} (адаптер поверх Eloquent\Builder; mutate=true по default);
 * - {@see ArraySource} (in-memory Collection / iterable; read-only by default);
 * - {@see SqlSource} (произвольный DB::connection через inline-Model; read-only by default);
 * - {@see HttpSource} (внешний HTTP API через декларативный fetch-closure;
 *   cursor primary, кэширование, read-only by design).
 *
 * Будущие фазы: FileSource (CSV/JSONL/NDJSON с lazy reader, Phase 6+).
 */
interface Source
{
    /**
     * Декларация возможностей источника (filter/sort/search/count/cursor/mutate/stream).
     * UI и engine используют это для корректной деградации.
     */
    public function capabilities(): Capabilities;

    /**
     * Immutable apply — возвращает новый Source с применённым Query.
     *
     * Реализация: клонирует source, применяет $query к внутреннему состоянию
     * (Eloquent\Builder, Collection, HTTP-params, …) и возвращает копию.
     */
    public function withQuery(Query $query): static;

    /**
     * Общее число записей (null = unknown — cursor sources без count, HTTP).
     */
    public function count(): ?int;

    /**
     * Страница строк (offset или cursor режим). См. {@see Page::isCursor()}.
     */
    public function page(int $page, int $perPage): Page;

    /**
     * Стрим всех отфильтрованных/отсортированных строк для экспорта.
     *
     * Для Eloquent — внутри chunkById. Для in-memory — yield from collection.
     * Для HTTP/File — постранично/построчно с lazy reader.
     *
     * @return Generator<int, mixed>
     */
    public function stream(int $chunkSize): Generator;

    /**
     * Резолв одной строки по primary key / external id. Null если не найдено.
     */
    public function find(int|string $id): mixed;

    /**
     * Резолв строк по массиву PK. Возвращает iterable в исходном порядке id.
     *
     * @param  array<int, int|string>  $ids
     * @return iterable<int, mixed>
     */
    public function findMany(array $ids): iterable;

    /**
     * Mutate-API — только при capabilities()->mutate === true.
     * Возвращает обновлённую строку (или null если не найдена).
     *
     * @param  array<string, mixed>  $changes
     */
    public function update(int|string $id, array $changes): mixed;

    /**
     * Type-based authz probe (пустой instance модели / null).
     *
     * Источник может вернуть null — server-side всё равно валидирует,
     * UI fallback'ится на «показать все actions».
     */
    public function probe(): mixed;
}
