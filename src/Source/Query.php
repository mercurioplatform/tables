<?php

namespace Mercurio\Tables\Source;

use Mercurio\Tables\Filter\FilterCondition;
use Mercurio\Tables\Filter\Qb\AtomCondition;
use Mercurio\Tables\Filter\Qb\AtomGroup;

/**
 * Neutral описание состояния запроса для Source-драйвера.
 *
 * Не зависит от Eloquent / SQL / HTTP — Source сам транслирует в свой
 * подъязык (LIKE / fulltext / API params / regex / in-memory predicate).
 *
 * Mutable VO: TableBuilder заполняет поля по мере прохождения pipeline'а
 * (FilterPipeline → SortResolver → Source::withQuery).
 */
final class Query
{
    public ?string $search = null;

    /** @var array<int, string> Список колонок, по которым ищем (семантический). */
    public array $searchableColumns = [];

    /** @var array<int, FilterCondition> Chip-фильтры. */
    public array $conditions = [];

    public AtomCondition|AtomGroup|null $qbRoot = null;

    public ?string $sortField = null;

    /** 'asc' | 'desc' */
    public string $sortDirection = 'asc';

    /** Ключ активного SavedView (Source-драйвер может применить его scope). */
    public ?string $savedViewKey = null;
}
