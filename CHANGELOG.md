# Changelog

All notable changes to `mercurioplatform/tables` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **`Mercurio\Tables\Source\HttpSource`** — четвёртый полноценный Source-драйвер
  пакета поверх произвольного внешнего HTTP API через декларативный
  fetch-closure. Создаётся через статический фабричный метод
  `HttpSource::for(Closure $fetch, ?Capabilities $capabilities = null, ?ListResource $resource = null, ?Closure $findOne = null, ?Closure $findMany = null, ?array $operatorWhitelist = null, ?int $cacheTtlSeconds = null, ?string $cachePrefix = null, string $primaryKey = 'id')`.
  Host передаёт `Closure(Query $q, ?string $cursor): array{rows, nextCursor, prevCursor, total}`
  — драйвер сам делает capabilities-gating, кэширование (Laravel
  `Cache::remember` с детерминированным `json_encode` ключом по explicit
  списку Query-полей), Log-каналы и собирает {@see Page} в правильном
  режиме. Capabilities по умолчанию: `filter / sort / search / stream = true`,
  **`cursor = true`** (cursor primary), **`count = false`** (cursor-mode без
  total), **`mutate = false`** (hard-denied, без override — `update()`
  логирует `tables.source.http.mutate_denied` и бросает `LogicException`
  всегда). Host может явно передать `Capabilities(count: true)` для
  offset-режима, тогда fetch обязан возвращать `total`. **Per-field
  operator whitelist** через `operatorWhitelist: array<field, list<Operator>>`:
  для поля в whitelist оставляем только разрешённые операторы (skip + WARN
  иначе); поля без entry — no constraint. **Кэширование** через
  `cacheTtlSeconds` (Laravel `Cache::remember`); ключ детерминирован
  (`{cachePrefix или 'tables.http'}.{resource_key|'anonymous'}.{sha1(...)}`),
  host инвалидирует через `Cache::forget(...)` или ждёт TTL. **`find($id)`**
  fallback через chip-фильтр `primaryKey = $id` + `withQuery(...)->page(1, 1)`,
  closure-injection (`findOne`) даёт O(1)-путь; edge-case: `Operator::Eq` не
  в whitelist для primaryKey → WARN + `null`. **`findMany($ids)`**
  bulk-fallback через `In`-условие на `primaryKey` — один HTTP-запрос
  вместо N; degrade на N×`find()` loop если `Operator::In` не в whitelist
  (WARN `tables.source.http.find_many.linear_fallback`); closure-injection
  (`findMany`) — самый быстрый путь. **`stream($chunkSize)`** обязан
  использовать cursor: при `capabilities.cursor=false` yield первой
  страницы + WARN; safety-cap 10 000 итераций / 1 000 000 yielded строк
  против infinite-cursor-loop. Ограничения: `qbRoot` skip + WARN
  (`?qb=` AST не транслируется в HTTP-параметры); `SavedView::scope`
  задисейблен (source-agnostic формы `conditions()` / `sourceClosure()`
  работают); `probe(): null` (нет Eloquent-модели); `findMany` не
  сохраняет порядок ids; Field-aware customizations не применяются.
  Основные use-cases — Shopify Admin API / Stripe API / GitHub REST API /
  внутренние REST / gRPC bridge-сервисы.

- **`Mercurio\Tables\Source\SqlSource`** — третий полноценный Source-драйвер
  пакета поверх произвольного `DB::connection`. Создаётся через статический
  фабричный метод
  `SqlSource::for(string $table, ?string $connection = null, string $primaryKey = 'id', ?Capabilities $capabilities = null, ?ListResource $resource = null)`,
  внутри которого собирается **inline-Model** (голый `Eloquent\Model`-subclass
  без relations / scopes / observers / accessors — `Mercurio\Tables\Source\Support\SqlSourceModel`)
  и оборачивается в `Eloquent\Builder`. За счёт этого весь существующий
  applier-стек (`BuiltinFilterApplier`, `FilterApplier`, `QueryBuilderApplier`,
  `SavedViewCountsCalculator`, `paginate(...)->withQueryString()`,
  `lazyById(...)`) переиспользуется без правок сигнатур. Capabilities по
  умолчанию: `filter / sort / search / count / stream = true`,
  `cursor = false`, `mutate = false` (read-only — `update()` логирует
  `tables.source.sql.mutate_denied` и бросает `LogicException`; host
  может явно включить `mutate = true` через четвёртый аргумент `for()`,
  тогда `update()` выполнит raw SQL UPDATE через
  `Builder::update($changes)` без observer'ов / accessors). Ограничения:
  search — single-column LIKE (dotted-path → WARN
  `tables.source.sql.search_dotted_unsupported` + skip; inline-Model не
  имеет relations); `SavedView::scope` / `SavedView::query(Closure)` — WARN
  `tables.source.sql.saved_view_scope_unsupported` + skip (используйте
  source-agnostic `SavedView::conditions()` / `SavedView::sourceClosure()`);
  `probe(): null` (нет model-class для type-based authz — host реализует
  `Field::canSee` / `RowAction::canRun` вручную); `findMany([…])` не
  сохраняет порядок IN-листа. Основные use-cases — ClickHouse /
  read-replica / BigQuery-через-bridge / unmanaged tables / legacy
  schemas без `EloquentModel` в проекте.

- **Source-абстракция (фаза 3): `ArraySource` + in-memory эвалюаторы.**

  - **`Mercurio\Tables\Source\ArraySource`** — второй полноценный Source-драйвер
    пакета поверх `Illuminate\Support\Collection`. Конструктор:
    `new ArraySource(Collection|iterable $rows, ?Capabilities $caps = null, string $primaryKey = 'id', ?ListResource $resource = null)`.
    Capabilities по умолчанию: `filter / sort / search / count / stream = true`,
    `cursor = false`, `mutate = false` (read-only). Immutable: `withQuery()`
    возвращает новый instance с пре-фильтрованной/sorted коллекцией через
    `->values()`. Под капотом — search через `mb_stripos`, chip-фильтры
    через `BuiltinFilterEvaluator`, `?qb=` AST через `AtomEvaluator`, sort
    через `Collection::sortBy(callable, SORT_NATURAL | SORT_FLAG_CASE)`.
    `page()` собирает делегат `LengthAwarePaginator` вручную — Blade-пагинатор
    `tables::pagination-bs5` работает без правок. `update()` бросает
    `LogicException` как final guard (mutate-stack уже отрезан UI-gating'ом
    из Phase 2 и серверными `mutate=false → 422`).

  - **`Mercurio\Tables\Filter\Qb\AtomEvaluator`** — in-memory эвалюатор AST из
    Query Builder'а (`AtomCondition` / `AtomGroup` → `bool`). Семантически
    эквивалентен `QueryBuilderApplier` (Eloquent), но работает над in-memory
    row. Reused в Phase 5/6 (HttpSource client-side fallback, FileSource).
    Field-aware `filterUsing` / `filterScope` (Builder-only) на in-memory row
    применить нельзя — atom'ы всегда идут через built-in operator semantics;
    ArraySource при detection кастомизации пишет WARN
    `tables.array_source.field_filter_customization_skipped`.

  - **`Mercurio\Tables\Filter\BuiltinFilterEvaluator`** — in-memory зеркало
    `BuiltinFilterApplier`. `matches(mixed $value, Operator $op, mixed $expected): bool`
    по всем 18 операторам enum `Operator`. Разница vs SQL `LIKE`:
    `Contains` / `StartsWith` / `EndsWith` сравнивают строки как **буквальный
    substring**, SQL-метасимволы (`%`, `_`) трактуются буквально.

  - **`Mercurio\Tables\Source\Support\RowValueExtractor`** — единое извлечение
    значения row по `field`-имени для in-memory эвалюаторов и для
    сортировки / search-substring в `ArraySource`. Поддерживает
    `array<string, mixed>`, `Eloquent\Model` (`getAttribute()` с accessor'ами),
    `Arrayable`, `ArrayAccess`, `object` (public props) и dotted-path
    (`order.customer.email`) с рекурсивным null-safe спуском.

  - **`SavedViewCountsCalculator` — поддержка non-Eloquent sources.** Ранее
    counts работали только на `EloquentSource` (SQL UNION subqueries через
    `getBuilder()->toSql()`). Добавлен универсальный путь
    `countsForGenericSource()`: для каждой view собирается `Query`
    (`conditions` + опциональный `sourceClosure`), вызывается
    `$source->withQuery($svQuery)->count()`. `view->scope` (string или
    Closure) и `view->countWith()` — Eloquent-only API, на non-Eloquent
    source'ах пропускаются с WARN `tables.saved_view.scope_unsupported_on_non_eloquent`
    и `tables.saved_view.count_callback_unsupported_on_non_eloquent`. SQL
    UNION-ветка для `EloquentSource` без regressions.

  - **`docs/sources.md`** — новый раздел `ArraySource`: use-cases, пример
    `Resource::source()`, таблица capabilities, список ограничений (read-only,
    sort по реляционным полям только через eager-load / RowValueExtractor,
    exact substring без LIKE-метасимволов, find — линейный scan, no-counts
    через `view->scope`).

- **Source-абстракция (фаза 1).** Контракт `Mercurio\Tables\Source\Source`
  с реализацией `EloquentSource` — единый адаптер источников данных для
  Resource-ов. Внутренний pipeline (`TableBuilder` → `FilterPipeline` →
  `SortResolver` → `Source::withQuery` → `Source::page`) больше не ходит
  через `Eloquent\Builder` напрямую. Подготовительный шаг для подключения
  не-Eloquent источников (ArraySource / SqlSource / HttpSource / FileSource)
  в следующих фазах. Capabilities-декларация (`filter` / `sort` / `search` /
  `count` / `cursor` / `mutate` / `stream`) позволяет источникам корректно
  заявлять, какие операции они поддерживают.

- **VO `Page` вместо `LengthAwarePaginator`.** `ResourceTable::$page` —
  унифицированный результат пагинации с двумя режимами: **offset** (с
  делегатом `LengthAwarePaginator` для рендера) и **cursor** (next/prev
  cursors, без `total`). Blade-пагинатор `tables::pagination-bs5`
  ветвится через `@if ($paginator->isCursor())` и поддерживает оба
  режима из коробки. Новые translation keys
  `tables::shell.pagination.previous` / `tables::shell.pagination.next`.

- **`ListResource::source(): ?Source`** — primary contract для Resource-ов
  на новых не-Eloquent источниках. `ListResource::resolveSource()` —
  единая точка резолва для внутреннего pipeline.

- **`Source::stream($chunkSize): Generator`** на месте `chunkById` —
  стрим выборки для экспорта. Для `EloquentSource` реализован через
  `lazyById($chunkSize)` (память O(chunkSize)).

### Docs

- **`docs/sources.md` — раздел `SqlSource`.** Use-cases (ClickHouse /
  read-replica / unmanaged tables / legacy schemas), capabilities table,
  пример Resource'а на `SqlSource::for(...)` с inline-Model, пример
  override-capabilities для opt-in write-API, перечень ограничений
  (single-column search, `SavedView::scope` отключён, `probe(): null`,
  `findMany([…])` не сохраняет порядок IN-листа, update без
  observer'ов / accessors / model-events) и decision-tree
  «EloquentSource vs ArraySource vs SqlSource vs HttpSource/FileSource».

### Changed

- **`ListResource::query(): ?Builder` — DEPRECATED.** Старый контракт
  продолжает работать v2-сессии через `EloquentSource`-shim (с
  `E_USER_DEPRECATED` и info-логом `tables.list_resource.query_shim_used`).
  Подклассы Resource-ов из host-приложений менять не требуется. Удаление
  shim'а — версия 3.

- **`ListResource::exportState(): array{source: Source, …}`** — было
  `array{builder: Builder, …}`. Хосты, читающие `$state['builder']`,
  должны перейти на `$state['source']` (или, как временный путь, на
  `$state['source']->getBuilder()` для `EloquentSource`).

- **`ResourceTable::$paginator` — DEPRECATED proxy.** Чтение
  `$table->paginator` продолжает возвращать LengthAwarePaginator-compatible
  объект через `__get`, но первое обращение пишет DEBUG-лог
  `tables.resource_table.paginator_legacy_access`. Замените на
  `$table->page`. Удаление proxy — версия 3.

- **`Row/Bulk/Cell-edit` handlers + `ActionLogHandler` undo-probe** —
  переехали на `Source::find()` / `Source::update()` / `Source::probe()`.
  `ResourceTable::$capabilities` пробрасывается в shell — UI-gating в
  Phase 2.

- **Source-абстракция (фаза 2): Capabilities-gating в UI + source-agnostic
  saved views.**

  - **Capabilities-gating в Blade.** UI автоматически прячет элементы,
    которые источник не поддерживает: `mutate=false` → bulk-bar /
    row-actions / cell-edit / select-all / row-checkbox / action-log
    trigger в shell-header; `sort=false` → sort-link и arrow-icons в `<th>`;
    `search=false` → input `name="q"` в filter-bar; `stream=false` →
    export-кнопка; `count=false` → атрибут `data-tables-total` (JS может
    отличить «unknown total» от «0»). EloquentSource объявляет всё `true`,
    поэтому существующие Resource'ы без правок.
  - **Server-side guards `mutate=false`.** `BulkActionHandler::dispatch()`,
    `RowActionHandler::dispatch()`, `ActionLogHandler::undo()` отвечают
    `422 {"message": tables::shell.mutate_denied}` + WARN-лог при
    `Source::capabilities()->mutate === false`. CellUpdateHandler /
    ExportHandler уже защищены в Phase 1. Новый i18n-ключ
    `tables::shell.mutate_denied` (ru/en).
  - **`SavedView::conditions(string $key, string $label, array $conditions)`**
    — source-agnostic перегрузка. Принимает `array<int, FilterCondition>`;
    условия сливаются с user-chip-фильтрами в `Query.conditions` через
    `FilterPipeline` (saved-view сначала, user потом — детерминированный
    trace). Counts работают (built-in операторы + Field-aware
    customizations через `FilterApplier`).
  - **`SavedView::sourceClosure(string $key, string $label, Closure $closure)`**
    — source-agnostic перегрузка. `Closure(Source): Source` применяется в
    `TableBuilder::build()` и `TableBuilder::buildForExport()` ПОСЛЕ
    `Source::withQuery` (immutable transform). Сигнатура
    `Source::withQuery(Query): static` НЕ меняется. В counts unsupported
    (skip + debug-log `tables.saved_view.counts_unsupported_source_closure`).
  - **`SavedView` constructor расширен**: `public readonly array $conditions = []`
    и `public readonly ?Closure $sourceClosure = null` через дефолтные
    параметры — `SavedView::all()`/`scope`/`query` без regression.
  - **Note**: `SavedView::scope(string)` остаётся **EloquentSource-only**;
    для source-agnostic перейдите на `SavedView::conditions(...)`. Runtime
    исключение для не-Eloquent Source-драйверов появится в Phase 3+.
  - **`BuiltinFilterApplier`** (`Mercurio\Tables\Filter\BuiltinFilterApplier`)
    — shared-helper для built-in применения `FilterCondition` без Field-aware
    кастомизаций. Используется и `EloquentSource::applyConditions()`, и
    `SavedViewCountsCalculator::counts()` (устранён cross-class дубликат).

## [1.2.0] — 2026-05-16

### Added

- **Auto-registry ресурсов через route defaults.** `TablesServiceProvider::boot()`
  обходит `RouteCollection`, собирает уникальные `Route::defaults('resource', ...)`
  и регистрирует FQN в `ResourceRegistry`. `Route::tablesPage(...)` уже ставит
  это значение на каждый из 17 named routes — поэтому ресурс попадает в реестр
  без дублирования в `config('tables.resources')`. Совместимо с
  `php artisan route:cache` (defaults сериализуются вместе с маршрутами).
  `config('tables.resources')` остаётся как опциональный override для CLI-only
  ресурсов, ресурсов через `Route::tablesResource(...)` и других исключений.

- **README — секция «Registering resources».** Объясняет разграничение
  `Route::tablesPage(...)` (auto-registry) vs `Route::tablesResource(...)`
  (controller-based, требует config) vs `config('tables.resources')`
  (опциональный override), роль `SystemViewSyncer` и kill-switch
  `config('tables.sync_system_views', false)`.

- **README — секция «Upgrade from v1.1 to v1.2».** Пошаговая инструкция
  по host-cleanup: убрать `Blade::anonymousComponentPath` override,
  переопубликовать views (если был publish), заменить host-side
  `<x-tables.foo>` refs на `<x-tables::foo>`, опционально подчистить
  `config/tables.php`.

### Changed

- **Anonymous components namespace = `tables`.**
  `Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'tables')`
  регистрирует префикс при boot'е. Internal Blade refs пакета переведены на
  namespaced синтаксис (`<x-tables::page>`, `<x-tables::table-root>` и т.д.).
  Старый dot-синтаксис `<x-tables.foo>` больше не работает — заменяется на
  `<x-tables::foo>`.

- **Структура `resources/views/components/` упрощена.** Подпапка
  `components/tables/` убрана, файлы перенесены в `components/`. **Breaking**
  для host-приложений, делавших `vendor:publish --tag=tables-views`:
  опубликованная структура изменилась — нужно переопубликовать с `--force`
  или вручную поправить (`view:clear` + `optimize:clear`). См. секцию
  «Upgrade from v1.1 to v1.2» в README.

### Fixed

- **Summary cards резолвятся без host-override.**
  `<x-dynamic-component :component="$cardView" />` (где `$cardView` =
  `tables::kpi-card` или `tables::funnel-card`) раньше падал с
  `Component tables::kpi-card not found` — namespace `tables` не был
  зарегистрирован у `anonymousComponentPath`. После добавления префикса
  и переноса файлов из подпапки `tables/` Summary работает «из коробки»,
  host-workaround `Blade::anonymousComponentPath(..., 'tables')` в
  `AppServiceProvider` больше не нужен.

## [1.1.0] — 2026-05-16

### Added

- **AJAX wrapper `resources/js/tables/ajax.js`.** Введён `tablesAjax(options)`
  поверх `$.ajax`: авто `X-Requested-With: XMLHttpRequest`, CSRF-токен из
  `<meta name="csrf-token">`, единая retry-политика. Все consumer-модули
  (`action-log`, `bulk-form`, `bulk`, `cell-edit`, `confirm-preview`, `prefs`,
  `progress`, `row-actions`, `saved-views`) переведены — ручная сборка
  `jqXHR`-options удалена. Единая точка для будущих перехватчиков
  (`X-Tables-Partial`, кастомные заголовки).

- **Bootstrap Offcanvas helper `resources/js/tables/offcanvas.js`.**
  `showOffcanvas(target)` / `hideOffcanvas(target)` — принимают DOM-узел,
  id-строку или jQuery-объект через `target.jquery` duck-typing. Заменил
  повторяющийся `bootstrap.Offcanvas.getOrCreateInstance(el).show()` dance
  в `bulk-form.js`, `row-actions.js`, `confirm-preview.js`, `qb.js`. No-op,
  если `window.bootstrap` недоступен (host-страница вне admin-контекста).

- **Логгер `resources/js/tables/logger.js`.** Единый API
  `error(msg, ...args)` / `warn(msg, ...args)` + `logger.scope(name)` для
  scoped-префиксов: `[tables]` для базового логгера, `[tables.<scope>]` для
  подмодулей (используется в `bulk` → `bulk_progress`, `progress` → `progress`).

- **Константы `resources/js/tables/data-attrs.js`.** Около 80 констант
  `ATTRS.*` (имена `data-tables-*` атрибутов без `data-` префикса),
  `EVENTS.{rendered,navigate,totalChanged,flash}` (имена custom-событий с
  префиксом `tables:`), билдер селекторов `sel(name, value?)` с экранированием
  кавычек. Опечатки в именах атрибутов больше не компилируются молча.

- **ESLint flat config (ESLint 9) для JS-кода пакета.** В корне `tables/`
  появился `package.json` (private, только devDeps: `eslint`, `@eslint/js`,
  `globals`) и `eslint.config.js` с правилами `no-unused-vars`, `prefer-const`,
  `no-var`, `no-empty` (allowEmptyCatch), `eqeqeq` с `null: 'ignore'` (idiomatic
  `== null` остаётся). Команды: `npm install && npm run lint` /
  `npm run lint:fix`. Требует Node ≥ 18.18. Runtime-зависимости (jQuery,
  Bootstrap) по-прежнему поставляет host-приложение через Vite;
  `package-lock.json` не коммитится (по аналогии с `composer.lock`).

### Changed

- **Удалён no-op экспорт `initTables()` из `resources/js/tables/index.js`.**
  Модуль side-effect-only — host-страницы расширяют поведение через
  document-listener `tables:navigate`. Поиск по `mercurioplatform/*` не
  нашёл call-site'ов внешнего `initTables()`.

- **jQuery-идиомы причёсаны.** Combined selectors
  (`$('.foo[data-bar="baz"]')`) вместо `.filter(fn)` с attribute-equality;
  `.length`-check вместо `.get(0) + null-check + $()-rewrap`; error-only
  logging policy по handlers/services (happy-path `console.log`/`console.info`
  удалены). Затронуты модули `resources/js/tables/*` без изменения
  публичного DOM-контракта.

- **Console-логи унифицированы через `logger.js`.** Прямые
  `console.error('[tables...] ...')` в `i18n.js`, `core.js`, `bulk.js`
  (scope `bulk_progress`), `cell-edit.js`, `saved-views.js`, `progress.js`
  (scope `progress`) заменены на `logger.error` / scoped `log.error`.

- **Имена `data-tables-*` атрибутов и custom-событий централизованы.** 16
  consumer-модулей переведены: все `[data-tables-*]` селекторы,
  `.attr('data-tables-*')` геттеры/сеттеры и строки custom-событий
  (`tables:rendered`, `tables:navigate`, `tables:total-changed`,
  `tables:flash`) теперь идут через `ATTRS`/`EVENTS`/`sel()` из
  `data-attrs.js`. HTML-литералы строковой/template-сборки и внутренние
  `data-path` селекторы `qb.js` — out-of-scope (ушли вместе с декомпозицией
  `qb.js` в этом же релизе).

- **Прогон ESLint по `resources/js/tables/**/*.js`.** Закрыты 2 baseline-ошибки
  без изменения публичного поведения: `qb/render.js` (`let header` →
  `const header`, автофикс `prefer-const`); `offcanvas.js` (удалены мёртвые
  `import jQuery from 'jquery'` и `const $ = jQuery` — оба идентификатора в
  файле не использовались после миграции на `target.jquery` duck-typing).

- **JS-модули `resources/js/tables/qb.js` и `progress.js` декомпозированы.**
  `qb.js` (726 → 250 строк) разбит на `qb/serialization.js` (utf8 base64,
  escapeHtml), `qb/ast.js` (operator-mode map, pure node-mutators по path,
  schema-хелперы), `qb/render.js` (tree → HTML), `qb/form-sync.js`
  (`readFormValues`), `qb/nav.js` (apply/reset/clearFromOutside + state-
  аксессоры). `progress.js` (355 → 88 строк) разбит на `progress/storage.js`
  (localStorage CRUD с warn-логом вместо silent-fail), `progress/ui.js`
  (карточка/тосты/affected-URL), `progress/poller.js` (стейт-машина polling
  + унифицированный `scheduleNext`). Точки входа (`data-tables-qb-*` /
  `data-tables-progress-*`, `window.TablesProgress.{enqueue,errorToast}`,
  события `tables:navigate` / `tables:rendered`) и импорты в
  `resources/js/tables/index.js` не меняются.

## [1.0.0] — 2026-05-15

### Added

- `Field::editableUsing(policy?, rules?, transform?, column?, options?)` — единая
  декларативная точка конфигурации inline cell-edit. Все 5 старых методов
  (`editable`, `editColumn`, `editPolicy`, `editRules`, `editOptions`) сохранены
  как тонкие wrappers и работают как раньше.
- Параметр `transform` — `Closure(mixed $value, Model $model): mixed`,
  вызывается в `CellUpdateHandler` после валидации, до `update()`. Любой
  Throwable из transform логируется (`tables.cell_edit.transform_failed`)
  и возвращает `422` с translation-ключом `tables::cell_edit.transform_failed`
  (поставляется в ru/en).
- `ListResource::cellEditEnabled(): bool` (default `true`). Если переопределить
  в `false`, route `PATCH {base}/cells/{id}/{field}` не регистрируется через
  `Route::tablesPage(...)` (boot-time probe). Любая ошибка резолва Resource'а
  при probe трактуется как fallback `true` с записью
  `Log::warning('tables.routing.cell_edit_probe_failed', ...)`.
- `Field::getCellEditSpec(): ?CellEditSpec` — публичный геттер для внутренних
  сервисов и host-расширений; внутреннее представление настроек cell-edit.

- `RowAction::sharedAuthz()` — opt-in флаг для row-actions, чья policy/ability
  не зависит от состояния конкретной модели. При установленном флаге
  `resolveRowActions()` выполняет один `Gate::check` на класс модели и
  переиспользует результат для всех строк того же класса. На странице
  из 25 строк × 4 row-actions это снижает количество Gate-вызовов с 100
  до 4 (для shared-authz actions). Без флага — текущее поведение (per-row).

### Performance

- **`ListResource` memoization.** Hot-path методы `fields()`, `savedViews()`,
  `bulkActions()`, `rowActions()` теперь резолвятся один раз за request и кешируются
  в private property на instance Resource'а. До патча `fields()` мог вызываться
  9 раз за один `index()` запрос (Filter/Qb/Prefs/UserView/ListResource зовут
  одну и ту же функцию). Если ваш Resource внутри `fields()` ходит в БД
  (autocomplete-опции, динамические options) — это убирает 8 повторных запросов
  на странице.
- Override-точки (`fields()` / `savedViews()` / `bulkActions()` / `rowActions()`)
  работают без изменений — мемо-слой прозрачен.

### Breaking changes

- `Mercurio\Tables\Summary\KpiSummary` и `FunnelSummary` удалены. Используйте `new Summary([...])` напрямую — `Summary` теперь `final` контейнер (раньше abstract).
- Добавлен `Mercurio\Tables\Summary\SummaryCard` (abstract) с обязательным `cellView(): string`. `KpiCard` / `FunnelCard` теперь его наследуют. Публичные конструкторы карточек не изменились — host-named-arguments стабильны.
- Blade-шаблоны `tables::kpi-summary` / `tables::funnel-summary` удалены — рендером занимается единый `tables::summary` через `<x-dynamic-component>`.
- CSS-классы `.tables-summary--kpi` / `.tables-summary--funnel` удалены. Контейнер использует `grid-template-columns: repeat(auto-fit, minmax(180px, 1fr))` (раньше: фиксированные 4 колонки для KPI, auto-fit 140px для funnel). Host может переопределить через CSS-переменные.
- Миграция call-site'ов: `new KpiSummary($cards)` / `new FunnelSummary($cards)` → `new Summary($cards)`.
- `@internal` `Mercurio\Tables\Services\UserSavedViewLoader::loadFor(string $resourceKey)` → `loadFor(\Mercurio\Tables\ListResource $resource)`. Класс отмечен `@internal`, но если хост ранее обращался к нему напрямую — заменить аргумент на сам Resource.
- `@internal` `Mercurio\Tables\Action\Helpers\ActionAuthorizer::authorizeAction(...)` — последний аргумент `string $resourceClass` заменён на `\Mercurio\Tables\ListResource $resource`. `currentTableActor()` / `resolveAuditActorId()` получили опциональный параметр `?ListResource $resource = null`.

### Documentation

- `docs/api.md` приведён в соответствие реальному коду. Исправлено:
  - `HandlesResourceListing`: `prefs(...)` заменён на `savePrefs(...)` + `resetPrefs(...)`,
    `cellEdit(...)` → `cellUpdate(...)`, несуществующий список
    `savedViewSave/Update/Delete/SetDefault` заменён на реальные `saveView(Request)` +
    `deleteUserView(Request, int $id)`. Добавлены `bulkActionPreview(Request, string $action)`
    и `rowActionPreview(Request, $id, string $action)`. Поправлен порядок аргументов
    `rowAction`/`rowActionForm` на `(Request, $id, string $action)`. Типизирован
    `actionLogUndo(Request, int $logId)`. Имена параметров приведены к коду
    (`$name → $action`, `$progressId → $progress`).
  - `Field` DSL: удалены несуществующие fluent-методы `label(string)`, `searchable(bool|string)`,
    `width(string)`, `editStep(float)`, `editMin(int|float)`, `editMax(int|float)`.
    Поправлены сигнатуры: `make(string $name, ?string $label = null)`,
    `sortable(bool $value = true)` (без `|string`), `editable(bool $value = true)`
    (раньше заявлен `?\Closure $rules`; rules задаются отдельно через `editRules(array|Closure)`),
    `filterable(array $operators = [])`, `filterOptions(\Closure|array $optionsOrFn)`,
    `onlyFilterable(bool $value = true)`. Comment-hint'ы callback'ов `subline`/`linkTo`
    выровнены с реальным рендером — оба получают `($value, $row)`, не `($row)`.
    Добавлены публичные `filterGroup(string)`, `filterPopover(string)`,
    `filterAutocomplete(bool)`.
  - `Action`: `Mercurio\Tables\Action\Action` — это `interface` с единственным методом
    `execute(mixed $subject, array $payload): ActionResult`, не abstract base.
    `BulkAction`/`RowAction` его не имплементируют; интерфейс реализуют handler-классы,
    передаваемые в `->handler(string $actionClass)`.
  - `BulkAction`: `confirm(?string $text = null)` (Closure не поддерживается),
    `undoable(\Closure $capture, \Closure $reverse)` — два Closure, не один;
    `queueWhen(int $threshold)` — int, не Closure. Добавлен mutex `queue()` ↔ `using()`.
    Перечислены недостающие fluent-методы (`kind`, `confirmText`, `formRequest`, `slot`,
    `reloadAfterSubmit`, `tooltip`, `ability`).
  - `RowAction`: `link(string $name, string $label, \Closure $href)` — factory-метод,
    не fluent setter. Расписан полный набор fluent-методов (раньше шёл общим намёком
    на «то же, что BulkAction»).
  - `ActionResult`: убран несуществовавший параметр `array $flash = []`. Добавлен реальный
    `?int $requested = null` (6-й параметр). Переставлен порядок: `(affected, missing,
    message, denied, skipped, requested)`. Удалены несуществующие getter-методы
    `affected()/missing()/skipped()/denied()/message()/flash()` — всё доступно как
    `public readonly` properties (`$result->affected`). Единственный метод — `counts()`.
  - `ListResource`: убран override-point `policy(): ?string` (его нет в коде; policy
    декларируется на уровне action'а). Добавлены реально существующие override'ы
    `density()`, `layout()`, `filterGroupLabels()`, `filterGroupThreshold()`.
  - `ResourceTable`: уточнён список public-properties (убраны `total`/`prefs` —
    их нет; добавлены реально публичные `key`, `search`, `activeFilters`, `qb`,
    `savedViewCounts`, `effectiveColumns`, `perPage`, `emptyState`). `rows` —
    метод, не property. Список public-методов расписан.
  - `ResourceRegistry`: убран несуществующий `find(string $key): ?ListResource`,
    добавлены `all()` и `classes()`.
  - `Route` macros: имена параметров приведены к реальным (`$uri → $path`,
    `$controllerClass → $controller`).
  - `Form\Field`: `SelectField::enum` — без 4-го параметра `$labeler`;
    `SelectField::relation(string $name, string $label, \Closure $resolver)` —
    реальная сигнатура (раньше заявлена `(string $modelClass, ?string $labelColumn)`).
    Fluent-методы `FormField` пересмотрены: убраны несуществующие `placeholder`,
    `disabled`, `default`, `help`; добавлены реальные `value`, `valueFrom`, `helper`,
    `attrs`, `attribute`.
  - `SavedView`: фактические сигнатуры `color(?string)`, `icon(?string)` + добавлен
    `countWith(\Closure)`. `Operator::Empty` уточнён до `Operator::Empty_` (case с
    trailing underscore; `value === 'empty'`).
  - `FilterCondition`: удалено несуществующее свойство `not` — у VO только
    `field/operator/value`.
  - `Page\HeaderAction`/`Breadcrumb`: имена параметров приведены к коду
    (`$href → $url`), добавлены `HeaderAction::size`/`target`.
  - `Export\ExportRequest`: описание уточнено — это VO параметров CSV-export'а
    (filename/delimiter/encoding/columns), не «состояния списка».
  - `Blade public surface`: `tables::shell` — это view-файл, не x-компонент.
    `<x-tables.page>` принимает prop `:table`, не `:resource`; слоты в page
    компоненте: `summary` + набор `before*`/`after*` (не `header`/`empty-state`).
  - `JS events`: удалён `tables:filter-groups` — это localStorage-prefix, не event.
    Эмит-механизм для `tables:flash` уточнён (jQuery trigger).

  Это **не breaking change**: документация была неточной с момента freeze'а v0.1.0,
  пользователи, опиравшиеся на отсутствующие методы (`cellEdit`, `prefs`, `savedViewSave`,
  `Field::label`, `ActionResult::affected()` и т.п.), получали `BadMethodCallException`
  или `TypeError` при первом вызове и не могли построить на этом рабочий код. Публичный
  surface самого кода (`tables/src/`) не менялся; источник правды — код.

### Changed

- **`ListResource` декомпозиция.** Логика сборки `ResourceTable` извлечена в
  `Mercurio\Tables\Table\TableBuilder` (`@internal`, регистрируется как
  singleton). Применение search + saved-view + chip-фильтров + AST query
  builder'а переехало в `Mercurio\Tables\Filter\FilterPipeline`. Резолв
  сортировки — в `Mercurio\Tables\Table\SortResolver` (static helper).
  `ListResource::table()` и `exportState()` теперь — тонкие фасады поверх
  `app(TableBuilder::class)`. Публичный API не меняется: host-Resource'ы
  продолжают наследоваться от `ListResource`, сигнатуры `fields()` /
  `savedViews()` / `bulkActions()` / `rowActions()` / `findField()` /
  `filterOptions()` / `qbSchema()` / `astToArray()` остаются. Размер
  `ListResource.php` сократился с 833 до 551 LOC.
- Summary block теперь рендерится между subtitle и Saved Views (ранее — после Saved Views).
  Затрагивает только дефолтный shell; кастомные `page_head_component`-переопределения не
  затронуты. Добавлен безусловный wrapper `<div data-tables-summary>` (стабильная точка
  монтирования для будущего AJAX-обновления partial). Smoke-tested via Blade source
  review only — host-приложение в этой итерации локально не поднималось.
- AJAX-partial теперь рендерит и обновляет блок summary через стабильный wrapper
  `<div data-tables-summary>`. Раньше KPI-карточки оставались со значениями полного
  reload'а и не пересчитывались при фильтрации / сортировке / пагинации / переключении
  saved-view. Затрагивает host-проекты, использующие дефолтный `tables::partial`;
  кастомные переопределения partial-вьюхи не затронуты (swap пропускается no-op'ом,
  summary остаётся прежним). Smoke-tested via Blade + JS source review and syntax check
  only — host-приложение локально не поднималось.
- Engine-компонент `tables.page-head` (runtime fallback для `shell.page_head_component`,
  активируется у host'ов без published `config/tables.php` либо с `null` для этого
  ключа) больше не рендерит внутренний placeholder `<p data-tables-subtitle>`. Раньше
  при этом fallback'е в DOM присутствовало два элемента с атрибутом
  `data-tables-subtitle` (внешний из `shell.blade.php` + внутренний из page-head),
  и AJAX-swap subtitle обновлял только первый в tree-order. Теперь единственный
  placeholder для full-страницы живёт в `shell.blade.php` (снаружи page-head),
  для AJAX-фрагмента — в `partial.blade.php`. Из `@props` page-head'а удалены
  ключи `subtitle` и `subtitleAttr` — эти prop'ы не задокументированы в
  `docs/api.md` и не были частью публичного контракта. Host-проекты с published
  `config/tables.php` (где `page_head_component` указывает на собственный
  компонент без `data-tables-subtitle`) поведения не меняют. Smoke-tested via
  Blade source review only — host-приложение в этой итерации локально не
  поднималось.
- `ListResource::resolveAuditActor(?int $actorId): ?string` теперь имеет дефолтную
  реализацию вместо `return null`. Резолв идёт через Laravel auth-провайдер,
  привязанный к `config('tables.guard')`:
  `Auth::createUserProvider(config('auth.guards.{guard}.provider'))->retrieveById($actorId)`.
  Найденный `Authenticatable` форматируется новым protected hook'ом
  `formatAuditActor(?Authenticatable $user): ?string` (default —
  `data_get($user, 'name') ?? data_get($user, 'email') ?? null`). Раньше метод всегда
  возвращал `null`, и host-проекты в `mercurioplatform/` повторяли один и тот же
  `Model::find()` + `name ?? email` в каждом Resource'е (5 копий). Конфигурационные
  аномалии host'а (provider не найден в `auth.providers`, driver не зарегистрирован —
  `InvalidArgumentException` от `AuthManager::createUserProvider`, имя провайдера
  пустое) graceful'но логируются через `Log::warning` (три раздельные ветки:
  `tables.audit_actor.guard_provider_missing`, `tables.audit_actor.provider_driver_invalid`,
  `tables.audit_actor.provider_not_resolvable`); offcanvas всегда открывается, UI
  fallback'ится на `#$id`. Операционные ошибки (`QueryException` от `retrieveById`)
  пробрасываются — Blade-consumer (`resources/views/action-log.blade.php`) уже
  толерантен через `try/catch`. Включён per-instance memoization
  (`private array $resolvedAuditActors`) — экономит lookup'ы в пределах одной
  страницы offcanvas. Override-точки сохранены: для нестандартного lookup'а
  (`withTrashed`, мульти-источники) — переопределить `resolveAuditActor`; для
  смены формата (`first_name + last_name`, `display_name`) — переопределить
  `formatAuditActor`. Public-сигнатура `resolveAuditActor(?int $actorId): ?string`
  не меняется; добавление protected-hook'а `formatAuditActor` — non-breaking (см.
  `docs/api.md` invariant о добавлении новых методов). Записи
  `tables_action_log.actor_id` (`unsignedBigInteger nullable`) и behaviour
  `ActionAuthorizer::resolveAuditActorId()` не затрагиваются. Smoke-tested via
  [DevTools на host'е mercurioplatform + проверка negative-case через временно
  изменённый `auth.providers.driver` / tinker-fallback при недоступности host'а —
  статус актуализировать при выполнении Task 7].

### Added

- `Mercurio\Tables\ListResource::guard(): ?string` — override-точка для auth guard'а. Дефолт `null` = `config('tables.guard', 'web')`. Используется engine'ом во всех точках актора (policy/Gate, prefs, saved views, экспорт, audit). Позволяет держать на одной странице Resource'ы с разными guard'ами без подмены глобального конфига.
- `Mercurio\Tables\ListResource::effectiveGuard(): string` (final) — резолвит override или конфиг. Использовать в host-коде и кастомных интеграциях вместо чтения `config('tables.guard')`.
- Protected hook `Mercurio\Tables\ListResource::formatAuditActor(?Authenticatable $user): ?string`
  — override-точка для смены формата отображаемого имени актора в offcanvas
  «История» без копирования lookup-логики. Default возвращает
  `data_get($user, 'name') ?? data_get($user, 'email') ?? null`. Через `data_get` —
  потому что interface `Authenticatable` не определяет свойства `name`/`email`
  (PHPStan level 6 не пропустил бы прямой property-access).

### Fixed

- `docs/api.md`: запись `resolveAuditActor` синхронизирована с реальной сигнатурой
  кода (`(?int $actorId): ?string` вместо устаревшего
  `(\Illuminate\Http\Request $request): ?\Illuminate\Contracts\Auth\Authenticatable`).
  Остальные расхождения api.md ↔ код запланированы под отдельный milestone — полный
  синк закроется одной правкой.

## [0.1.0] — 2026-05-09

### Notes

- Initial public release. Extracted from `mercurioplatform` monorepo via `git subtree split` (полная история коммитов сохранена).
- Публичный API заморожен под v0.1.0 (см. [`docs/api.md`](docs/api.md)).

### Public API matrix v0.1.0

> Полная матрица с per-symbol stability statements и versioning policy: [`docs/api.md`](docs/api.md).

**Resource & lifecycle**

- `Mercurio\Tables\ListResource` — abstract base; override-points: `key()`, `query()`, `fields()`, `searchable()`, `savedViews()`, `bulkActions()`, `rowActions()`, `summary()`, `perPage()`, `defaultSort()`, `routeBaseName()`, `policy()`, `pageTitle()`, `browserTitle()`, `subtitle($total)`, `breadcrumbs()`, `headerActions()`, `emptyState()`, `flashKeys()`, `actionHistoryEnabled()`, `resolveAuditActor()`.
- `Mercurio\Tables\ResourceTable` — runtime descriptor собранной таблицы (передаётся в `<x-tables.shell>` / `<x-tables.page>`).
- `Mercurio\Tables\ResourceRegistry` — lookup ResourceClass по `key()`; используется sync-savedviews и Query Builder.
- `Mercurio\Tables\Concerns\HandlesResourceListing` — controller trait: `index()`, `bulkAction()`, `bulkActionForm()`, `rowAction()`, `rowActionForm()`, `actionLog()`, `actionLogUndo()`, `prefs()`, `savedView*()`, `export()`, `cellEdit()`, `actionProgress()`, `options()`.
- `Route::tablesResource(uri, controller)` — macro; регистрирует все JSON-эндпоинты на собственный контроллер с `HandlesResourceListing`.
- `Route::tablesPage(uri, ResourceClass)` — macro; декларативный путь, контроллер не нужен (используется встроенный `Mercurio\Tables\Http\GenericTablesController`).
- `Mercurio\Tables\Routing\PendingTablesResource` — fluent билдер, возвращаемый макросами (`->name(...)`, `->middleware(...)`, ...).

**Fields**

- `Mercurio\Tables\Field\Field` — abstract; fluent-методы: `make`, `label`, `sortable`, `searchable`, `align`, `width`, `mono`, `cellView`, `editable`, `editColumn`, `editOptions`, `editRules`, `editPolicy`, `editStep`, `editMin`, `editMax`, `filterable`, `filterOptions`, `onlyFilterable`.
- Конкретные: `TextField`, `TwoLineField`, `NumberField`, `MoneyField`, `DiscountedMoneyField`, `BooleanField`, `StatusField`, `BadgesField`, `ImageField`, `AvatarField`, `BelongsToField`, `BelongsToManyField`, `RelationCountField`, `RatingField`, `ProgressBarField`, `ConditionalColorField`, `JsonField`, `TagsField`, `DateField`.

**Form fields** (используются в `BulkAction::schema(...)` / `RowAction::schema(...)`)

- `Mercurio\Tables\Form\Field\FormField` — abstract.
- `Mercurio\Tables\Form\Field\FieldRow` — горизонтальная группа полей.
- Конкретные: `TextField`, `TextareaField`, `NumberField`, `SelectField` (с `::enum`, `::relation`, `::options`), `RadioGroupField`, `CheckboxField`, `PlaintextField`.

**Actions**

- `Mercurio\Tables\Action\Action` — общий abstract для bulk и row.
- `Mercurio\Tables\Action\BulkAction` — fluent: `make`, `instant`, `confirm`, `preview`, `schema`, `withValidator`, `prepareInput`, `transformValidated`, `handler`, `using`, `payload`, `policy`, `onSuccess`, `onError`, `icon`, `variant`, `undoable`, `queue`, `queueChunkSize`, `queueWhen`.
- `Mercurio\Tables\Action\RowAction` — fluent: `make`, `link`, `confirm`, `preview`, `schema`, `withValidator`, `prepareInput`, `transformValidated`, `using`, `policy`, `onSuccess`, `onError`, `icon`, `tooltip`, `variant`, `hideWhen`, `undoable`.
- `Mercurio\Tables\Action\ActionResult` — return-VO для handlers: `affected`, `missing`, `skipped`, `denied`, `message`, `flash`, `counts()`.
- `Mercurio\Tables\Jobs\BulkActionJob` — встроенный `ShouldQueue`-job для `->queue()` (FQCN кастомизируется через `tables.bulk_progress.job_class`).

**Saved views & filters**

- `Mercurio\Tables\View\SavedView` — value-object; статические конструкторы `all`, `scope`; fluent `default`, `position`, `color`, `icon`.
- `Mercurio\Tables\Filter\Operator` — enum поддерживаемых операторов (Eq, In, NotIn, Between, NotBetween, Gt, Gte, Lt, Lte, Like, NotLike, IsNull, IsNotNull, ...).
- `Mercurio\Tables\Filter\FilterCondition` — value-object одного условия.

**Page chrome**

- `Mercurio\Tables\Page\HeaderAction` — fluent: `make($label, $href)`, `icon`, `variant`, `attrs`.
- `Mercurio\Tables\Page\Breadcrumb` — `link($label, $href)`, `current($label)`.
- `Mercurio\Tables\Page\EmptyState` — fluent: `make($title)`, `description`, `icon`, `cta`.

**Summary**

- `Mercurio\Tables\Summary\Summary` — abstract.
- `Mercurio\Tables\Summary\KpiSummary`, `KpiCard`.
- `Mercurio\Tables\Summary\FunnelSummary`, `FunnelCard`.

**Models** (Eloquent — public по факту вынесения миграций тегом `tables-migrations`)

- `Mercurio\Tables\Models\SavedView`.
- `Mercurio\Tables\Models\UserTablePrefs`.
- `Mercurio\Tables\Models\ActionLog`.
- `Mercurio\Tables\Models\ActionProgress`.

**Services**

- `Mercurio\Tables\Services\SystemViewSyncer` — sync system-views в БД из объявлений Resource'ов (вызывается в `boot()`, ручной триггер — `tables:sync-saved-views`).
- `Mercurio\Tables\Services\SavedViewCountsCalculator`.
- `Mercurio\Tables\Services\UserSavedViewLoader`.
- `Mercurio\Tables\Services\ActionLogWriter`.
- `Mercurio\Tables\Prefs\UserPrefs`, `Mercurio\Tables\Prefs\UserPrefsResolver`.

**Export**

- `Mercurio\Tables\Export\ExportRequest` — VO текущего состояния списка.
- `Mercurio\Tables\Export\CsvStreamWriter`.
- `Mercurio\Tables\Export\ExportJobDispatcher` — interface для async fallback.

**Console**

- `php artisan tables:sync-saved-views` (`Mercurio\Tables\Console\SyncSavedViewsCommand`) — sync system-views из всех зарегистрированных Resource'ов.

**Config keys** (`config/tables.php`)

- `guard`, `route_prefix`, `default_per_page`, `partial_header`, `js_event_prefix`, `resources`.
- `autocomplete_limit`, `autocomplete_min_chars`, `autocomplete_debounce_ms`, `route_options_suffix`.
- `qb_max_payload_size`, `qb_max_depth`, `qb_max_atoms`, `qb_button_label`, `qb_offcanvas_width`.
- `sync_system_views`, `saved_view_color_palette`, `saved_view_icons`.
- `row_actions.{suffix, form_suffix}`, `bulk_actions.form_suffix`, `cell_edit.{suffix, embed_options_limit}`.
- `user_prefs.{per_page_options, density_options, popover_button_label, popover_button_icon}`.
- `export.{sync_limit, chunk_size, csv_delimiter, csv_enclosure, csv_escape, csv_bom, filename_prefix, ability, async_dispatcher, log_chunks, button_label, button_icon}`.
- `shell.{layout, page_head_component, flash_keys, title_suffix}`.
- `action_log.{enabled, subjects_id_limit, recent_limit, per_page, header_action_label, header_action_icon, payload_max_bytes, undo_window_minutes, undo_snapshot_max_bytes}`.
- `bulk_progress.{enabled, default_chunk_size, default_threshold, poll_interval_ms, poll_max_duration_ms, progress_ttl_minutes, max_affected_ids_for_cta, job_class, job_tries, job_timeout_seconds, tray_position}`.
- `tables.{saved_views, user_table_prefs, action_log, action_progress}` — overrides DB-таблиц движка.

**Blade public surface**

- `<x-tables.shell :table="$table"/>` — обёртка страницы (layout + breadcrumbs + page-head + flashes).
- `<x-tables.page :resource="$table">` со слотами `header`, `summary`, `empty-state`.
- `<x-tables.table-root :table="$table"/>` — fragment, который рендерится при AJAX-навигации (`X-Tables-Partial`).

**Publishable tags**

- `tables-config` — `config/tables.php`.
- `tables-migrations` — миграции `saved_views`, `user_table_prefs`, `action_log`, `action_progress`.
- `tables-views` — `resources/views/components/tables/*` + корневые view-файлы (escape-hatch для override вёрстки).
- `tables-assets` — `resources/{js,scss}` пакета.

**JS events** (prefix configurable через `tables.js_event_prefix`, default `tables`)

- `tables:rendered` — таблица перерендерена (после navigate / поиска / фильтра).
- `tables:navigate` — выполнен `pushState` с новым URL.
- `tables:total-changed` — изменился total-counter.
- `tables:flash` — flash-сообщение (status / warning / error) от engine.
- `tables:filter-groups` — обновлены группы фильтров (advanced QB).

### Internal (`@internal`)

> Помечены `@internal` PHPDoc-маркерами в коде (`packages/tables/src/`). Полное описание стабильности — [`docs/api.md`](docs/api.md). Прямое использование возможно, но не поддерживается: minor/patch-релизы могут менять сигнатуры без deprecation cycle.

- `Mercurio\Tables\Action\Helpers\{ActionAuthorizer, ActionPayloadResolver, ActionResponseBuilder}`.
- `Mercurio\Tables\Action\Handlers\{BulkActionHandler, RowActionHandler, ActionLogHandler}`.
- `Mercurio\Tables\Services\{UserViewHandler, CellUpdateHandler}`.
- `Mercurio\Tables\Export\ExportHandler`.
- `Mercurio\Tables\Filter\{FilterParser, FilterApplier}`.
- `Mercurio\Tables\Filter\Qb\{AtomCondition, AtomGroup, QueryBuilderApplier, QueryBuilderNormalizer, QueryBuilderParser}`.
- `Mercurio\Tables\Routing\PendingTablesResource`.
- `Mercurio\Tables\Http\GenericTablesController`.
- `Mercurio\Tables\Support\{Pluralizer, UserViewHref}`.

[Unreleased]: https://github.com/mercurioplatform/tables/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/mercurioplatform/tables/compare/v1.0.0...v1.1.0
