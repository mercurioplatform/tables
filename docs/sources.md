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

### SqlSource

Драйвер поверх произвольного `DB::connection` через **inline-Model**:
SqlSource внутри сам создаёт голый `Eloquent\Model` (без relations / scopes /
observers / accessors) и собирает поверх него обычный `Eloquent\Builder`. За
счёт этого весь существующий applier-стек пакета (`BuiltinFilterApplier`,
`QueryBuilderApplier`, `FilterApplier`, `SavedViewCountsCalculator`,
`paginate(...)->withQueryString()`, `lazyById(...)`) переиспользуется
**без изменений сигнатур** — нет параллельного стека под голый
`Query\Builder`.

**Use-cases**:

- ClickHouse / BigQuery-через-bridge / read-replica / read-only пул — данные
  лежат в SQL-источнике, но писать `EloquentModel` ради admin-списка нет
  смысла (нет relations / observers / business-logic, которую модель
  оправдала бы).
- Unmanaged / legacy таблицы — таблица существует в БД, но в коде проекта
  для неё нет (и не нужно) Eloquent-модели.
- Внешний read-only connection — другой database / другой driver, чем
  default-connection приложения; SqlSource даёт admin-список без правок
  глобальной конфигурации Eloquent.

**Не использовать**, если:

- Для таблицы уже есть `EloquentModel` с relations / scopes / mutators /
  observers / accessors — берите `EloquentSource` (`ListResource::query()`
  через legacy-shim или явный `EloquentSource` в `source()`); inline-Model
  в SqlSource намеренно не подхватывает этот context, и вы потеряете
  observer-driven side-effects / accessor-форматирование / relation-aware
  фильтры.
- Нужны relation-aware search/filter (dotted `user.email`) — SqlSource не
  поддерживает (inline-Model не имеет relations); ждать Phase 4b /
  Phase 5+ или использовать `EloquentSource`.
- Нужен полноценный write-API с observer'ами / events / accessors —
  inline-Model такого не даёт; делайте `EloquentSource` поверх реальной
  модели.

**Capabilities по умолчанию**:

| Capability | Value | Замечание |
|---|---|---|
| `filter` | `true` | chip-фильтры + `?qb=` через `BuiltinFilterApplier` / `QueryBuilderApplier` поверх `Eloquent\Builder`. |
| `sort` | `true` | `orderBy(column, direction)` на builder'е inline-Model. |
| `search` | `true` | `LIKE %q%` через `orWhere(column, 'LIKE', …)` по `Resource::searchable()`. **Только single-column.** |
| `count` | `true` | `clone->toBase()->getCountForPagination()` (Eloquent overhead сброшен для COUNT). |
| `cursor` | `false` | Offset-only. `page()` строит `LengthAwarePaginator` через `paginate(...)->withQueryString()`. |
| `mutate` | `false` | Read-only. `update()` логирует `tables.source.sql.mutate_denied` и бросает `LogicException`. UI прячет bulk / row-actions / cell-edit / undo. |
| `stream` | `true` | `lazyById($chunkSize)` поверх builder'а inline-Model. Memory O(chunkSize). |

**Пример Resource'а на SqlSource**:

```php
use Mercurio\Tables\Field\MoneyField;
use Mercurio\Tables\Field\StatusField;
use Mercurio\Tables\Field\TextField;
use Mercurio\Tables\Filter\FilterCondition;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\Source;
use Mercurio\Tables\Source\SqlSource;
use Mercurio\Tables\View\SavedView;

final class ClickhouseEventsResource extends ListResource
{
    public function key(): string
    {
        return 'analytics.events';
    }

    public function source(): ?Source
    {
        return SqlSource::for(
            table: 'events',
            connection: 'clickhouse',
            primaryKey: 'id',
            resource: $this,
        );
    }

    public function fields(): array
    {
        return [
            TextField::make('id', '#')->sortable(),
            TextField::make('kind', 'Тип')->sortable()->filterable([Operator::Eq, Operator::In]),
            StatusField::make('source', 'Источник')
                ->kinds(['web' => 'primary', 'app' => 'success', 'api' => 'info'])
                ->filterable(),
            MoneyField::make('revenue', 'Выручка')
                ->sortable()
                ->filterable([Operator::Between, Operator::Gt]),
        ];
    }

    public function searchable(): array
    {
        // ВАЖНО: single-column только. Никаких `user.email` — у inline-Model нет relations.
        return ['kind', 'session_id'];
    }

    public function savedViews(): array
    {
        return [
            SavedView::all('Все'),
            SavedView::conditions('web-only', 'Web', [
                new FilterCondition('source', Operator::Eq, 'web'),
            ]),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['id', 'desc'];
    }
}
```

#### Override capabilities

Если в host'е поверх SqlSource построен write-API (он сам отвечает за
валидацию / observer-less update / транзакции), включите `mutate = true`
явно через четвёртый аргумент `for()`:

```php
return SqlSource::for(
    table: 'events',
    connection: 'clickhouse',
    primaryKey: 'id',
    capabilities: new Capabilities(
        filter: true, sort: true, search: true, count: true,
        cursor: false, mutate: true, stream: true,
    ),
    resource: $this,
);
```

Тогда `update($id, $changes)` вместо `LogicException` выполнит
`whereKey($id)->update($changes)` (raw SQL UPDATE) и вернёт свежую строку
через `whereKey($id)->first()`. **Observer'ы / accessors / model-events не
вызываются** — inline-Model их не имеет; это контракт SqlSource, не баг.

> ⚠️ **Транзакции — на стороне host'а.** В отличие от `EloquentSource`,
> `SqlSource::update()` **не** оборачивает запись и последующий re-fetch в
> `DB::transaction(...)`: host лучше знает, какие именно операции должны
> идти атомарно (часто write идёт батчем рядом с другой бизнес-логикой —
> аудит-лог, queue-job, исходящий webhook). Если атомарность строки нужна,
> оберните `update()` явно:
>
> ```php
> use Illuminate\Support\Facades\DB;
>
> DB::connection('clickhouse')->transaction(function () use ($source, $id, $changes) {
>     return $source->update($id, $changes);
> });
> ```
>
> Для connection'ов без полноценной транзакционной семантики (ClickHouse,
> read-replicas с особыми ограничениями) ответственность за идемпотентность /
> retry-safety тоже остаётся на host'е.

#### Ограничения

1. **Read-only по умолчанию.** `Capabilities::mutate = false`. Прямой вызов
   `update()` бросает `LogicException` после WARN
   `tables.source.sql.mutate_denied`. Это намеренный разрыв с
   `EloquentSource` (там паттерн `return null`): SqlSource, как и
   `ArraySource`, считает write-mutate **opt-in только через явный
   `Capabilities(mutate: true)`** — read-replica / ClickHouse / unmanaged
   table не должны принимать `update()` через UI без явного решения host'а.
2. **Single-column search.** `LIKE %q%` применяется через
   `orWhere(column, 'LIKE', …)` без относительных подзапросов. Dotted-path
   (`user.email`) пропускается с WARN
   `tables.source.sql.search_dotted_unsupported` — inline-Model не имеет
   relations, и `orWhereHas('user', …)` некорректен.
3. **`probe(): mixed` всегда `null`.** Type-based authz пакетный не
   работает: host должен реализовать `Field::canSee` / `RowAction::canRun`
   вручную либо через policy-методы в Resource. `EloquentSource::probe()`
   возвращает empty `Model::newInstance()` (для `Gate::allows(...)`-проверок);
   у SqlSource такого class-target'а нет — `null` — final-fallback на
   «показать все actions».
4. **`SavedView::scope` задисейблен.** Обе формы (`scope(string $modelScopeName)`
   и `query(Closure(Builder<Model>))`) пропускаются с WARN
   `tables.source.sql.saved_view_scope_unsupported`. Scope-функции
   ожидают конкретную модель / relation / local-scope, inline-Model такого
   контекста не даёт. Используйте source-agnostic альтернативы:
   `SavedView::conditions(array<FilterCondition>)` (сливаются в
   `Query.conditions` до `withQuery`) и `SavedView::sourceClosure(Closure(Source): Source)`
   (применяется TableBuilder после `withQuery`).
5. **`findMany([…])` не сохраняет порядок IN-листа.** Возвращаемые строки
   идут в SQL-порядке выборки, не в порядке `$ids`. Без явного
   `FIELD(id, …)` ordering (driver-specific — `MySQL`/`PostgreSQL`/`SQLite`
   разные) восстановить порядок нельзя средствами универсального драйвера.
   Если порядок важен — полагайтесь на `defaultSort()` поля.
6. **Update без observer'ов / accessors / events.** При включённом
   `mutate = true` запись идёт через `Builder::update($changes)` — raw SQL
   UPDATE, минуя `Model::save()`. Это особенность inline-Model'а, а не
   баг: SqlSource намеренно не подхватывает observer-context конкретной
   модели приложения.
7. **Field-aware filter-customizations** (`Field::applyFilter`,
   `Field::filterUsing(Closure)`, `Field::filterScope(string)`) работают —
   они оперируют `Eloquent\Builder` и колоночными именами, не зависят от
   relations / scopes inline-Model'а. Тот же путь, что в `EloquentSource`.

#### Decision tree

Какой Source-драйвер взять:

1. **Есть `EloquentModel` для строк таблицы?**
   - Да → **`EloquentSource`** (через `ListResource::query()` legacy-shim
     или явный `source()`). Получаете relations / scopes / observers /
     accessors / mutate=true по default.
   - Нет → шаг 2.
2. **Данные in-memory (массив / `Collection` / справочник из `config/`)?**
   - Да → **`ArraySource`**. Read-only, никакой БД, full pipeline на
     `BuiltinFilterEvaluator` / `AtomEvaluator`.
   - Нет → шаг 3.
3. **Данные в SQL-источнике (любая БД через Laravel `DB::connection`)?**
   - Да → **`SqlSource`**. Inline-Model + read-only by default; mutate
     включается явно через `Capabilities(mutate: true)`.
   - Нет → шаг 4.
4. **Внешний API / файл / другой источник?**
   - Внешний API → **`HttpSource`** (cursor-pagination + Laravel Cache +
     per-field operator whitelist; read-only by design).
   - Файл (CSV / JSONL / NDJSON) → ждать **`FileSource`** (Phase 6+).

### HttpSource

Драйвер поверх произвольного внешнего HTTP API через декларативный
**fetch-closure**: host пишет одну функцию
`Closure(Query $q, ?string $cursor): array{rows, nextCursor, prevCursor, total}`,
а драйвер сам делает capabilities-gating, кэширование, пишет Log-каналы,
собирает {@see Page} в правильном режиме и фильтрует операторы по
per-field whitelist. Pipeline пакета (filter / sort / search / SavedView /
chip-фильтры) работает поверх HttpSource без правок.

**Use-cases**:

- **Shopify Admin API** — orders / products / customers / inventory (cursor-only
  API, total не возвращается; идеально ложится на `count=false, cursor=true`).
- **Stripe API** — payments / charges / subscriptions / invoices (cursor-pagination,
  per-resource рейт-лимит → caching критичен).
- **GitHub REST API** — issues / PRs / repositories (cursor-pagination + ETag).
- **Внутренние REST / gRPC bridge-сервисы** — read-only admin-список поверх
  данных, которые лежат в другом микросервисе и доступны только через API.

**Не использовать**, если:

- Данные лежат в SQL / `EloquentModel` — берите `EloquentSource` (полноценный
  CRUD) или `SqlSource` (read-only без модели);
- Данные in-memory (массив / `Collection` / справочник из `config/`) — берите
  `ArraySource`, иначе вы добавляете лишний серверный запрос на каждое
  открытие админ-списка;
- Нужен полноценный write-API (`update($id, $changes)`) — HttpSource **hard-denies**
  mutate (`Capabilities::mutate=true` не предусмотрено); host пишет в API
  через свой service-layer, не через `Source::update()`.

**Capabilities по умолчанию**:

| Capability | Value | Замечание |
|---|---|---|
| `filter` | `true` | chip-фильтры; host транслирует `Query::$conditions` в HTTP-параметры в собственном fetch-closure. `?qb=` AST задисейблен (см. ограничения). |
| `sort` | `true` | host транслирует `Query::$sortField` / `$sortDirection` в HTTP-параметры. |
| `search` | `true` | host транслирует `Query::$search` / `$searchableColumns` в HTTP-параметры. |
| `count` | **`false`** | Cursor primary. Host может явно передать `Capabilities(count: true)` для offset-режима, тогда fetch обязан возвращать `total` в payload'е. |
| `cursor` | **`true`** | Default. Page рендерит «← / →» через `nextCursor` / `prevCursor`. |
| `mutate` | **`false`** (hard) | Read-only by design. `update()` логирует `tables.source.http.mutate_denied` и бросает `LogicException` всегда. **`Capabilities(mutate: true)` не поддерживается** — это контракт HttpSource, не настройка. |
| `stream` | `true` | Stream обязан использовать cursor: при `capabilities.cursor=false` yield только первой страницы + WARN. |

**Пример Resource'а на HttpSource** (Shopify orders mock):

```php
use Mercurio\Tables\Field\StatusField;
use Mercurio\Tables\Field\TextField;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\HttpSource;
use Mercurio\Tables\Source\Query;
use Mercurio\Tables\Source\Source;

final class ShopifyOrdersResource extends ListResource
{
    public function key(): string
    {
        return 'shopify.orders';
    }

    public function source(): ?Source
    {
        return HttpSource::for(
            fetch: function (Query $q, ?string $cursor): array {
                $response = app('shopify.client')->get('/orders.json', [
                    'limit' => 50,
                    'page_info' => $cursor,
                    'status' => $this->extractStatusFilter($q),
                    'created_at_min' => $this->extractCreatedAtMin($q),
                ]);

                return [
                    'rows' => $response['orders'],
                    'nextCursor' => $response['page_info']['next'] ?? null,
                    'prevCursor' => $response['page_info']['prev'] ?? null,
                    'total' => null, // Shopify cursor API не возвращает total
                ];
            },
            resource: $this,
            operatorWhitelist: [
                // Shopify API понимает только =/>=/<= для created_at
                'created_at' => [Operator::Eq, Operator::Gte, Operator::Lte],
                // `status` НЕ в whitelist → keep all operators (поле без entry = no constraint)
            ],
            cacheTtlSeconds: 60,
            primaryKey: 'id',
        );
    }

    public function fields(): array
    {
        return [
            TextField::make('id', '#')->sortable(),
            TextField::make('email', 'Email')->filterable([Operator::Eq, Operator::Contains]),
            StatusField::make('financial_status', 'Оплата')
                ->kinds(['paid' => 'success', 'pending' => 'warning', 'refunded' => 'danger'])
                ->filterable([Operator::Eq, Operator::In]),
        ];
    }

    public function searchable(): array
    {
        return ['email', 'name'];
    }
}
```

#### Caching

HttpSource кэширует страницы через Laravel `Cache::remember`. Включается
передачей `cacheTtlSeconds` (в секундах) в `HttpSource::for()`. При
`cacheTtlSeconds === null` или `=== 0` — кэш bypass, всегда live fetch.

- **Ключ кэша**: `{cachePrefix или 'tables.http'}.{resource_key|'anonymous'}.{sha1(json_serialized_query + cursor)}`.
  Query сериализуется детерминированно: explicit list полей
  (`search`, `searchableColumns` отсортированные, `conditions` нормализованные
  в tuples `[field, operator, value]` с `usort`, `sortField`,
  `sortDirection`, `savedViewKey`), затем `json_encode` с
  `JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`.
  Это устойчиво к PHP-версиям, opcache, порядку property-инициализации
  (в отличие от `serialize($query)` PHP-native).
- **Cursor** входит в ключ отдельным компонентом — каждая страница
  кэшируется индивидуально.
- **Invalidation** — на стороне host'а. HttpSource не отслеживает
  write-through (mutate выключен by design). При обновлении данных в API
  host вызывает `Cache::forget(...)` сам или ждёт TTL.
- **Thundering herd** — для v2 простой `Cache::remember` без `Cache::lock`;
  при cache-miss и high-concurrent traffic несколько запросов могут
  одновременно стрелять в API. Lock через `Cache::lock(...)` — на следующую
  фазу.

Observability: каналы `tables.source.http.cache.hit` / `cache.miss` /
`fetch.hit` / `fetch.miss` — `Log::debug`.

#### Operator whitelist

Per-field operator constraint. Host передаёт
`operatorWhitelist: array<field, list<Operator>>`. Семантика:

- **Поле НЕ в whitelist** → keep all operators (нет ограничения для этого
  поля; API понимает любые операторы по нему). Если whitelist вообще `null` —
  no-op для всех условий.
- **Поле в whitelist** и оператор условия ∈ whitelist[field] → keep.
- **Поле в whitelist** и оператор условия ∉ whitelist[field] → **skip
  условия** + `Log::warning('tables.source.http.operator_not_allowed', [...])`.

Пример: Shopify API понимает `=` / `>=` / `<=` для `created_at` (нет
between), но все операторы для `status` (`=` / `in` / `!=`). Host передаёт:

```php
operatorWhitelist: [
    'created_at' => [Operator::Eq, Operator::Gte, Operator::Lte],
    // `status` НЕ в whitelist — API принимает любые операторы по нему,
    // chip-фильтры по status уйдут в fetch как есть.
],
```

`between created_at [a, b]` будет skip'нуто с WARN. Если такая семантика
нужна, host конвертирует `between` → `>=` AND `<=` в собственном
fetch-closure, прежде чем строить HTTP-запрос.

Whitelist применяется к `Query::$conditions` (chip-фильтры); `qbRoot`
(QB-tree AST из `?qb=`) — задисейблен полностью (см. ограничения).

#### Override capabilities

Если host'у нужен offset-mode (API возвращает total), включите
`count = true` явно:

```php
return HttpSource::for(
    fetch: $fetch,
    capabilities: new Capabilities(
        filter: true, sort: true, search: true, count: true,
        cursor: false, mutate: false, stream: false,
    ),
    resource: $this,
);
```

`page()` тогда передаёт fetch'у `$cursor = "offset:{$page}"` (соглашение),
fetch обязан вернуть `total`. **Известное ограничение**: без
`LengthAwarePaginator`-делегата `Page::previousPageUrl()` /
`nextPageUrl()` в offset-режиме вернут `null` — Blade-пагинатор
`tables::pagination-bs5` рендерит «← / →» вместо номеров страниц.

`Capabilities::mutate = true` **не поддерживается** — `update()` бросает
`LogicException` всегда, независимо от Capabilities.

#### Ограничения

1. **Read-only by design.** `Capabilities::mutate = false` — hard-denied,
   без override. `update($id, $changes)` логирует WARN
   `tables.source.http.mutate_denied` и бросает `LogicException`. Host
   пишет в API через свой service-layer (отдельный controller / action /
   command), не через `Source::update()`. UI прячет bulk / row-edit /
   cell-edit / undo через Capabilities-gating из Phase 2.
2. **Cursor primary; offset-mode частично функционален.** В offset-режиме
   `Page::previousPageUrl()` / `nextPageUrl()` возвращают `null` без
   `LengthAwarePaginator`-делегата. Blade-пагинатор рендерит «← / →» вместо
   номеров страниц. Cursor-режим работает полностью (Page строит URL'ы
   через `?cursor=...&page=null`).
3. **`qbRoot` (`?qb=` AST) задисейблен.** HttpSource не транслирует Query
   Builder AST в HTTP-параметры. При `$query->qbRoot !== null` —
   `Log::warning('tables.source.http.qb_unsupported', ...)` + `qbRoot`
   зануляется перед fetch'ом. Используйте chip-фильтры
   (`Query::$conditions`); если QB-tree всё-таки нужен, host обрабатывает
   его в собственном fetch-closure (HttpSource AST не пробрасывает).
4. **`find($id)` fallback через chip-фильтр.** Без явного
   `findOne`-closure HttpSource строит `Query` c
   `conditions = [new FilterCondition($primaryKey, Eq, $id)]` и зовёт
   `withQuery(...)->page(1, 1)` — один HTTP-запрос. **Edge-case**: если
   whitelist задан и primaryKey в нём И `Operator::Eq` НЕ разрешён для
   primaryKey — fallback недоступен, `find()` возвращает `null` с
   WARN `tables.source.http.find_fallback_blocked_by_whitelist`. Передайте
   `findOne`-closure для O(1)-пути.
5. **`findMany($ids)` bulk-fallback через `In`-условие.** Без явного
   `findMany`-closure HttpSource строит `Query` с
   `conditions = [new FilterCondition($primaryKey, In, $ids)]` и зовёт
   `withQuery(...)->page(1, count($ids))` — **один HTTP-запрос вместо N**
   (критично для bulk-actions с 20+ ids). Если `Operator::In` не в
   whitelist для primaryKey — degrade на N×`find()` loop с **один** WARN
   `tables.source.http.find_many.linear_fallback`. Передайте
   `findMany`-closure для O(1)-пути.
6. **`findMany([...])` не сохраняет порядок входных ids.** Возвращаемые
   строки идут в порядке выборки API, не в порядке `$ids` — consistent с
   `SqlSource`. Известное отклонение от `Source::findMany()` PHPDoc-контракта
   («в исходном порядке id»). Host реиндексирует сам, если порядок важен
   (`array_combine($ids, $rows)` после `array_map(...)`).
7. **`probe(): null`.** Type-based authz пакетный не работает — нет
   Eloquent-модели как target'а для `Gate::allows(...)` пакетной проверки.
   Host реализует `Field::canSee` / `RowAction::canRun` вручную или через
   policy-методы в Resource.
8. **`SavedView::scope` задисейблен.** Обе формы (`scope(string $modelScopeName)`
   и `query(Closure(Builder))`) — Eloquent-only, пропускаются с WARN
   `tables.source.http.saved_view_scope_unsupported`. Используйте
   source-agnostic альтернативы: `SavedView::conditions(array<FilterCondition>)`
   (сливаются в `Query.conditions` до `withQuery`) и
   `SavedView::sourceClosure(Closure(Source): Source)` (применяется
   TableBuilder после `withQuery`).
9. **Field-aware filter-customizations не применяются.**
   `Field::applyFilter` / `filterUsing(Closure(Builder))` / `filterScope(string)`
   оперируют `Eloquent\Builder` — у HttpSource такого нет. Операторы идут
   в payload через built-in semantics + per-field operator whitelist.
   Кастомизация — на уровне fetch-closure host'а, не Field'ов.
10. **Per-field operator whitelist — кастомизация на уровне Source-драйвера,
    не Field'ов.** В отличие от `Field::filterable([Operator::Eq, ...])`
    (UI-уровень — какие чипы показывать), `operatorWhitelist` (Source-уровень —
    какие операторы драйвер пропустит в fetch). Это разные концепции:
    первый ограничивает что пользователь может выбрать, второй —
    что драйвер пошлёт в API, даже если Field разрешил больше.

### Будущие драйверы (roadmap)

- **FileSource** (Phase 6) — CSV / JSONL / NDJSON с lazy reader. Использует
  `AtomEvaluator` и `BuiltinFilterEvaluator` для in-memory фильтрации.


