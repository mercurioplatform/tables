# Public API matrix — mercurioplatform/tables v0.1.0

> **Стабильность**: всё, что перечислено в разделе **Stable API**, не меняется breaking-способом без major-bump (v0.x → v1.0 → v2.0). Расширения (новые методы, новые подклассы, новые конфиги, новые слоты) допустимы в minor (v0.1 → v0.2). Bug-fixes и improvements внутри internal — patch (v0.1.0 → v0.1.1).
>
> **Internal symbols** (раздел внизу) могут меняться в minor/patch без deprecation cycle. Использовать на свой риск; пакет не гарантирует совместимость.

## Stable API

### Resource & lifecycle

#### `Mercurio\Tables\ListResource` (abstract)

Override-points (фиксируются как часть контракта; добавление новых методов — non-breaking):

- `key(): string` — уникальный ID ресурса (в URL routing + saved views FK + audit log).
- `query(): \Illuminate\Database\Eloquent\Builder`
- `fields(): array<int, Field>`
- `searchable(): array<int, string>`
- `savedViews(): array<int, SavedView>`
- `bulkActions(): array<int, BulkAction>`
- `rowActions(): array<int, RowAction>`
- `summary(): ?Summary`
- `perPage(): int`
- `defaultSort(): array{0: string, 1: 'asc'|'desc'}|null`
- `routeBaseName(): ?string`
- `policy(): ?string` (FQCN политики Gate)
- `pageTitle(): ?string`
- `browserTitle(): ?string`
- `subtitle(int $total): ?string`
- `breadcrumbs(): array<int, Breadcrumb>`
- `headerActions(): array<int, HeaderAction>`
- `emptyState(): ?EmptyState`
- `flashKeys(): array<int, string>`
- `actionHistoryEnabled(): bool`
- `resolveAuditActor(\Illuminate\Http\Request $request): ?\Illuminate\Contracts\Auth\Authenticatable`

Invariants:

- `key()` стабилен по жизни приложения (используется как FK в `tables_saved_views.resource_key` и audit log).
- Любой переопределённый метод вызывается **до** AJAX/HTTP-обработки; side-effects (запросы к БД, IO) допустимы только в `query()` и `summary()`.

#### `Mercurio\Tables\ResourceTable`

Runtime VO собранной таблицы. Передаётся в blade-компоненты `<x-tables.shell :table>` / `<x-tables.page :resource>`. **Не инстанцировать вручную** — собирается через `ListResource::table($request)`.

Public read-only properties / accessors: `rows`, `fields`, `paginator`, `savedViews`, `bulkActions`, `rowActions`, `sort`, `currentView`, `summary`, `total`, `density`, `prefs`.

#### `Mercurio\Tables\ResourceRegistry`

DI-singleton. Метод `find(string $key): ?ListResource` — lookup для sync-savedviews и Query Builder. Метод `register(string $resourceClass): void` — для пользователей, которые хотят явный bind вне роута (редкий кейс).

#### `Mercurio\Tables\Concerns\HandlesResourceListing` (controller trait)

Публичные HTTP-action методы (signature не меняем):

| Method | HTTP role |
|---|---|
| `index(\Illuminate\Http\Request $request)` | список + AJAX-partial |
| `bulkAction(\Illuminate\Http\Request $request)` | execute bulk handler |
| `bulkActionForm(\Illuminate\Http\Request $request, string $name)` | form schema render |
| `rowAction(\Illuminate\Http\Request $request, string $name, $id)` | execute row handler |
| `rowActionForm(\Illuminate\Http\Request $request, string $name, $id)` | form schema render |
| `actionLog(\Illuminate\Http\Request $request)` | offcanvas history |
| `actionLogUndo(\Illuminate\Http\Request $request, $id)` | undo entry |
| `prefs(\Illuminate\Http\Request $request)` | save user prefs |
| `savedViewSave/savedViewUpdate/savedViewDelete/savedViewSetDefault` | user-saved views CRUD |
| `export(\Illuminate\Http\Request $request)` | CSV stream / async dispatch |
| `cellEdit(\Illuminate\Http\Request $request, $id, string $field)` | inline cell update |
| `actionProgress(\Illuminate\Http\Request $request, string $progressId)` | bulk-progress polling |
| `options(\Illuminate\Http\Request $request)` | autocomplete для BelongsToField |

Trait требует `protected string $resource = ResourceClass::class`. Внутренняя реализация делегирует на helper/handler-классы (`@internal`, см. ниже).

#### `Route` macros

- `Route::tablesResource(string $uri, string $controllerClass): PendingTablesResource` — escape hatch для кастомных контроллеров с `HandlesResourceListing`.
- `Route::tablesPage(string $uri, string $resourceClass): PendingTablesResource` — декларативный путь без контроллера (использует встроенный internal `GenericTablesController`).

`PendingTablesResource` — fluent builder; цепляющиеся методы (`->name()`, `->middleware()`, `->prefix()`, и пр.) перенаправляются на underlying `RouteRegistrar`. **Билдер internal** (см. internal-секцию), но макросы — public.

### Fields

#### `Mercurio\Tables\Field\Field` (abstract)

Fluent DSL. Сигнатуры заморожены:

```php
Field::make(string $key, ?string $label = null): static
->label(string $label): static
->sortable(bool|string $sortable = true): static  // bool или явный column-name
->searchable(bool|string $searchable = true): static
->align('left'|'right'|'center'): static
->width(string $width): static  // CSS width: '120px', '15%', '1fr'
->mono(bool $mono = true): static
->cellView(string $bladePath): static
->displayUsing(\Closure $fn): static  // ($value, $row): mixed
->subline(\Closure $fn): static  // ($row): ?string
->linkTo(\Closure $fn): static  // ($row): ?string (URL)
->hideByDefault(bool $hidden = true): static
->editable(?\Closure $rules = null): static
->editColumn(string $column): static
->editOptions(\Closure|array $options): static
->editRules(array $rules): static
->editPolicy(string $policyClass, string $method): static
->editStep(float $step): static
->editMin(float|int $min): static
->editMax(float|int $max): static
->filterable(?array $operators = null): static
->filterOptions(\Closure|array $options): static
->onlyFilterable(bool $only = true): static
->filterUsing(\Closure $fn): static  // ($builder, FilterCondition): void
->filterScope(string $modelScopeName): static
```

Конкретные подклассы (без поломок сигнатур):

`TextField`, `TwoLineField`, `NumberField`, `MoneyField`, `DiscountedMoneyField`, `BooleanField`, `StatusField`, `BadgesField`, `ImageField`, `AvatarField`, `BelongsToField`, `BelongsToManyField`, `RelationCountField`, `RatingField`, `ProgressBarField`, `ConditionalColorField`, `JsonField`, `TagsField`, `DateField`.

Расширение через подкласс — допустимо; новые конкретные подклассы добавляются в minor.

### Form fields (для `BulkAction::schema()` / `RowAction::schema()`)

`Mercurio\Tables\Form\Field\FormField` (abstract) + `FieldRow`.

Конкретные:

- `TextField` (alias hint: импортировать как `TextInput` если конфликтует с `Field\TextField`).
- `NumberField` (alias hint: `NumberInput`).
- `TextareaField`, `SelectField`, `RadioGroupField`, `CheckboxField`, `PlaintextField`.

`SelectField` factory-методы:

- `SelectField::options(string $key, string $label, array<string|int, string> $options)`
- `SelectField::enum(string $key, string $label, string $enumClass, ?\Closure $labeler = null)`
- `SelectField::relation(string $key, string $label, string $modelClass, ?string $labelColumn = null)`

Fluent (наследуется на все form-fields): `required()`, `rules(array)`, `messages(array)`, `placeholder(string)`, `default(mixed)`, `disabled(bool|\Closure)`, `help(string)`.

### Actions

#### `Mercurio\Tables\Action\Action` (abstract base)

Общая часть для bulk и row. Не инстанцируется напрямую.

#### `Mercurio\Tables\Action\BulkAction`

Fluent (заморожен):

```php
BulkAction::make(string $name, string $label): static
->instant(): static
->confirm(string|\Closure|null $text = null): static
->preview(\Closure $fn): static  // ($subjects, $payload): View|string|array
->schema(array<int, FormField|FieldRow> $schema): static
->withValidator(\Closure $fn): static
->prepareInput(\Closure $fn): static
->transformValidated(\Closure $fn): static
->handler(string $actionClass): static  // FQCN of Action implementor
->using(\Closure $fn): static  // ($subjects, $payload, $actor): ActionResult
->payload(array $payload): static
->policy(string $policyClass, string $method): static
->onSuccess(\Closure $fn): static  // (ActionResult): string|array
->onError(\Closure $fn): static  // (Throwable): string|array
->icon(string $icon): static
->variant('primary'|'secondary'|'success'|'danger'|'warning'|'info'): static
->undoable(\Closure $reverse): static  // ($subjectIds, $payload, $actor): ActionResult
->queue(?string $queueName = null): static
->queueChunkSize(int $size): static
->queueWhen(\Closure $fn): static
```

Mutex: `queue()` и `undoable()` нельзя вызывать на одном action одновременно (declaration-time guard).

#### `Mercurio\Tables\Action\RowAction`

Аналогичный fluent (без `queue`/`queueChunkSize`/`queueWhen`, плюс `link($urlClosure)`, `tooltip(string)`, `hideWhen(\Closure)`).

#### `Mercurio\Tables\Action\ActionResult`

Read-only VO. Конструктор/публичные геттеры:

```php
new ActionResult(int $affected, int $missing = 0, int $skipped = 0, int $denied = 0, ?string $message = null, array $flash = [])
->affected(): int
->missing(): int
->skipped(): int
->denied(): int
->message(): ?string
->flash(): array
->counts(): array<string, int>
```

#### `Mercurio\Tables\Jobs\BulkActionJob`

`ShouldQueue`-job для `->queue()` action'ов. FQCN кастомизируется через `config('tables.bulk_progress.job_class')`. Public — на случай override через подкласс.

### Saved views & filters

- `Mercurio\Tables\View\SavedView` — VO. Конструкторы: `::all($label)`, `::scope($key, $label, $modelScopeName)`, `::query($key, $label, \Closure)`. Fluent: `->default()`, `->position(int)`, `->color(string)`, `->icon(string)`.
- `Mercurio\Tables\Filter\Operator` — enum (`Eq`, `Neq`, `In`, `NotIn`, `Contains`, `NotContains`, `StartsWith`, `NotStartsWith`, `EndsWith`, `NotEndsWith`, `Between`, `NotBetween`, `Empty`, `NotEmpty`, `Gt`, `Lt`, `Gte`, `Lte`).
- `Mercurio\Tables\Filter\FilterCondition` — VO одного условия. Передаётся в `Field::filterUsing(\Closure)` callback. Public-properties: `field`, `operator` (Operator), `value`, `not` (bool).

### Page chrome

- `Mercurio\Tables\Page\HeaderAction::make($label, $href)` + fluent `icon()`, `variant()`, `attrs()`.
- `Mercurio\Tables\Page\Breadcrumb::link($label, $href)`, `Breadcrumb::current($label)`.
- `Mercurio\Tables\Page\EmptyState::make($title)` + `description()`, `icon()`, `cta(HeaderAction)`.

### Summary

- `Mercurio\Tables\Summary\Summary` (abstract).
- `Mercurio\Tables\Summary\KpiSummary`, `KpiCard`.
- `Mercurio\Tables\Summary\FunnelSummary`, `FunnelCard`.

### Models (Eloquent — public по факту вынесения миграций тегом `tables-migrations`)

- `Mercurio\Tables\Models\SavedView`.
- `Mercurio\Tables\Models\UserTablePrefs`.
- `Mercurio\Tables\Models\ActionLog`.
- `Mercurio\Tables\Models\ActionProgress`.

Имена таблиц параметризуются через `config('tables.tables.*')`.

### Services (resolve через DI)

- `Mercurio\Tables\Services\SystemViewSyncer` — ручной триггер `php artisan tables:sync-saved-views`.
- `Mercurio\Tables\Services\SavedViewCountsCalculator`.
- `Mercurio\Tables\Services\UserSavedViewLoader`.
- `Mercurio\Tables\Services\ActionLogWriter`.
- `Mercurio\Tables\Prefs\UserPrefs` (VO), `Mercurio\Tables\Prefs\UserPrefsResolver`.

Override через `app()->bind(...)` — поддерживается.

### Export

- `Mercurio\Tables\Export\ExportRequest` — VO текущего состояния списка.
- `Mercurio\Tables\Export\CsvStreamWriter`.
- `Mercurio\Tables\Export\ExportJobDispatcher` — interface для async fallback (пользователь реализует и биндит в DI).

### Console

- `php artisan tables:sync-saved-views` (`Mercurio\Tables\Console\SyncSavedViewsCommand`).

### Support

- `Mercurio\Tables\Support\TableStateKeys` — public константа `STATE` (whitelist URL-state ключей saved view'а): `['q', 'f', 'qb', 'sort', 'dir', 'columns', 'density', 'per_page']`. JS-копия в `resources/js/tables/saved-views.js::STATE_WHITELIST` синхронизируется вручную (anchor-comment).

### Config keys (`config/tables.php`)

Полный список — см. CHANGELOG `### Public API matrix v0.1.0` → блок `**Config keys**`. Вкратце:

`guard`, `route_prefix`, `default_per_page`, `partial_header`, `js_event_prefix`, `resources`, `autocomplete_*`, `qb_*`, `sync_system_views`, `saved_view_color_palette`, `saved_view_icons`, `row_actions.*`, `bulk_actions.*`, `cell_edit.*`, `user_prefs.*`, `export.*`, `shell.*`, `action_log.*`, `bulk_progress.*`, `tables.*` (DB table names override).

Изменение default-значений — non-breaking (patch). Удаление ключа — breaking (major). Переименование — breaking + deprecation cycle.

### Blade public surface

- `<x-tables.shell :table="$table"/>` — обёртка страницы (layout + breadcrumbs + page-head + flashes).
- `<x-tables.page :resource="$table">` со слотами: `header`, `summary`, `empty-state`.
- `<x-tables.table-root :table="$table"/>` — fragment, возвращаемый AJAX-route'ом (`X-Tables-Partial`).

`vendor:publish --tag=tables-views` — escape hatch на крайний случай (override через копию в `resources/views/vendor/tables/`).

### Publishable tags

- `tables-config` → `config/tables.php`.
- `tables-migrations` → миграции `tables_saved_views`, `tables_user_table_prefs`, `tables_action_log`, `tables_action_progress`.
- `tables-views` → `resources/views/components/tables/*` + корневые view-файлы.
- `tables-assets` → `resources/{js,scss}` пакета.

### JS events

Префикс конфигурируется через `config('tables.js_event_prefix')`, default `tables`.

- `tables:rendered` — таблица перерендерена (после navigate / поиска / фильтра).
- `tables:navigate` — выполнен `pushState` с новым URL.
- `tables:total-changed` — изменился total-counter.
- `tables:flash` — flash-сообщение (`status`/`warning`/`error`) от engine.
- `tables:filter-groups` — обновлены группы фильтров (advanced QB).

Payload-объекты не специфицируются формально (документируются в коде ивент-эмиттеров); поломка структуры payload — minor breaking (рассматривается как deprecation на следующий минор).

---

## Internal symbols (subject to change without semver bump)

> Перечисленные ниже классы помечены `@internal` PHPDoc. Прямое использование (extends/instanceof/import в пользовательском коде) возможно, но не поддерживается: minor- и patch-релизы могут изменить сигнатуры, переименовать классы, объединить или разделить их.

### Action helpers/handlers

- `Mercurio\Tables\Action\Helpers\ActionAuthorizer`
- `Mercurio\Tables\Action\Helpers\ActionPayloadResolver`
- `Mercurio\Tables\Action\Helpers\ActionResponseBuilder`
- `Mercurio\Tables\Action\Handlers\BulkActionHandler`
- `Mercurio\Tables\Action\Handlers\RowActionHandler`
- `Mercurio\Tables\Action\Handlers\ActionLogHandler`

### Services (handlers, не resolve-targets)

- `Mercurio\Tables\Services\UserViewHandler`
- `Mercurio\Tables\Services\CellUpdateHandler`

### Export

- `Mercurio\Tables\Export\ExportHandler`

### Filters

- `Mercurio\Tables\Filter\FilterParser`
- `Mercurio\Tables\Filter\FilterApplier`
- `Mercurio\Tables\Filter\Qb\AtomCondition`
- `Mercurio\Tables\Filter\Qb\AtomGroup`
- `Mercurio\Tables\Filter\Qb\QueryBuilderApplier`
- `Mercurio\Tables\Filter\Qb\QueryBuilderNormalizer`
- `Mercurio\Tables\Filter\Qb\QueryBuilderParser`

### Routing & HTTP

- `Mercurio\Tables\Routing\PendingTablesResource`
- `Mercurio\Tables\Http\GenericTablesController`

### Support

- `Mercurio\Tables\Support\Pluralizer`
- `Mercurio\Tables\Support\UserViewHref`

(`Mercurio\Tables\Support\TableStateKeys` — **Stable**, см. выше.)

---

## Known warts (fixable in v0.2 with deprecation cycle)

*(заполняется по факту — пустой список к моменту freeze'а допустим. Если в процессе будет обнаружено спорное имя/тип публичного API, оно добавляется сюда без правок самого кода.)*

---

## Versioning policy

`mercurioplatform/tables` следует [SemVer 2.0](https://semver.org/spec/v2.0.0.html) от тэга `v0.1.0` (ставится в `Tables-engine-extract-to-org`).

| Изменение | Bump |
|---|---|
| Новый Field-подкласс / новый method override-point на `ListResource` / новый config key с default'ом | minor |
| Изменение default'а config key | patch |
| Bug fix без поломки сигнатур | patch |
| Изменение сигнатуры public-метода / удаление public-symbol / переименование config-key | major (с deprecation cycle на minor) |
| Изменение internal-symbol | любая версия (даже patch) |
| Изменение JS event payload structure | minor (с anchor-warn в CHANGELOG) |
| Удаление JS event | major |

Pre-1.0 (`v0.x`): minor-bump допускает breaking changes на public API при условии явной записи в CHANGELOG `### Breaking changes`. С `v1.0` — только в major.
