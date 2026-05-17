# Examples bundle — mercurioplatform/tables

> Каталог [`examples/`](../examples) в репозитории — это GitHub-only набор
> готовых demo-ресурсов, по одному на каждый Source-адаптер пакета. Он не
> публикуется в `vendor/`: `.gitattributes` и `composer.json#archive.exclude`
> исключают его из tarball'а на Packagist. Чтение и copy-paste — только через
> GitHub.

## Зачем

Пакет даёт пять Source-адаптеров (`EloquentSource`, `ArraySource`, `SqlSource`,
`HttpSource`, `FileSource` с CSV/JSONL-фабриками) и Summary-API (KPI / Funnel
карточки). Examples — это **референсная реализация** каждого: какой источник
указать в `source()`, как описать `fields()`, как настроить `savedViews()` и
`summary()`, какие `Capabilities` имеют смысл.

Используются три способа применения:

1. **Чтение в репо** — открыть файл на GitHub, разобраться, как устроен Source,
   и применить паттерн в своём `ListResource` без копирования.
2. **Copy-paste по одному** — скопировать конкретный пример в `app/Tables/Demo/`
   и сразу увидеть рабочую страницу.
3. **Bulk-install** — скопировать всё дерево `examples/` целиком (см.
   [`examples/README.md`](../examples/README.md) — Quick start).

## Состав

| Demo | Файл | Маршрут | Источник |
| --- | --- | --- | --- |
| EloquentSourceDemo | [`examples/resources/EloquentSourceDemo.php`](../examples/resources/EloquentSourceDemo.php) | `/admin/tables-demo/eloquent` | `App\Models\User` |
| ArraySourceDemo | [`examples/resources/ArraySourceDemo.php`](../examples/resources/ArraySourceDemo.php) | `/admin/tables-demo/array` | 32-строковый справочник inline |
| SqlSourceDemo | [`examples/resources/SqlSourceDemo.php`](../examples/resources/SqlSourceDemo.php) | `/admin/tables-demo/sql` | таблица `tables_demo_orders` |
| HttpSourceDemo | [`examples/resources/HttpSourceDemo.php`](../examples/resources/HttpSourceDemo.php) | `/admin/tables-demo/http` | JSONPlaceholder REST API |
| FileSourceCsvDemo | [`examples/resources/FileSourceCsvDemo.php`](../examples/resources/FileSourceCsvDemo.php) | `/admin/tables-demo/file-csv` | `currencies.csv` (32 строки) |
| FileSourceJsonlDemo | [`examples/resources/FileSourceJsonlDemo.php`](../examples/resources/FileSourceJsonlDemo.php) | `/admin/tables-demo/file-jsonl` | `instruments.jsonl` (32 объекта) |

## Детальный разбор

### EloquentSourceDemo

**Что показывает**

- Самый прямой путь подключения Source: `new EloquentSource(User::query(), $this)`.
  Pipeline сам прокидывает search/sort/filter в Eloquent-builder.
- `Operator::Empty_` и `Operator::NotEmpty` в `filterable` и `savedViews` для
  колонки `email_verified_at` — без необходимости писать кастомные `FilterApplier`.
- `summary()` собирается из четырёх `count()`-запросов в `User::query()` —
  паттерн «KPI = простой agg в построитель».
- `defaultSort('created_at', 'desc')` — типичный default для списков-журналов.

**Какие фичи пакета задействованы**

- [`EloquentSource`](sources.md#eloquentsource) — capabilities all-on по
  умолчанию (filter / sort / search / count / mutate / stream).
- [Saved views](api.md) — `SavedView::all()`, `SavedView::conditions(...)`.
- [Summary cards](summary-cards.md) — `KpiCard` (с `delta` / `deltaTone`) и
  `FunnelCard` (с привязкой к `viewKey`).
- [`Operator`-enum](api.md) — `Empty_` / `NotEmpty` для null-проверок,
  `Gt` для дат.

**Как адаптировать под свою модель**

Замените `User::query()` на свою (`Order::query()`, `Invoice::query()`). Если
модель использует non-default-connection — Eloquent сам подхватит её через
свойство `$connection`. Поля и saved views перепишите по своим колонкам.

### ArraySourceDemo

**Что показывает**

- In-memory pipeline без БД. Подходит для справочников из `config/`, lookup
  таблиц, demo-стендов.
- Полный набор фичей пакета **работает поверх `Collection`**: search / sort /
  chip-filters / Query Builder / saved views / pagination / export CSV.
- `summary()` со «статическими» цифрами — типичный паттерн для справочников,
  где число строк известно заранее.

**Какие фичи пакета задействованы**

- [`ArraySource`](sources.md#arraysource) — capabilities filter / sort /
  search / count / stream, `mutate = false`.
- `StatusField::kinds()` + `labels()` для рендеринга цветных бейджей.
- `FunnelCard` с `viewKey` — кликабельные карточки переключают saved view.

**Как адаптировать**

Замените `$rows` на `config('your-config.items')` или на результат вызова
`MyService::lookups()`. Если строк > 10 000 — рассмотрите `SqlSource` или
`FileSource::csv()` (грузится по требованию).

### SqlSourceDemo

**Что показывает**

- Read-only выборка через `SqlSource::for('tables_demo_orders')` — без
  Eloquent-модели на таблицу. SqlSource создаёт inline-модель самостоятельно.
- `summary()` использует прямые `DB::table('tables_demo_orders')->count()`
  агрегации — без вложения в Resource.
- Имя таблицы префиксировано `tables_demo_*` — bundle не конфликтует с
  возможной хост-таблицей `orders`.

**Какие фичи пакета задействованы**

- [`SqlSource`](sources.md#sqlsource) — capabilities filter / sort / search /
  count / stream, `mutate = false` (read-only по контракту).
- `MoneyField` — форматирование `decimal(12,2)` колонки `total`.
- Несколько saved views на одну таблицу с разными `FilterCondition`.

**Как адаптировать**

`SqlSource::for($tableName)` — это shortcut. Под капотом доступны:

- `SqlSource::for($table, connection: 'reporting')` — переключение на другую
  connection (ClickHouse / read-replica / отдельный analytics-coupling).
- `SqlSource::forBuilder(DB::table($table)->where(...), resource: $this)` — если
  нужен предварительный фильтр / join.

### HttpSourceDemo

**Что показывает**

- Источник — внешний REST API (JSONPlaceholder), но шаблон применим к любому
  http-эндпоинту: REST с offset/cursor, GraphQL, внутренний service mesh.
- `cacheTtlSeconds: 300` + `cachePrefix: 'demo.http.users'` — встроенный Cache
  wrap, чтобы не дёргать API на каждый ререндер.
- Закрытый `Capabilities` — filter / sort / search / cursor / mutate / stream
  выключены, потому что fetch-closure не транслирует Query. Это намеренный
  smoke-вариант. Для materialized-fetch'а (загрузить раз, дальше пользоваться
  in-memory pipeline) — см. [`sources.md` → HttpSource](sources.md#httpsource).
- В `summary()` — отдельный `Cache::remember()` со своим ключом, чтобы карточки
  переживали навигацию страниц.

**Какие фичи пакета задействованы**

- [`HttpSource::for($fetch, ...)`](sources.md#httpsource) — factory с
  callable-fetch и `Capabilities`.
- `Query` VO — fetch-closure получает search / conditions / sort и может
  превратить их в URL параметры, если нужно.
- Свой `summary()` поверх Cache — KPI цифр без перетыкания API.

**Как адаптировать**

Сделайте честный фильтрующий fetch: в `Query $query` есть `$query->search`,
`$query->conditions[]`, `$query->sortField`/`$query->sortDirection`,
`$query->savedViewKey`. Преобразуйте их в URL-параметры (`?q=...&filter[x]=...`)
и проставьте Capabilities filter/sort/search/count в `true` — UI начнёт
показывать pipeline-контролы.

### FileSourceCsvDemo / FileSourceJsonlDemo

**Что показывает**

- `FileSource::csv($path)` и `FileSource::jsonl($path)` — две фабрики поверх
  одного драйвера. Файл загружается materialized'ом при первом обращении и
  дальше работает по ArraySource-пайплайну.
- Strict-режим JSONL (default) бросает `LogicException` на битой строке —
  ловится в логах и сразу даёт сигнал «фикс выгрузки».
- Оба примера используют одинаковые savedViews/summary, чтобы наглядно показать:
  для пакета CSV и JSONL — это один и тот же материализованный источник, разный
  только parser.

**Какие фичи пакета задействованы**

- [`FileSource`](sources.md#filesource) — capabilities filter / sort / search /
  count / stream, `mutate = false`.
- [`export.md`](export.md) — CSV/JSONL fixtures можно экспортировать обратно
  через `?format=csv`, потому что pipeline тот же.

**Как адаптировать**

- Замените `storage_path('app/...')` на реальный путь к выгрузке (отчёты,
  ежедневный export из 1С / SAP / R&D-pipeline).
- Для CSV — `FileSource::csv($path, delimiter: ';', enclosure: '"')`, если
  файл не в RFC 4180.
- Для JSONL — `FileSource::jsonl($path, strict: false)` если допустимы битые
  строки (пакет залогирует и пропустит их).

## Decision-tree: какой Source выбрать?

```
            ┌─ Есть Eloquent-модель?
            │
            ├── ДА  ──→ EloquentSource (filter/sort/search/count/mutate/stream)
            │
            ├── НЕТ ─→ Данные in-memory (≤ 10 000)?
            │         │
            │         ├── ДА ─→ ArraySource
            │         │
            │         └── НЕТ ─→ Источник?
            │                    │
            │                    ├── SQL-таблица без модели  ─→ SqlSource
            │                    │
            │                    ├── Внешний REST/GraphQL     ─→ HttpSource
            │                    │
            │                    └── CSV / JSONL файл         ─→ FileSource
```

Подробности по каждому драйверу — [`sources.md`](sources.md).

## i18n / export

Все demo используют русские заголовки. Pipeline пакета знает обо
[`i18n.md`](i18n.md), и saved view label'ы / pageTitle / browserTitle
переводятся через стандартный Laravel `__()`-механизм если установить
`tables.lang.*` через [`vendor:publish --tag=tables-lang`](../README.md).

Export работает поверх любого Source с `stream = true` — добавьте `?format=csv`
к demo-URL'у, и пакет вернёт CSV-стрим. См. [`export.md`](export.md).

## См. также

- [`examples/README.md`](../examples/README.md) — короткий quick-start (copy-paste).
- [`sources.md`](sources.md) — полный справочник по Source-API.
- [`summary-cards.md`](summary-cards.md) — KPI / Funnel cards в деталях.
- [`api.md`](api.md) — `ListResource` API, Operator-enum, Source contract.
- [`export.md`](export.md) — экспорт CSV / XLSX.
- [`i18n.md`](i18n.md) — перевод заголовков и подсказок.
