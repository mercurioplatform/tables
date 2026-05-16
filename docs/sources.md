# Source drivers — mercurioplatform/tables

> Source-абстракция `Mercurio\Tables\Source\Source` отвязывает pipeline пакета
> от Eloquent. Каждый Resource выбирает свой источник данных через
> `ListResource::source(): ?Source`. Pipeline (search / sort / chip-filters /
> Query Builder / saved views / pagination / export / mutate-stack) одинаково
> работает поверх любого Source-драйвера; разница — только в Capabilities.

## Контракт

`Mercurio\Tables\Source\Source` — интерфейс с обязательными методами:
`capabilities()`, `withQuery(Query): static`, `count()`, `page()`, `stream()`,
`find()`, `findMany()`, `update()`, `probe()`. Полная сигнатура — в
[`api.md`](api.md) («Advanced: Source contract»).

`Mercurio\Tables\Source\Capabilities` — VO с булевыми флагами `filter / sort /
search / count / cursor / mutate / stream`. UI и engine используют их для
корректной деградации: `mutate = false` прячет bulk / row-actions с writer'ом /
inline cell-edit / undo; `count = false` переключает пагинатор в cursor-режим;
`sort = false` — снимает click-handler с `<th>`; `search = false` — прячет
search-input; `stream = false` — прячет export-кнопку.

`Mercurio\Tables\Source\Query` — neutral VO с полями `search`,
`searchableColumns`, `conditions`, `qbRoot`, `sortField`, `sortDirection`,
`savedViewKey`. Source-драйвер сам транслирует Query в свой подъязык
(LIKE / fulltext / API params / in-memory predicate).

`Mercurio\Tables\Source\Page` — унифицированный результат пагинации с двумя
режимами: offset (с делегатом `LengthAwarePaginator` для рендера) и cursor
(prev/next cursors без `total`).

## Драйверы

### EloquentSource

Базовый драйвер пакета. Capabilities: `filter / sort / search / count / mutate /
stream = true`, `cursor = false`. Создаётся автоматически через
`ListResource::resolveSource()` из legacy `query(): ?Builder` (с deprecation-shim)
или явно из `source()`. Подробности — в [`api.md`](api.md).

### ArraySource

In-memory драйвер поверх `Illuminate\Support\Collection`.

**Use-cases**:

- справочники из `config/` (страны, валюты, типы документов);
- hard-coded settings / lookup-таблицы;
- demo / тест-стенды для пакета и host'а;
- небольшие in-memory таблицы, которые не оправдывают создание модели и
  миграции.

**Не использовать**: production-выборки > 10 000 строк — все операции линейные
(`filter` / `sortBy` / `first` — O(N) на проход; `find()` пишет WARN
`tables.array_source.find.linear_scan` при `count() > 10 000`).

**Capabilities по умолчанию**:

| Capability | Value | Замечание |
|---|---|---|
| `filter` | `true` | chip-фильтры + `?qb=` через `BuiltinFilterEvaluator` / `AtomEvaluator`. |
| `sort` | `true` | `Collection::sortBy(callable, SORT_NATURAL | SORT_FLAG_CASE)`. |
| `search` | `true` | `mb_stripos` по `Resource::searchable()`. |
| `count` | `true` | `Collection::count()`. |
| `cursor` | `false` | Offset-only; `page()` строит делегат `LengthAwarePaginator` вручную. |
| `mutate` | `false` | Read-only. `update()` бросает `LogicException`. UI прячет bulk / row-actions / cell-edit / undo. |
| `stream` | `true` | `Collection::chunk()` → `yield`. |

Переопределяются через ctor:

```php
new ArraySource(
    rows: collect([...]),
    caps: new Capabilities(sort: false, ...),  // например, отключить sort для семантически-упорядоченного списка
    primaryKey: 'code',
    resource: $this,                            // optional — позволяет видеть Field-map для WARN-ов
);
```

**Пример Resource'а на ArraySource**:

```php
use Illuminate\Support\Facades\Lang;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\ArraySource;
use Mercurio\Tables\Source\Source;
use Mercurio\Tables\View\SavedView;

final class DocumentTypesResource extends ListResource
{
    public function key(): string
    {
        return 'document-types';
    }

    public function source(): ?Source
    {
        return new ArraySource(
            collect(config('document_types')),
            primaryKey: 'code',
            resource: $this,
        );
    }

    public function fields(): array
    {
        return [
            Field::text('code', Lang::get('app.fields.code')),
            Field::text('label', Lang::get('app.fields.label'))
                ->searchable()->sortable(),
            Field::text('group', Lang::get('app.fields.group'))
                ->filterable()->sortable(),
        ];
    }

    public function searchable(): array
    {
        return ['code', 'label', 'group'];
    }

    public function savedViews(): array
    {
        return [
            SavedView::all(Lang::get('app.views.all')),
            SavedView::conditions('legal', Lang::get('app.views.legal'), [
                new FilterCondition('group', Operator::Eq, 'legal'),
            ]),
        ];
    }
}
```

#### Row-форматы

`RowValueExtractor::extract($row, $field)` поддерживает:

- `array<string, mixed>` — обычный ассоциативный массив (`$row['code']`).
- `Eloquent\Model` — через `getAttribute()` (включая accessor'ы и casts).
- `Arrayable` — `toArray()` + ключевой доступ.
- `ArrayAccess` — `isset($row[$f]) ? $row[$f] : null`.
- `object` — public props (`$row->code`).
- dotted path (`order.customer.email`) — рекурсивный спуск с null-safe
  прерыванием на первом отсутствующем сегменте.

#### Ограничения

1. **Read-only.** `Capabilities::mutate = false`. Прямой вызов `update()`
   бросает `LogicException`. Контроллеры Phase 2 уже возвращают 422 при
   `mutate = false` — UI bulk / row-actions / cell-edit / undo скрыты.
2. **Sort по реляционным полям.** `Collection::sortBy` через
   `RowValueExtractor` работает по dotted-path, но это требует, чтобы вложенные
   данные **уже были в row** (например, eager-loaded relation на
   `Eloquent\Model` или явный nested array). Lazy-load по реляции даст N+1
   и не отсортируется детерминированно.
3. **Search dotted-path.** Аналогично — substring-match по `relation.column`
   работает, только если nested-данные в row на момент `withQuery()`.
4. **LIKE-семантика.** `Contains` / `StartsWith` / `EndsWith` (+ `Not*`-пары)
   сравнивают **буквальный substring** через `mb_stripos`. SQL-метасимволы
   (`%`, `_`) в expected-value трактуются буквально. `BuiltinFilterApplier`
   (SQL-режим) экранирует их через `escapeLike()` — практический эффект тот же,
   но edge-case'ы с wildcard-стилем wildcard'ов в built-in операторах
   изначально не поддерживаются.
5. **`find()` — линейный scan.** При `count() > 10 000` пишется WARN
   `tables.array_source.find.linear_scan`. Future-optimization (вне scope):
   ленивая индексация по `primaryKey`.
6. **`SavedView::scope(string)` и `SavedView::query(Closure(Builder))`** —
   Eloquent-only. На ArraySource обе формы пропускаются с WARN
   `tables.array_source.saved_view_scope_unsupported`. Source-agnostic
   альтернативы — [`SavedView::conditions(array<FilterCondition>)`](#savedview-conditions)
   и [`SavedView::sourceClosure(Closure(Source): Source)`](#savedview-sourceclosure).
7. **`SavedView::countWith(Closure)`** — Eloquent-only escape-хатч. На
   ArraySource пропускается с WARN
   `tables.saved_view.count_callback_unsupported_on_non_eloquent`.
8. **Field-aware filter-customizations.** `Field::applyFilter` /
   `Field::filterUsing(Closure)` / `Field::filterScope(string)` принимают
   `Eloquent\Builder` — Closure'ы Builder'а на in-memory row применить нельзя.
   На ArraySource все chip-фильтры и `?qb=` атомы проходят через
   `BuiltinFilterEvaluator` напрямую; при detection кастомизации пишется
   WARN `tables.array_source.field_filter_customization_skipped` (один раз
   на поле).

#### Saved views

##### SavedView::conditions

Полностью поддерживается. `FilterPipeline` сливает `view->conditions`
с user chip-фильтрами в `Query.conditions` ПЕРЕД `Source::withQuery()` —
ArraySource применяет их единообразно через `BuiltinFilterEvaluator`.
Counts вычисляются через `$source->withQuery($svQuery)->count()`.

##### SavedView::sourceClosure

Полностью поддерживается. `TableBuilder::build()` применяет
`Closure(Source): Source` ПОСЛЕ `Source::withQuery()` (immutable transform).
Closure получает `ArraySource` и должен вернуть новый ArraySource (например,
с предварительно отфильтрованной коллекцией):

```php
SavedView::sourceClosure('archived', Lang::get('app.views.archived'),
    fn (Source $s) => $s instanceof ArraySource
        ? new ArraySource($s->getRows()->where('archived', true))
        : $s,
);
```

Counts вычисляются через `withQuery` + applied closure → `count()`.

### Будущие драйверы (roadmap)

- **SqlSource** (Phase 4) — произвольный `DB::connection` (ClickHouse,
  BigQuery, read-replica), не Eloquent.
- **HttpSource** (Phase 5) — внешний API, opt-in cursor-режим, кэширование.
  Использует `AtomEvaluator` для client-side fallback по колонкам, которые
  API не понимает.
- **FileSource** (Phase 6) — CSV / JSONL / NDJSON с lazy reader. Использует
  `AtomEvaluator` и `BuiltinFilterEvaluator` для in-memory фильтрации.
