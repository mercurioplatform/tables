# JSON API architecture — internal reference

> **Audience:** контрибьюторы пакета `mercurioplatform/tables`.
> **Scope:** карта серверного pipeline'а JSON-API (`Source → ApiQueryParser → JsonRenderer → response`),
> контракт `Source`-абстракции, перечень публичной поверхности `src/Api/`, правила opt-in регистрации
> маршрутов, контракт error-envelope'а и точки расширения для новых драйверов / операций.
>
> **Не для host-разработчиков** — host-side руководства лежат рядом: `docs/api.md` (Blade-pages + DSL),
> `docs/json-api.md` (как пользоваться JSON-эндпоинтами), `docs/sources.md` (как выбрать и настроить
> Source-драйвер).

---

## 1. Architecture map

JSON API в `tables/` стоит на одной и той же `Source`-абстракции, что и Blade-рендер: оба
получают строки данных через `Source::page()` / `Source::stream()`, отличаются только тем, как
рендерят результат. JSON-ветка имеет три single-action invokable-контроллера (по одному на
семантически отдельную операцию: read, mutate, schema-introspection), четыре HTTP-маршрута и
один общий error-envelope контракт.

### 1.1. End-to-end pipeline

```
HTTP Request
   │
   ▼
┌──────────────────────────────────────────────────────────────────────┐
│ Route::tablesApi('orders', OrdersResource::class)                    │
│   GET  /{uri}         → JsonApiController        (.index)            │
│   POST /{uri}         → JsonApiController        (.query)            │
│   GET  /{uri}/schema  → JsonApiSchemaController  (.schema)           │
│   POST /{uri}/mutate  → JsonApiMutateController  (.mutate)           │
└──────────────────────────────────────────────────────────────────────┘
   │
   ▼  (1) route()->defaults['resource'] → resolve class
   │      ├── not a string / class_exists=false        → LogicException (500)
   │      └── not a subclass of ListResource           → 404 RESOURCE_NOT_FOUND
   │
   ▼  (2) $resource->resolveApiConfig() → ApiConfig VO
   │
   ▼  (3) Hard-gates (mutate-only):
   │      ├── ! $config->getAllowMutations()           → 403 MUTATIONS_DISABLED
   │      └── coarse Gate (mutateAbility, optional)    → 403 POLICY_DENIED
   │
   ▼  (4) Body / query parsing
   │      ┌────────────────────────────────┐        ┌────────────────────────────────┐
   │      │ ApiQueryParser::parse()        │        │ MutateBodyParser::parse()      │
   │      │   GET ?filter[..] / ?sort /    │        │   POST { op, id?, field?,      │
   │      │   ?qb=<base64> / ?savedView /  │        │           ids[]?, payload? }   │
   │      │   ?include / ?fields /         │        │   per-op validation +           │
   │      │   ?format[..] / ?page          │        │   maxBulkIds / maxPayloadBytes  │
   │      │   POST { qb, sort, filter, … } │        │                                │
   │      └──────────────┬─────────────────┘        └──────────────┬─────────────────┘
   │                     │  throws ApiValidationException → 422
   │                     ▼
   │             ParsedApiQuery                                     ParsedMutate
   │
   ▼  (5) $resource->resolveSource()               (mutate: also calls resolveSource)
   │      └── any Throwable                              → 500 SOURCE_ERROR
   │
   ▼  (6) Read-path: $source->withQuery($parsed->query)
   │      Mutate-path: $source->capabilities()->mutate=false → 422 CAPABILITY_UNSUPPORTED
   │
   ▼  (7) Capability-gating (read-path only — assertCapabilities()):
   │      ├── qbRoot ≠ null  & ! caps.qbTree           → 422 CAPABILITY_UNSUPPORTED
   │      ├── conditions ≠ [] & ! caps.filter          → 422 CAPABILITY_UNSUPPORTED
   │      ├── sortField ≠ null & ! caps.sort           → 422 CAPABILITY_UNSUPPORTED
   │      ├── search ≠ null   & ! caps.search          → 422 CAPABILITY_UNSUPPORTED
   │      └── savedViewKey ≠ null & ! caps.filter      → 422 CAPABILITY_UNSUPPORTED
   │
   ▼  (8) Data fetch / mutation
   │      Read-path:   $source->page($page, $perPage)            → Page VO
   │                     any Throwable                            → 500 SOURCE_ERROR
   │      Mutate-path: dispatchOp() →
   │                     CellUpdateHandler::applyAsArray()
   │                   | RowActionHandler::applyAsResult()
   │                   | BulkActionHandler::applyAsResult()
   │                     ApiValidationException                   → 422
   │                     Laravel ValidationException              → 422 VALIDATION_FAILED
   │                     any Throwable                            → 500 MUTATION_FAILED
   │      Schema-path: SchemaBuilder::build($resource, $config)   → array
   │
   ▼  (9) Response rendering
   │      Read-path:   JsonRenderer::render($page, $source, $resource, $parsed, $config)
   │      Mutate-path: MutateRenderer::render($result, $parsed, $resource)
   │      Schema-path: { schema: SchemaBuilder::build(...) }
   │
   ▼ (10) JsonResponse
          200 (read / schema / sync mutate)
          202 (bulk mutate, $result->progressId !== null)
```

### 1.2. Exception edges

Pipeline различает два класса ошибок:

| Класс | Источник | Поведение |
|---|---|---|
| **`ApiValidationException`** (`src/Api/Exceptions/`) | Парсеры (`ApiQueryParser`, `MutateBodyParser`), `assertCapabilities()`, handler'ы | Пойман в контроллере → `ApiErrorResponse::fromException($e)` → envelope `{error: {code, message, details}}` со статусом из `ApiErrorCode::httpStatus()`. Не утекает наружу. |
| **`Throwable`** (любое другое) | `resolveSource()`, `Source::page()`, `dispatchOp()` handler'ов | Логируется через `Log::error('tables.api.*', […])`, конвертируется в `SOURCE_ERROR` (500) или `MUTATION_FAILED` (500). Контроллеры держат явные `try/catch` вокруг каждой Source-операции — никакой централизованной exception-обёртки в middleware нет. |
| **`LogicException`** | Контракт-нарушения маршрута (нет `defaults['resource']`, класс не `ListResource`, unknown `dispatchOp`) | **Не** оборачивается в envelope, поднимается до Laravel exception handler'а. Семантика — bug в host-конфигурации, не пользовательская ошибка. |

`MutateBodyParser::parse()` сам бросает `ApiValidationException` с подходящим `ApiErrorCode`
(`MALFORMED_QUERY` / `VALIDATION_FAILED` / `CAPABILITY_UNSUPPORTED` / `ACTION_NOT_FOUND`),
поэтому контроллеру достаточно одного `catch (ApiValidationException)` на парсер-этапе.

### 1.3. `resolveSource()` bridge

`ListResource::resolveSource(): Source` — единственная точка получения источника данных для всей
API-поверхности. Все три контроллера зовут её, и все три ожидают, что ресурс вернёт уже
сконфигурированный, готовый к `withQuery()` источник.

В v2 параллельно сохраняется deprecated-shim `ListResource::query(): Builder` — это
обратная совместимость для подклассов, которые ещё не мигрированы на `resolveSource()`.
Поведение моста:

- если ресурс переопределил `resolveSource()` — он возвращает Source-driver as-is;
- если ресурс переопределил только устаревший `query()` — `resolveSource()` оборачивает результат
  в `EloquentSource`-адаптер;
- если переопределены **оба** — `resolveSource()` побеждает.

Shim удалится в v3 (`Source` interface станет единственным контрактом данных).

### 1.4. `assertCapabilities()` controller-level gate

`JsonApiController::assertCapabilities(Source, ParsedApiQuery): void` (приватный метод) выполняется
**после** `withQuery()`. Это нужно, потому что Source-driver может вернуть **другой** capabilities-набор
после применения query (например, `FileSource` переключается в lazy-mode по `materializeUnderBytes` —
и зануляет `sort`/`count`/`qbTree`). Гейт обходит пять conditional проверок и при первой
несоответствии бросает `ApiValidationException(CapabilityUnsupported, …)`.

Mutate-контроллер не использует `assertCapabilities()` — у него только один capability-флаг
(`caps.mutate`) и одна точка проверки сразу после `resolveSource()`. Schema-контроллер не
обращается к Source-данным напрямую, поэтому capability-gating ему не требуется.


## 2. Source contract & capabilities

`Source` — единственный контракт данных для всей JSON-API поверхности (и для
Blade-рендера тоже). В пакете 5 драйверов плюс 6 internal-утилит в
`src/Source/Support/`. UI и engine используют 8 capability-флагов для
корректной деградации: скрыть кнопку, ответить 422 `CAPABILITY_UNSUPPORTED`,
переключить пагинатор в cursor-режим, и т. д.

### 2.1. `Source` interface

```php
namespace Mercurio\Tables\Source;

interface Source
{
    public function capabilities(): Capabilities;
    public function withQuery(Query $query): static;     // immutable apply
    public function count(): ?int;                       // null = unknown (cursor / lazy)
    public function page(int $page, int $perPage): Page;
    public function stream(int $chunkSize): Generator;   // export O(chunkSize)
    public function find(int|string $id): mixed;
    public function findMany(array $ids): iterable;
    public function update(int|string $id, array $changes): mixed;  // mutate-only
    public function probe(): mixed;                      // type-based authz probe
}
```

Семантика:

- **`capabilities()`** — `final` для каждого инстанса, но может зависеть от
  ctor-параметров (см. FileSource materialized/lazy).
- **`withQuery()`** — immutable apply: возвращает **новый** instance с
  применённым `Query`. Все driver'ы клонируют свой underlying state
  (Builder / Collection / HTTP-params) перед мутацией.
- **`count(): ?int`** — `null` допустим (cursor-paged API, lazy-file без
  материализации). UI в этом случае рендерит «← / →» без номеров страниц.
- **`stream()`** — обязательный exit для экспорта; memory O(chunkSize).
  HttpSource при `capabilities.cursor=false` yield-ит только первую страницу
  (anti-runaway), FileSource имеет hard cap 10M строк.
- **`update()`** — фактический mutate. Контроллер `JsonApiMutateController`
  гейтит вызов через `capabilities()->mutate` ДО `update()`, поэтому read-only
  драйверы (`Array/Sql/Http/File`) дополнительно бросают `LogicException` как
  защиту от программных обходов контроллера.
- **`probe(): mixed`** — пустой instance модели или `null`. Используется
  type-based authz UI; драйверы без Eloquent-модели возвращают `null`, host
  реализует `Field::canSee` / `RowAction::canRun` вручную.

### 2.2. `Query` VO

Mutable neutral-VO, который наполняется на pipeline-этапе и передаётся в
`Source::withQuery()`. Source-драйвер сам транслирует поля в свой подъязык
(SQL / LIKE / HTTP-params / in-memory predicate).

```php
final class Query
{
    public ?string $search = null;
    /** @var array<int, string> */
    public array $searchableColumns = [];
    /** @var array<int, FilterCondition> */
    public array $conditions = [];                       // chip-фильтры
    public AtomCondition|AtomGroup|null $qbRoot = null;  // Query Builder AST
    public ?string $sortField = null;
    public string $sortDirection = 'asc';                // 'asc' | 'desc'
    public ?string $savedViewKey = null;
}
```

Контракт mutate vs immutable: канонический сценарий — заполнить новый `Query`
и передать в `withQuery()`. `FileSource::withQuery()` клонирует входной
`Query` (`clone`) и работает с клоном; `HttpSource::withQuery()` мутирует
входной VO (известное расхождение, см. driver-doc). Все остальные драйверы
не модифицируют `$query.field*`.

### 2.3. `Page` VO

Унифицированный результат пагинации, замещающий `LengthAwarePaginator`.
Два режима:

- **offset** (`$total !== null`) — традиционная пагинация по номерам.
  EloquentSource всегда возвращает offset Page с приложенным `$delegate`
  (`LengthAwarePaginator`), на который форвардятся Blade-совместимые
  методы (`total()`, `hasPages()`, `links()`).
- **cursor** (`$total === null`) — opt-in для HTTP-/file-sources без count.
  Page сам строит prev/next URL'ы на основе `$prevCursor` / `$nextCursor`.

LengthAwarePaginator-совместимый shim (`hasPages`, `firstItem`, `lastItem`,
`previousPageUrl`, `nextPageUrl`, `links`) даёт обратную совместимость для
host-published Blade-шаблонов без правок.

### 2.4. `Capabilities` flags

8 boolean-флагов; сериализуются в JSON-envelope (`capabilities` block в
`JsonRenderer`). Контроллер `JsonApiController::assertCapabilities()` гейтит
read-path по `qbTree`/`filter`/`sort`/`search` + `savedViewKey→filter`.

| Флаг | UI/engine эффект при `false` | API эффект при `false` |
|---|---|---|
| `filter` | chip-фильтры скрываются | 422 `CAPABILITY_UNSUPPORTED` при `?filter[..]` / `savedViewKey` |
| `sort` | заголовки колонок без click-handler'а / aria-sort | 422 при `?sort` |
| `search` | search-input скрывается | 422 при `?search` |
| `count` | пагинатор без `total`; cursor-style «← / →» | response без `meta.total` (`pagination.total = null`) |
| `cursor` | (informational — связан с `count`) | offset-режим в response |
| `mutate` | bulk / row-actions с writer'ом / cell-edit / undo прячутся | 403 `MUTATIONS_DISABLED` / 422 при попытке `POST /mutate` |
| `stream` | export-кнопка скрывается | 422 при export-эндпоинте |
| `qbTree` | QB-builder UI скрыт | 422 при `?qb=…` / POST `{qb}` (плоский `?filter[..]` работает) |

### 2.5. Capabilities matrix (5 драйверов × 6 строк)

`FileSource` имеет два режима — materialized (файл целиком в Collection) и
lazy (построчный reader-generator); порог переключения — `materializeUnderBytes`
(default 5 MB, hard-cap 50 MB).

| Driver | `filter` | `sort` | `search` | `count` | `cursor` | `mutate` | `stream` | `qbTree` |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| `EloquentSource`              | ✓ | ✓ | ✓ | ✓ |   | ✓ | ✓ | ✓ |
| `ArraySource` (default)       | ✓ | ✓ | ✓ | ✓ |   |   | ✓ | ✓ |
| `SqlSource` (default)         | ✓ | ✓ | ✓ | ✓ |   |   | ✓ | ✓ |
| `HttpSource` (default)        | ✓ | ✓ | ✓ |   | ✓ |   | ✓ |   |
| `FileSource` — materialized   | ✓ | ✓ | ✓ | ✓ |   |   | ✓ | ✓ |
| `FileSource` — lazy           | ✓ |   | ✓ |   |   |   | ✓ |   |

Caveats:

- `ArraySource` и `SqlSource` принимают override через ctor — host может
  выключить `sort` (для семантически-упорядоченных коллекций) или включить
  `mutate=true` у SqlSource (на свою ответственность: inline `SqlSourceModel`
  не имеет observer'ов/accessors).
- `HttpSource` принимает override, но `mutate=true` hard-denied: `update()`
  всегда бросает `LogicException`.
- `FileSource` при override клампит lazy-несовместимые флаги (`sort`,
  `count`, `qbTree`) обратно к `false` с WARN'ом; `mutate=true` всегда
  клампится (read-only by design).

### 2.6. Driver-specific public surface

| Driver | Entry-point / factory | Дополнительный публичный API |
|---|---|---|
| `EloquentSource` | `new EloquentSource(Builder $b, ?ListResource $r = null)` | `getBuilder(): Builder` (`@internal`, deprecation-shim для legacy ExportHandler / host'ов) |
| `ArraySource` | `new ArraySource(Collection\|iterable $rows, ?Capabilities, string $primaryKey = 'id', ?ListResource)` | `getRows(): Collection` (`@internal`, для tests и `SavedViewCountsCalculator`); public-readonly `primaryKey` |
| `SqlSource` | `SqlSource::for(string $table, ?string $connection, string $primaryKey = 'id', ?Capabilities, ?ListResource)` | прямой ctor `(Builder $b, ?Capabilities, ?ListResource)` |
| `HttpSource` | `HttpSource::for(Closure $fetch, ?Capabilities, ?ListResource, ?Closure $findOne, ?Closure $findMany, ?array $operatorWhitelist, ?int $cacheTtlSeconds, ?string $cachePrefix, string $primaryKey = 'id')` | прямой ctor с теми же параметрами + `Query` + `cursor` |
| `FileSource` | `FileSource::for($path, ?$format, ...)`, `::csv($path, $delimiter, $enclosure, $escape, ?$columns, ...)`, `::jsonl($path, $strictJson, ...)`, `::ndjson($path, ...)` | `getRows(): Collection` (`@internal`, только materialized; lazy → `LogicException`); public-readonly props (`path`, `format`, `query`, `resource`, `primaryKey`, `materializeUnderBytes`, `delimiter`, `enclosure`, `escape`, `columns`, `strictJson`) |

### 2.7. `Source/Support/` (6 утилит)

Internal-кирпичики, на которых стоят in-memory и HTTP-драйверы:

| Файл | Назначение |
|---|---|
| `FileReader` (interface) | Внутренний контракт построчного чтения файла для `FileSource`. Yield-ит `array<string, mixed>` per row, сам открывает / закрывает handle. `@internal`, не экспортируется. |
| `CsvFileReader` | Реализация `FileReader` поверх `fgetcsv`: header detection / BOM strip / column-mismatch WARN. |
| `JsonlFileReader` | Реализация `FileReader` поверх `fopen+fgets`: per-line `json_decode`, `strictJson=true` → `JSON_THROW_ON_ERROR`, иначе WARN + skip. |
| `HttpFetchResult` | Internal DTO для нормализации payload'а `HttpSource::$fetch`-closure (`rows`, `nextCursor`, `prevCursor`, `total`). Мягкая валидация null-аблов, жёсткая на отсутствие `rows`. |
| `RowValueExtractor` | Извлечение значения row по field-имени для in-memory драйверов (`ArraySource`, `FileSource`). Поддержка: array, `Eloquent\Model` (через `getAttribute`), `Arrayable`, `ArrayAccess`, public-props объектов, dotted-path с null-safe прерыванием. |
| `SqlSourceModel` | Concrete inline-`Model` для `SqlSource::for()` — голый subclass без relations / scopes / observers / accessors. Нужен только чтобы получить рабочий `Builder` через `Model::newQuery()` поверх произвольной таблицы/connection. `@internal`. |

### 2.8. SavedView source-agnostic forms

`SavedView` поддерживает три формы фильтрации, которые могут сочетаться в
одной view; порядок применения детерминирован: `scope → conditions →
sourceClosure`.

| Форма | Сигнатура | Где применяется | Совместимость |
|---|---|---|---|
| **`scope`** (legacy) | `string $modelScope` или `Closure(Builder): void` | `EloquentSource::applySavedView()` | **EloquentSource-only**. На `Array/Sql/Http/File` — graceful-clamp: один WARN per-call (`tables.{driver}.saved_view_scope_unsupported`) + skip без 422. Другие формы той же view продолжают работать. |
| **`conditions`** (v2, source-agnostic) | `array<int, FilterCondition>` | `FilterPipeline` сливает с user chip-фильтрами в `Query.conditions` **до** `Source::withQuery()` | Все 5 драйверов: трактуются единообразно через built-in evaluator (`Array`, `File`) или Builder-applier (`Eloquent`, `Sql`) или fetch-params + per-field operator whitelist (`Http`). |
| **`sourceClosure`** (v2, source-agnostic) | `Closure(Source): Source` | `TableBuilder` применяет **после** `Source::withQuery()` в `build()` / `buildForExport()` | Все 5 драйверов. В counts unsupported (skip + WARN). |

Static factories:

```php
SavedView::all();                                        // empty
SavedView::scope('archived', 'Архив', 'archived');       // EloquentSource only
SavedView::query('paid', 'Paid', fn (Builder $q) => $q->where(...));  // EloquentSource only
SavedView::conditions('paid', 'Paid', [                  // source-agnostic
    new FilterCondition('status', Operator::Eq, 'paid'),
]);
SavedView::sourceClosure('all-ext', 'All', fn (Source $s) => $s);  // source-agnostic
```

Каналы WARN при попытке применить `scope` на non-Eloquent драйвере (с
указанием Source-agnostic альтернатив в reason):

- `tables.array_source.saved_view_scope_unsupported`
- `tables.source.sql.saved_view_scope_unsupported`
- `tables.source.http.saved_view_scope_unsupported`
- `tables.source.file.saved_view_scope_unsupported`


## 3. Public API surface (`src/Api/`)

Все классы JSON API-слоя живут в namespace `Mercurio\Tables\Api\*`. Они
делятся на четыре группы: парсеры (Request → VO), VO (parsed input),
рендеры (VO → array envelope) и контракт-классы (envelope/error/format).

### 3.1. Inventory (17 классов)

| Файл | Роль | Notes |
|---|---|---|
| `ApiConfig` | host-override VO с fluent withers (`make()` + 10 withers); whitelist полей / savedViews, mutate-hardening лимиты, default format / includes / pagination | Резолвится через `ListResource::resolveApiConfig()`. Sentinel-`null` для `allowFields`/`allowSavedViews` означает auto-resolve из `fieldsMemo()`/`savedViewsMemo()`. |
| `ApiErrorCode` | enum 10 cases + `httpStatus()` | `MALFORMED_QUERY=400`, `VALIDATION_FAILED=422`, `CAPABILITY_UNSUPPORTED=422`, `MUTATIONS_DISABLED=403`, `POLICY_DENIED=403`, `RESOURCE_NOT_FOUND=404`, `RECORD_NOT_FOUND=404`, `ACTION_NOT_FOUND=404`, `SOURCE_ERROR=500`, `MUTATION_FAILED=500`. |
| `ApiErrorResponse` | factory error-envelope'а + единая точка логирования API-ошибок (`Log::log(level=4xx?warning:5xx?error, 'tables.api.error', …)`) | `make($code, $message, $details)`, `capabilityUnsupported($capability)`, `fromException(ApiValidationException)`. |
| `ApiQueryParser` | Request → `ParsedApiQuery` (GET query + POST body) | Парсит `?include`, `?fields`, `?format`, `?per_page`, `?page`, `?sort`, `?q`, `?savedView`, `?filter[..]`, `?qb=<base64-json>` / `body.qb`. Public const `KNOWN_INCLUDES`. |
| `FormatMode` | enum `Raw\|Formatted\|Both` — режим сериализации значения поля | Парсинг через встроенные `FormatMode::tryFrom()` / `from()`; никаких обёрток сверху. |
| `JsonRenderer` | `Page` + `ApiConfig` + `ParsedApiQuery` → `array<string, mixed>` envelope | Блоки: `data`, `page`, `summary`, `savedViews`, `capabilities`, `schema` (опционально, по `include`). |
| `MutateBodyParser` | Request body (`POST /{uri}/mutate`) → `ParsedMutate` | Discriminator `op:"cell"\|"row"\|"bulk"`; per-op-валидация + `maxBulkIds` + `maxPayloadBytes`. Public const `KNOWN_MUTATE_INCLUDES`. |
| `MutateRenderer` | `MutateResult` → `array<string, mixed>` envelope (per-op shape: `cell`/`row`/`bulk`) | Контроллер `JsonApiMutateController` оборачивает в `JsonResponse` со статусом 200 (sync) или 202 (queued bulk). |
| `ParsedApiQuery` | immutable VO: `Query` + `includes[]` + `fields[]` + `FormatMode` + `perFieldFormats[]` + `perPage` + `page` | Wantsinclude-helper. |
| `ParsedMutate` | immutable readonly VO для распарсенного mutate-body | Per-op-поля заполняются по дискриминатору `$op`. |
| `SavedViewSerializer` | helper сериализации saved-view conditions (`{field, operator, value}`) | Один источник правды для `JsonRenderer::serializeSavedView()` и `SchemaBuilder`. |
| `SchemaBuilder` | сборщик self-describing блока `schema` | Два consumer'а: `?include=schema` через `JsonRenderer` + `GET /{uri}/schema` через `JsonApiSchemaController`. Whitelist через `ApiConfig::getAllowFields()`/`getAllowSavedViews()`. |
| `Exceptions/ApiValidationException` | `RuntimeException` с `public readonly ApiErrorCode $errorCode` и `public readonly array $details` | Парсеры / `assertCapabilities()` / mutate-handlers бросают; контроллеры ловят и конвертируют через `ApiErrorResponse::fromException()`. |
| `Mutate/MutateResult` | marker-interface для трёх VO ниже (PHP-аппроксимация sealed-classes) | Type-hint для `MutateRenderer::render(MutateResult)`. |
| `Mutate/CellMutateResult` | `final readonly` — `id`, `freshRow`, `undoLogId=null` (cell-edit не undoable) | Renderer envelope: `{data: {id, row}}`. |
| `Mutate/RowMutateResult` | `final readonly` — `id`, `ActionResult $actionResult`, опц. `undoLogId` | Renderer извлекает `affected`/`message`/`payload` из `ActionResult`. |
| `Mutate/BulkMutateResult` | `final readonly` — `affected`, `missing`, `denied`, `affectedIds`, опц. `progressId` (queued) | Два режима: sync (`progressId === null`) → 200; queued → 202 + counts=0. |

### 3.2. Host-only consumer surface

«Host-only» = публичный contract пакета, к которому консьюмер обращается
напрямую из своих контроллеров / Eloquent-моделей / job'ов; внутри пакета
тот же API зовут парсеры/контроллеры/рендеры.

`ApiConfig::make()` + withers — host задаёт API-поверхность на ресурсе:

```php
public function api(): ApiConfig
{
    return ApiConfig::make()
        ->allowFields(['id', 'number', 'status', 'total'])
        ->allowSavedViews(['paid', 'pending'])
        ->allowMutations(true)
        ->mutateAbility('orders.mutate')   // coarse Gate
        ->defaultFormat(FormatMode::Both)
        ->defaultIncludes(['data', 'page', 'capabilities'])
        ->defaultPerPage(25)
        ->maxPerPage(200)
        ->maxBulkIds(1000)
        ->maxPayloadBytes(65_536);
}
```

Withers — fluent immutable (`with()` под капотом возвращает новый instance);
getters — `getAllowFields(): ?array`, `getAllowMutations(): bool`,
`getMutateAbility(): ?string`, `getMaxPayloadBytes(): int` и т.д. (10 пар
wither + getter, симметричных полям ctor'а).

`ApiErrorResponse::*` — host может звать из кастомных контроллеров для
возврата envelope-совместимых ошибок:

```php
return ApiErrorResponse::make(
    ApiErrorCode::ValidationFailed,
    'Custom host-side validation',
    ['field' => 'foo'],
);
return ApiErrorResponse::capabilityUnsupported('export');
```

`FormatMode::tryFrom('raw'\|'formatted'\|'both')` / `FormatMode::from(...)` —
host использует в кастомных field-render-callback'ах для воспроизведения
семантики `?format=` / `?format[field]=`.

### 3.3. Public include constants

Два публичных whitelist'а — host видит их при построении кастомных
include'ов / документации:

- **`ApiQueryParser::KNOWN_INCLUDES`** =
  `['data', 'page', 'summary', 'savedViews', 'capabilities', 'schema']`
  — блоки envelope'а для read-эндпоинтов (GET `/{uri}` / POST `/{uri}` / GET `/{uri}/schema`).
  Неизвестные include'ы → WARN + skip (не fail).
- **`MutateBodyParser::KNOWN_MUTATE_INCLUDES`** = `['undoToken']`
  — блоки envelope'а для mutate-эндпоинта (POST `/{uri}/mutate`).
  Семантика та же: неизвестные → WARN + skip.

Константы публичны намеренно: host подключает их в собственных API-документах
и тестах вместо хардкода строк.


## 4. Routing & opt-in compliance

В пакете три route-макроса; каждый — это **explicit-opt-in** регистрация
своего набора маршрутов. Никаких implicit-публикаций «UI + API одной
строкой», никакого fluent-`->withApi()` поверх существующего макроса. Host
вызывает ровно тот макрос, который соответствует поверхности, которую он
хочет открыть.

### 4.1. Три макроса (booted в `TablesServiceProvider::boot()`)

```php
// UI-таблица (Blade + богатый pipeline: bulk/row actions, export, cell-edit,
// saved views, prefs, action log). Поверхность publish-publish ListResource.
Route::tablesPage('orders', OrdersResource::class)
    ->name('orders')
    ->middleware(['auth']);

// UI-таблица с явным контроллером (host-defined). Иногда нужно, когда
// контроллер кастомен и не Generic. Тот же набор маршрутов, что у tablesPage.
Route::tablesResource('orders', OrdersTablesController::class)
    ->name('orders')
    ->middleware(['auth']);

// JSON API-эндпоинт: четыре маршрута (index/query/schema/mutate).
Route::tablesApi('orders', OrdersResource::class)
    ->name('api.orders')
    ->middleware(['auth:api', 'throttle:60,1']);
```

### 4.2. Macro comparison

| Макрос | Назначение | Регистрируемые маршруты | Расширение |
|---|---|---|---|
| `tablesPage($path, ResourceClass)` | UI-страница поверх `GenericTablesController`. Probe `cellEditEnabled()` через DI-инстанс ресурса (boot-time). | `GET /{path}`, `POST /{path}/bulk-action`, `GET /{path}/options`, `POST /{path}/save-view`, `DELETE /{path}/user-views/{id}`, `POST /{path}/row-action/{id}/{action}` (+ `/form`, `/preview`), `GET /{path}/bulk-action/{action}/form` (+ `/preview`), `POST /{path}/prefs`, `DELETE /{path}/prefs`, `GET /{path}/export`, `PATCH /{path}/cells/{id}/{field}` (опц.), `GET /{path}/action-log`, `POST /{path}/action-log/{logId}/undo`, `GET /{path}/action-progress/{progress}` | `defaults('resource', $resourceClass)` ставится на все routes; `name($base)` присваивает имена; `middleware([...])` / `where([...])` применяются ко всему набору. |
| `tablesResource($path, ControllerClass)` | UI-страница с явным host-контроллером (custom controller вместо `GenericTablesController`). | Тот же набор маршрутов, что у `tablesPage`. | То же fluent-API. **Не** ставит `defaults('resource')` — host-контроллер сам знает, какой ресурс рендерить. |
| `tablesApi($uri, ResourceClass)` | JSON API-поверхность ListResource. | `GET /{uri}` → `JsonApiController` (`.index`), `POST /{uri}` → `JsonApiController` (`.query`), `GET /{uri}/schema` → `JsonApiSchemaController` (`.schema`), `POST /{uri}/mutate` → `JsonApiMutateController` (`.mutate`) | `defaults('resource', $resourceClass)` ставится на все 4 routes; `name($base)` присваивает имена `.index/.query/.schema/.mutate`; `middleware([...])` / `where([...])` применяются ко всем 4. |

### 4.3. Compliance rules

- **Никаких implicit-сторон**. UI и API живут в разных файлах маршрутов
  (`routes/web.php` vs `routes/api.php`) и регистрируются отдельными
  вызовами макросов. Если host хочет обе поверхности на одном ресурсе —
  делает два вызова, по одному на поверхность. Это сделано намеренно:
  middleware-стек разный (web-сессия vs api-bearer-token), и пакет не
  должен угадывать комбинацию за хоста.
- **Никакого `->withApi()`-fluent поверх `tablesPage`/`tablesResource`**.
  Если такое появится в будущей фазе — оно нарушит правило «один макрос =
  одна поверхность» и сделает routing невидимым в файлах маршрутов.
- **Middleware наследуется от файла маршрутов**. Конкретный stack
  (`web` / `api`) задаётся `Route::middleware([...])->group(fn() => …)`
  на уровне `routes/web.php` или `routes/api.php`; пакет ничего не
  добавляет от себя. Mutate-эндпоинт — не исключение: он публикуется
  всегда (одинаковый URL-shape для всех ресурсов), но гейтится в
  контроллере через `ApiConfig::allowMutations(true)`. Без явного opt-in'а
  любой `POST /{uri}/mutate` отвечает 403 `MUTATIONS_DISABLED`.

### 4.4. Route ↔ controller pinning

`tablesApi` ставит каждому из 4 маршрутов `defaults('resource', $class)`.
Все три API-контроллера выполняют один и тот же контракт-чек на старте:

```php
$resourceClass = $request->route()?->defaults['resource'] ?? null;
if (! is_string($resourceClass) || ! class_exists($resourceClass)) {
    Log::error('tables.api.bad_route', [...]);
    throw new LogicException(
        '<Controller>: route is missing "resource" default — register the route via Route::tablesApi().',
    );
}
```

Это и есть «opt-in compliance gate»: если кто-то зарегистрировал маршрут
руками (минуя макрос) и забыл `defaults('resource')`, контроллер падает с
`LogicException` (не envelope-ошибкой), а Laravel exception handler пишет
500. Семантика — bug в host-конфигурации, не пользовательская ошибка.

Дополнительно — есть `ResourceRegistry`-боот-сканер
(`TablesServiceProvider::boot()`): после регистрации всех маршрутов он
проходит по `Router::getRoutes()`, собирает `defaults['resource']` и
регистрирует уникальные классы в `ResourceRegistry`. Это даёт стабильный
inventory ресурсов для синхронизации saved views и cli-команд.

## 5. Error envelope contract

JSON API имеет ровно один формат ошибок и ровно одну точку его генерации.
UI-слой использует свой параллельный envelope — они **намеренно разные**,
потому что у них разные клиенты (machine-to-machine vs Blade + Turbo).

### 5.1. API error envelope

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "...",
    "details": { ... }
  }
}
```

Единая точка генерации — `ApiErrorResponse::make($code, $message, $details)`.
Это **единственная** точка, которая возвращает 4xx/5xx-ответ в API-слое.
Grep `response()->json(['error' …])` по всему `src/` даёт **0 совпадений** —
никаких inline-обёрток. Уровень логирования зависит от статуса:

- 5xx → `Log::error('tables.api.error', …)`;
- 4xx → `Log::warning('tables.api.error', …)`.

Парсеры (`ApiQueryParser`, `MutateBodyParser`) **не** дублируют
`Log::warning`-строки перед `throw` — единственная точка лога вверх по
стеку в контроллере через `ApiErrorResponse::fromException()`.

Helpers:

- `ApiErrorResponse::capabilityUnsupported($capability)` — shortcut для
  `CAPABILITY_UNSUPPORTED` с `details.capability`. Используется контроллерами
  read-path при `assertCapabilities()`-mismatch'е и mutate-контроллером при
  `! source->capabilities()->mutate`.
- `ApiErrorResponse::fromException(ApiValidationException $e)` — единый
  путь конвертации parser-/validation-исключений в envelope. Берёт
  `$e->errorCode`, `$e->getMessage()`, `$e->details`.

### 5.2. `ApiErrorCode` → HTTP status mapping

| Code | HTTP | Источник |
|---|---|---|
| `MALFORMED_QUERY` | 400 | парсеры (`?qb=` decode-fail, unsupported content-type на mutate) |
| `VALIDATION_FAILED` | 422 | парсеры (per-field validation), `MutateBodyParser` (payload-size, ops-shape), Laravel `ValidationException` в form-handlers |
| `CAPABILITY_UNSUPPORTED` | 422 | `JsonApiController::assertCapabilities()`, `JsonApiMutateController` (`! caps.mutate`), `MutateBodyParser` (cell-edit нет в `allowFields`) |
| `MUTATIONS_DISABLED` | 403 | `JsonApiMutateController` (`! $config->getAllowMutations()`) |
| `POLICY_DENIED` | 403 | `JsonApiMutateController` coarse Gate (`! Gate::check($ability, $resource)`) |
| `RESOURCE_NOT_FOUND` | 404 | контроллеры (`! is_subclass_of(..., ListResource::class)`) |
| `RECORD_NOT_FOUND` | 404 | mutate-handlers (`$source->find($id) === null`) |
| `ACTION_NOT_FOUND` | 404 | `MutateBodyParser` (`action` не зарегистрирован у ресурса) |
| `SOURCE_ERROR` | 500 | `JsonApiController` обёртки `try/catch` вокруг `resolveSource()` / `Source::page()` |
| `MUTATION_FAILED` | 500 | `JsonApiMutateController` обёртка `try/catch` вокруг `dispatchOp()` |

`ApiErrorCode::cases()` гарантирует ≥ 10 кодов (текущее значение). Маппинг
`code → HTTP status` живёт в `ApiErrorCode::httpStatus()` — один источник
правды для всех контроллеров.

### 5.3. `LogicException` vs envelope

Не все «ошибки» в коде идут через envelope. Контракт-нарушения **маршрута**
(не входные данные пользователя — bug в host-конфигурации) бросают чистый
`LogicException` и идут до Laravel exception handler'а:

| Случай | Куда |
|---|---|
| `route()->defaults['resource']` отсутствует | `LogicException` (бросают все 3 API-контроллера) |
| `dispatchOp(): default => throw LogicException("Unknown op: ...")` | `LogicException` (`MutateBodyParser` валидирует `op`, до контроллера unknown не доходит — это unreachable-guard) |
| `ApiConfig` sentinel `allowFields=null` прорвался до парсера | `LogicException` (резолв обязан был случиться в `ListResource::resolveApiConfig()`) |

Семантика: если пользователь видит 500 от этого — починить надо
**host-конфигурацию**, не отправку запроса. Envelope с `code` тут
неуместен.

### 5.4. UI-layer envelope (параллельный контракт)

UI-слой (`bulk-action`, `row-action`, `cell-update`, `prefs`,
`save-view`, …) возвращает другой envelope через
`Action/Helpers/ActionResponseBuilder`:

```json
{
  "status":  "ok"      | null,
  "warning": "..."     | null,
  "error":   "..."     | null,
  "counts":  { ... }   | null
}
```

Поля семантически дополняют друг друга: одно primary-сообщение
(`status="ok"` для успеха, `error="..."` для ошибки) плюс опциональные
`warning` (deprecation/частичный успех) и `counts` (affected/missing/denied
для bulk). Этот envelope намеренно отличается от API:

- UI клиенты — Blade + Turbo + flash-messages; они умеют рендерить
  `status` / `warning` / `error` через redirect-`withErrors()` или
  `Response::json` для async-actions;
- API клиенты — machine-to-machine; им нужны стабильные `code`-строки
  и HTTP-статусы.

Один rule applies to both: ни UI, ни API не вкладывают `response()->json(['error' => …])` инлайн —
только через свой factory (`ApiErrorResponse` или `ActionResponseBuilder`).

### 5.5. Mutate hardening surface

Гейты mutate-эндпоинта — пять уровней, конфигурируемых на ресурсе через
`ApiConfig`. Каждый уровень имеет свой error code / HTTP-статус.

| Лимит | ApiConfig wither | Default | Effect при превышении / отказе |
|---|---|---|---|
| **Hard-gate** разрешения mutate | `->allowMutations(true)` | `false` | 403 `MUTATIONS_DISABLED` (контроллер, ДО парсинга body) |
| **Coarse Gate** — ability на mutate-операцию | `->mutateAbility('orders.mutate')` | `null` (выкл) | 403 `POLICY_DENIED` (контроллер, ПОСЛЕ `allowMutations`, ДО парсинга) |
| **Source capability** — driver поддерживает `update()` | (не настраивается на ресурсе; зависит от `Source::capabilities()->mutate`) | зависит от driver'а (default-`true` только у `EloquentSource`) | 422 `CAPABILITY_UNSUPPORTED` (контроллер, ПОСЛЕ парсинга, ДО dispatch) |
| **Bulk IDs limit** | `->maxBulkIds(1000)` | `1000` | 422 `VALIDATION_FAILED` (`MutateBodyParser`) |
| **Payload size** — `strlen(json_encode(payload))` для row/bulk | `->maxPayloadBytes(65_536)` | `65_536` (64 KiB) | 422 `VALIDATION_FAILED` с `details.reason='payload_too_large'` (`MutateBodyParser`) |

Per-action policy внутри `RowActionHandler` / `BulkActionHandler` /
`CellUpdateHandler` — **orthogonal** к coarse Gate. Coarse Gate отвечает
на «может ли actor вообще обращаться к mutate-API этого ресурса»;
per-action — «может ли actor вызвать конкретный action на конкретной
строке». Обе проверки делаются независимо; coarse — раньше.

`maxPerPage` (default `200`) — read-side лимит, отдельный от mutate-surface;
превышение → 422 `VALIDATION_FAILED` в `ApiQueryParser`.

## 6. Extension points

Четыре естественные точки расширения, в порядке убывания частоты. Каждая
показана PHP-скетчем минимально-достаточного объёма; реальные имплементации
в `src/` подтянут детали (логирование, edge cases, immutable apply).

### 6.1. Новый `Source`-driver

Минимальный driver — реализовать `Source` interface (8 методов) и решить,
какие capabilities декларировать. Read-only driver — `mutate=false`,
`update()` бросает `LogicException`.

```php
namespace App\Tables\Source;

use Generator;
use Mercurio\Tables\Source\{Source, Capabilities, Query, Page};
use LogicException;

final class RedisListSource implements Source
{
    public function __construct(
        private readonly string $listKey,
        private readonly Query $query = new Query,
    ) {}

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            filter: false, sort: false, search: false,
            count: true, cursor: false, mutate: false,
            stream: true, qbTree: false,
        );
    }

    public function withQuery(Query $q): static
    {
        return new self($this->listKey, clone $q);  // immutable apply
    }

    public function count(): ?int       { return \Redis::llen($this->listKey); }
    public function page(int $p, int $pp): Page { /* LRANGE … */ }
    public function stream(int $cs): Generator  { /* LRANGE по чанкам, yield each */ }
    public function find(int|string $id): mixed     { /* по индексу */ }
    public function findMany(array $ids): iterable { /* MGET-style */ }
    public function update(int|string $id, array $changes): mixed {
        throw new LogicException('RedisListSource is read-only.');
    }
    public function probe(): mixed { return null; }
}
```

Подключение через `ListResource::resolveSource()`:

```php
protected function resolveSource(): Source
{
    return new RedisListSource('queue:orders');
}
```

Контракт `assertCapabilities()` сам отдаст 422 `CAPABILITY_UNSUPPORTED`,
если клиент попросит `?sort=` / `?filter[..]` / `?qb=` на этом driver'е —
дополнительной защиты в samом driver'е не нужно.

### 6.2. Новый mutate-handler (новый row/bulk-action)

Самый частый «mutate extension» — это **не** новый op-тип, а новый
**action** под существующий `row`/`bulk` op. Action декларируется на
ресурсе через `ListResource::rowActions()` / `bulkActions()`; mutate-API
автоматически принимает его в `POST /{uri}/mutate` body
`{"op":"row|bulk", "action":"<key>"}`.

```php
// На ресурсе:
public function rowActions(): array
{
    return [
        RowAction::make('publish', 'Опубликовать')
            ->canRun(fn ($row) => Gate::check('orders.publish', $row))
            ->handle(function ($row, array $payload, Request $request): ActionResult {
                $row->update(['status' => 'published', 'published_at' => now()]);
                return ActionResult::ok('Опубликовано', counts: ['affected' => 1]);
            }),
    ];
}
```

`MutateBodyParser` валидирует `action` против `rowActionsMemo()` /
`bulkActionsMemo()` ресурса; неизвестный action → 404 `ACTION_NOT_FOUND`.
Per-action policy (`canRun`) — orthogonal к coarse `mutateAbility` Gate.

Полноценный **новый op-тип** (например `restore`/`merge`) — это работа
поверх всех слоёв одновременно: `MutateBodyParser::ALLOWED_OPS` +
`dispatchOp()` в `JsonApiMutateController` + новый `XxxMutateResult` +
ветка в `MutateRenderer::render()`. На v2 это сознательно держится
закрытым (3 op'а покрывают известный use-case).

### 6.3. Новый error code

`ApiErrorCode` — sealed-style enum: добавить case + ветку в `httpStatus()`.

```php
// src/Api/ApiErrorCode.php
case ConcurrencyConflict = 'CONCURRENCY_CONFLICT';

public function httpStatus(): int
{
    return match ($this) {
        // …существующие cases…
        self::ConcurrencyConflict => Status::HTTP_CONFLICT,  // 409
    };
}
```

Использование — через `ApiErrorResponse::make(ApiErrorCode::ConcurrencyConflict, $msg, $details)`
или через `ApiValidationException` (если ошибка throw-up из handler'а):

```php
throw new ApiValidationException(
    ApiErrorCode::ConcurrencyConflict,
    'Row was modified by another actor.',
    ['expected_version' => $expected, 'actual_version' => $actual],
);
```

Никаких дополнительных регистраций / маппингов в конфиге не нужно —
`ApiErrorResponse::fromException()` сам подхватит новый код.

### 6.4. Новый `FormatMode` / include key

#### 6.4.1. Новый `FormatMode` (полный пример с обоими путями `?format=` и `?format[field]=`)

```php
// src/Api/FormatMode.php
case Display = 'display';   // только display-string, без raw
```

Парсинг уже работает «бесплатно»: `ApiQueryParser::parseFormat()` зовёт
`FormatMode::tryFrom($value)` и для global-параметра (`?format=display`),
и для per-field map'а (`?format[total]=display` или
`body.format={"total":"display"}`). Обе ветки покрыты одной функцией
`tryFrom()` — ничего отдельно регистрировать не нужно.

Рендер — добавить ветку в `JsonRenderer::renderField()` рядом с
существующими:

```php
return match ($mode) {
    FormatMode::Raw       => $raw,
    FormatMode::Formatted => $field->exportValue($raw, $row),
    FormatMode::Both      => ['raw' => $raw, 'display' => $field->exportValue($raw, $row), 'tone' => null],
    FormatMode::Display   => $field->exportValue($raw, $row),
};
```

Semantic of routing:

- `?format=display` (global) — `$rendererMode = ParsedApiQuery::$format = FormatMode::Display`
  применяется ко всем полям, у которых нет override;
- `?format[total]=display` (per-field) — попадает в
  `ParsedApiQuery::$perFieldFormats['total'] = FormatMode::Display`, и
  `JsonRenderer` для этого поля берёт **per-field** значение (правило:
  `$mode = $perFieldFormats[$name] ?? $format`).

#### 6.4.2. Новый include block

Добавить ключ в whitelist + блок в renderer. Для read-эндпоинта:

```php
// src/Api/ApiQueryParser.php
public const KNOWN_INCLUDES = [
    'data', 'page', 'summary', 'savedViews', 'capabilities', 'schema',
    'cursorWindow',  // ← новый блок
];

// src/Api/JsonRenderer.php — render($page, $source, $resource, $parsed, $config)
if ($parsed->wantsInclude('cursorWindow')) {
    $envelope['cursorWindow'] = [
        'prevCursor' => $page->prevCursor ?? null,
        'nextCursor' => $page->nextCursor ?? null,
    ];
}
```

Для mutate — симметрично через `MutateBodyParser::KNOWN_MUTATE_INCLUDES` +
ветку в `MutateRenderer::render()`. Неизвестные include'ы (не из
whitelist'а) → WARN + skip, не fail (тот же подход, что и в read-side).

---

## See also

- [`docs/api.md`](../api.md) — Blade-pages + DSL: как объявлять `ListResource`,
  fields, row/bulk actions, saved views. **Точка входа для host-разработчика.**
- [`docs/json-api.md`](../json-api.md) — JSON API guide: URL-параметры,
  envelope-блоки, mutate-эндпоинт. **Точка входа для API-клиента.**
- [`docs/sources.md`](../sources.md) — выбор и настройка Source-драйвера:
  use-cases, capabilities, ограничения, memoization. **Точка входа для
  intergration-разработчика.**
