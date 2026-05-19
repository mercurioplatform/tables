# JSON API — mercurioplatform/tables

> Опциональный JSON-эндпоинт поверх существующего `ListResource`-pipeline.
> Один и тот же ресурс отдаёт и HTML (`Route::tablesPage(...)`), и REST
> (`Route::tablesApi(...)`). Регистрация всегда **явная**: middleware
> наследуется от файла (`routes/api.php` → `api` stack, `routes/web.php` →
> `web` stack), implicit-публикации нет.

Поддерживается с версии `2.x`. Поверхность включает:

- read-эндпоинт `GET /{uri}` и его POST-форму (для произвольного QB-tree, не
  лезущего в URL) — см. «Quickstart» и «QB-tree»;
- self-описание ресурса через `?include=schema` и discovery-endpoint
  `GET /{uri}/schema` — см. «Schema discovery»;
- write-side (cell-edit / row-action / bulk-action) через
  `POST /{uri}/mutate` — см. «Mutations»;
- per-field format overrides через `?format[field]=mode` — см. «Format».

## Quickstart

```php
// routes/api.php
use Illuminate\Support\Facades\Route;
use App\Tables\OrdersResource;

Route::tablesApi('orders', OrdersResource::class)->name('orders');
```

```bash
curl -s "https://example.test/api/orders?per_page=2&format=both" | jq
```

```json
{
  "data": [
    { "id": 1, "number": { "raw": "A-1001", "display": "A-1001", "tone": null }, "total": { "raw": 1200, "display": "1200", "tone": null } }
  ],
  "page": { "mode": "offset", "per_page": 2, "current_page": 1, "total": 4, "next_url": "...", "prev_url": null }
}
```

## ApiConfig

Конфиг живёт на самом ресурсе через опциональный метод `api(): ApiConfig`.
Если метод не переопределён — `ApiConfig::make()` с sensible defaults:

| Wither | Default | Описание |
| --- | --- | --- |
| `allowFields([...])` | `null` → `fieldsMemo()` | whitelist для `?fields=` |
| `allowSavedViews([...])` | `null` → `savedViewsMemo()` keys | whitelist для `?savedView=` |
| `allowMutations(bool)` | `false` | read-only из коробки; `true` включает `POST /{uri}/mutate` |
| `defaultFormat(FormatMode)` | `FormatMode::Raw` | глобальный default для `?format=` |
| `defaultIncludes([...])` | `['data','page']` | блоки, которые возвращаются без `?include=` |
| `defaultPerPage(int)` | `25` | default для `?per_page=` |
| `maxPerPage(int)` | `200` | upper-bound для `?per_page=` |
| `rateLimit(?string)` | `null` | Laravel rate-limit string (host применяет своим middleware) |

Пример override на ресурсе:

```php
use Mercurio\Tables\Api\ApiConfig;
use Mercurio\Tables\Api\FormatMode;

public function api(): ApiConfig
{
    return ApiConfig::make()
        ->allowFields(['id', 'number', 'status', 'total'])
        ->allowSavedViews(['paid'])
        ->defaultFormat(FormatMode::Both)
        ->defaultPerPage(50)
        ->maxPerPage(200);
}
```

## URL-параметры

### `?include=...`

CSV блоков envelope'а. Известные значения: `data`, `page`, `summary`,
`savedViews`, `capabilities`, `schema`. `data` и `page` присутствуют всегда.
Блок `schema` публикует структуру ресурса (поля, saved views, capabilities) —
см. «Schema discovery».

```bash
curl "...?include=summary,savedViews,capabilities"
```

### `?fields=id,number`

Sparse-fieldsets. Поля валидируются по `ApiConfig::allowFields`. Неразрешённое
поле → 422 `VALIDATION_FAILED`.

### `?format=raw|formatted|both`

Глобальный режим сериализации значений.

- `raw` — scalar из Source (`data_get($row, $field->name)`).
- `formatted` — plain-text через `Field::exportValue()` (как в CSV-экспорте).
- `both` — `{ raw, display, tone }` (метаданные для UI-чипов).

### `?format[field]=mode` — per-field overrides

Типовой кейс: «большинство полей `raw`, money/status — `both`». Без per-field
overrides клиент вынужден либо запрашивать всё в `both` (объёмный payload), либо
делать второй round-trip — оба варианта противоречат целям JSON API.

Array-форма даёт overrides per field. Спец-ключ `*` задаёт base mode для
не-перечисленных полей; без него base = `ApiConfig::defaultFormat` (по умолчанию
`raw`). Override применяется только к полям, попавшим в выходной row (через
`?fields=` или `allowFields`); для остальных тихо игнорируется.

| Запрос | Эффект |
| --- | --- |
| `?format=both` | global=both, perField=[] (как раньше) |
| `?format[total]=both` | global=`defaultFormat`, perField=`{total:both}` |
| `?format[*]=both&format[total]=raw` | global=both, perField=`{total:raw}` |
| `?format[secret]=both` (поле не в `allowFields`) | 422 `VALIDATION_FAILED`, `details.field='secret'` |
| `?format[total]=xml` | 422 `VALIDATION_FAILED`, `details.format='xml'`, `details.field='total'` |

POST-эквивалент через JSON-типы — string vs object:

```json
{"format": "both"}                          // global=both, perField=[]
{"format": {"total": "both"}}               // global=defaultFormat, perField={total:both}
{"format": {"*": "both", "total": "raw"}}   // global=both, perField={total:raw}
```

### `?per_page=N` / `?page=N`

Размер страницы и номер страницы (1-based). `per_page` валидируется по диапазону
`[1, ApiConfig::maxPerPage]`. Cursor-режим работает прозрачно: если Source —
cursor-primary (например, `HttpSource`), envelope блок `page` отдаст
`mode: 'cursor'` и `next_url` / `prev_url` с cursor-токеном внутри URL.
Клиент следует за URL — явный `?cursor=`-параметр не вводится.

### `?sort=field` / `?sort=-field`

`-` префикс — desc. Whitelist полей через `allowFields`.

### `?q=...`

Fulltext-search. Передаётся в `Query::$search` и применяется
`Source::withQuery()`. Колонки для поиска приходят из `ListResource::searchable()`.

### `?savedView=key`

Применяет SavedView (`SavedView::conditions(...)`). Conditions из view
AND-мержатся с пришедшими `filter[..]`. Неразрешённый key → 422.

### `?filter[..]` — плоский AND-sugar

```
?filter[status]=paid                    # Eq
?filter[total][gte]=1000                # с явным operator'ом
?filter[status][in][]=paid&[in][]=pending   # массив
?filter[total][between][0]=100&[1]=500  # диапазон
?filter[verified_at][empty]=true        # без значения
?filter[verified_at][not_empty]=true
```

Маппинг operator-имён — напрямую `Operator::tryFrom($name)`: `eq`, `neq`,
`gt`, `gte`, `lt`, `lte`, `contains`, `not_contains`, `starts_with`,
`not_starts_with`, `ends_with`, `not_ends_with`, `in`, `not_in`, `between`,
`not_between`, `empty`, `not_empty`. Whitelist operator'ов — через
существующий `Field::filterable([Operator::...])`. Параллельных whitelist'ов нет.

Для полноценного дерева (OR-группы, NOT, вложенность) — см. секцию «QB-tree» ниже.

## QB-tree

Плоского `?filter[..]` (AND-sugar) хватает для типового READ, но для
выражений вида `status = paid AND (total > 1000 OR customer.tier = vip)`
нужна полноценная булевая алгебра. **QB-tree** использует то же
дерево, что и UI jQuery-QB-плагин (`tables/resources/js/tables/qb/{ast,serialization}.js`),
без дублирования спецификации фильтров между UI и API.

Tree принимается двумя путями:

- **POST с JSON body** на тот же URL — primary; нет 8 KB-лимита nginx;
  не shareable / не кэшируется.
- **GET `?qb=<base64-encoded JSON>`** — secondary; shareable URL (копипаст
  в slack/jira/docs) + cacheable; лимит `qb_max_payload_size` (default
  4096 байт) — для типовых деревьев на 5–10 атомов base64-payload
  ~300–500 байт.

POST + GET на одном URL — это **один логический ресурс (read), отвечающий
двумя verb'ами**, аналог `Route::resource()`. UI vs API остаются разделены
файлами маршрутов через отдельные макросы (`Route::tablesPage` vs
`Route::tablesApi`).

### POST на тот же путь

`Content-Type: application/json`. Body — словарь с теми же top-level
keys, что и GET query-string:

```http
POST /api/orders
Content-Type: application/json

{
  "qb":         { "type": "group", "op": "AND", "children": [...] },
  "filter":     { "status": "paid", "total": { "gte": 1000 } },
  "sort":       "-created_at",
  "q":          "alice",
  "savedView":  "paid",
  "page":       2,
  "per_page":   25,
  "include":    ["summary","savedViews"],
  "fields":     ["id","number","total"],
  "format":     "both"
}
```

Любой ключ опционален; отсутствующий = default из `ApiConfig`.

```bash
curl -s -X POST 'https://example.test/api/orders' \
  -H 'Content-Type: application/json' \
  --data-binary @payload.json | jq
```

POST без `application/json` Content-Type → 400 `MALFORMED_QUERY` c
`details.reason = "unsupported_media_type"` (страховка от form-POST,
который иначе дал бы 200 с пустым `query()`).

### GET `?qb=<base64>`

JSON-дерево base64-кодируется и кладётся в query-параметр `qb`:

```bash
QB=$(jq -cn '{type:"group", op:"OR", children:[
  {type:"cond", field:"status", operator:"eq", value:"paid"},
  {type:"cond", field:"status", operator:"eq", value:"pending"}
]}' | base64)

curl -s "https://example.test/api/orders?qb=${QB}&per_page=10" | jq
```

POST с `qb` в body **И** `?qb=` в query одновременно → 400
`MALFORMED_QUERY` с `details.reason = "qb_specified_twice"`. Дерево
должно прийти ровно по одному каналу.

### Tree-формат

Два типа нодов:

**Atom** — лист дерева, одно условие:

```json
{ "type": "cond", "field": "<name>", "operator": "<op>", "value": <mixed>, "not": false }
```

- `field` — машинное имя поля из `ListResource::fields()`; должно
  присутствовать в `ApiConfig::allowFields` и иметь
  `Field::filterable([...])`.
- `operator` — snake_case `Operator` enum (`eq`, `neq`, `gt`, `gte`, `lt`,
  `lte`, `in`, `not_in`, `between`, `not_between`, `empty`, `not_empty`,
  `contains`, `not_contains`, `starts_with`, `not_starts_with`,
  `ends_with`, `not_ends_with`); должен входить в
  `Field::getFilterableOperators()`.
- `value` — scalar для большинства операторов; массив для `in`/`not_in`;
  `[min, max]` или `{min, max}` для `between`/`not_between`; `null` для
  `empty`/`not_empty`.
- `not` — опциональный bool; инвертирует условие.

**Group** — внутренний узел, объединяющий children через AND/OR:

```json
{ "type": "group", "op": "AND" | "OR", "not": false, "children": [<Node>, ...] }
```

- `op` — `AND` или `OR`; других значений нет (`NAND` etc → 400
  `MALFORMED_QUERY` `kind=invalid_op`).
- `not` — опциональный bool; инвертирует группу целиком.
- `children` — массив `cond`/`group`-нодов; глубина и количество атомов
  ограничены `tables.qb_max_depth` (default 5) и `tables.qb_max_atoms`
  (default 100). Превышение → 400 `MALFORMED_QUERY` с
  `kind=depth_exceeded` / `kind=atoms_exceeded`.

Tree-формат **байт-в-байт совпадает** с UI QB-плагином. Один и тот же
PHP-парсер (`QueryBuilderParser::parse()` / `::parseArray()`) валидирует
оба источника — серверной разводки UI/API нет.

### Merge правила `savedView ∧ ?filter[..] ∧ qb`

Все три источника сливаются в один AND-root:

- **`qb` отсутствует** — плоский `?filter[..]` и savedView-conditions
  остаются в `Query::$conditions`.
- **`qb` присутствует, root = `AND` без `not`** — `children` пополняются
  доп. `AtomCondition`-ами, конвертированными из flat-filters и
  savedView (через `AtomCondition::fromFilter()`). Итог — расширенный
  AND-root.
- **`qb` присутствует, root = `OR` или `not = true`** — root оборачивается
  в новый AND-узел: `AND([<root>, ...flat, ...savedView])`. Это сохраняет
  семантику «top-level OR/NOT» как одного узла и AND-merge'ит сверху.

Пример. Tree = `(status=paid OR status=pending)`, `?savedView=paid`
(добавляет `status=paid`), `?filter[total][gte]=1500` → итог:

```text
AND([
  OR([cond(status=paid), cond(status=pending)]),
  cond(status=paid),
  cond(total>=1500),
])
```

В fixture `TestOrdersResource` это даёт ровно одну строку
(`id=3, status=paid, total=1500`).

### Capability `qbTree`

Новая capability в `Source::capabilities()`:

| Source-драйвер | `qbTree` | Комментарий |
| --- | --- | --- |
| `EloquentSource` | `true` | `QueryBuilderApplier` транслирует дерево в Builder. |
| `ArraySource` | `true` | `AtomEvaluator` исполняет дерево in-memory. |
| `SqlSource` | `true` | Через `DB::connection()` + `QueryBuilderApplier`. |
| `FileSource` | `true` (materialized) | В lazy-режиме `qbRoot` пропускается с WARN. |
| `HttpSource` | `false` | Внешний REST не понимает дерева; молча null'ифицирует. |

Контроллер проверяет capability **до** вызова `Source::page()`: при
`qbRoot !== null && capabilities.qbTree === false` → 422
`CAPABILITY_UNSUPPORTED` c `details.capability = "qbTree"`. Это
предотвращает silent-200 с НЕ-применённым деревом.

Если хосту нужно полностью отключить QB-tree для конкретного ресурса
(даже если Source умеет) — оберните его в обычный `withCapabilities(...)`
со своим `Capabilities` VO с `qbTree: false`.

### Лимиты (`config/tables.php`)

| Ключ | Default | Назначение |
| --- | --- | --- |
| `tables.qb_max_depth` | `5` | Макс. глубина вложенности групп. |
| `tables.qb_max_atoms` | `100` | Макс. число cond-нодов в дереве. |
| `tables.qb_max_payload_size` | `4096` | Лимит base64-payload до декода (только для GET `?qb=`). |

Превышение лимита в strict-mode → 400 `MALFORMED_QUERY` с
соответствующим `details.kind`.

## Envelope

```json
{
  "data":         [...],
  "page":         { "mode": "offset|cursor", "per_page": N, ... },
  "summary":      [...],
  "savedViews":   [...],
  "capabilities": { "filter": true, "sort": true, ... }
}
```

Опциональные блоки отдаются только при наличии в `?include=`. `data`/`page`
есть всегда.

### `page` блок

`offset`-режим: `{ mode, per_page, count, current_page, total, first_item, last_item, next_url, prev_url }`.
`cursor`-режим: `{ mode, per_page, count, next_url, prev_url }` (без `total`).

### `summary` блок

Массив сериализованных карточек. KPI: `{ type:"kpi", title, value, suffix, delta, delta_tone, sparkline, sparkline_filled }`.
Funnel: `{ type:"funnel", label, value, view_key, kind, delta }`. Кастомные
карточки `SummaryCard` без поддержки → `{ type: "unknown", class }`.

### `savedViews` блок

Отфильтрован по `ApiConfig::allowSavedViews`. Каждый: `{ key, label, default,
color, icon, position, conditions: [{field, operator, value}, ...] }`.

### `capabilities` блок

`Capabilities::toArray()` — `{ filter, sort, search, count, cursor, mutate, stream }`
(все bool).

## Schema discovery

Self-describing API: клиент получает структуру ресурса (типы полей, операторы,
enum-значения, format-hints, saved views, capabilities) без хардкода. Один
источник правды — сервис `SchemaBuilder`, две проекции:

- **Inline** — `GET /{uri}?include=schema` добавляет блок `schema` в обычный
  envelope (`data` + `page` остаются). Используется для bootstrap'а SPA: один
  round-trip отдаёт и первую страницу, и описание ресурса.
- **Discovery-endpoint** — `GET /{uri}/schema` возвращает ровно
  `{schema: {...}}`, без `data`/`page`. Используется для генераторов клиентов
  (Postman/Insomnia/Bruno, React-admin/Retool, будущий `tables:openapi`).

Оба пути дают **байт-в-байт одинаковый** блок `schema`.

```bash
# inline (для SPA bootstrap)
curl "https://example.test/api/orders?include=schema&per_page=10" | jq

# discovery (для генераторов клиентов)
curl "https://example.test/api/orders/schema" | jq
```

```json
{
  "schema": {
    "resource": { "key": "catalog.orders" },
    "fields": {
      "id":      { "name": "id",      "label": "#",       "type": "text",    "sortable": true,  "filterable": false, "operators": [],  "values": null,    "format_hints": {},                                                                              "editable": false, "hidden": false, "align": "left",  "searchable": false, "cell_view": null, "filter_popover": "text", "qb_value_type": "text" },
      "status":  { "name": "status",  "label": "Статус",  "type": "status",  "sortable": true,  "filterable": true,  "operators": [{"value":"eq","label":"Равно"},{"value":"in","label":"В списке"}], "values": [{"value":"paid","label":"Оплачен"},{"value":"pending","label":"Ожидание"}], "format_hints": { "kinds": {"paid":"success"}, "labels": {"paid":"Оплачен"}, "default_kind": "secondary", "use_dot": true, "kind_using_closure": false, "label_using_closure": false }, "editable": false, "hidden": false, "align": "left",  "searchable": false, "cell_view": null, "filter_popover": "select", "qb_value_type": "select" },
      "total":   { "name": "total",   "label": "Сумма",   "type": "money",   "sortable": true,  "filterable": true,  "operators": [{"value":"gte","label":"Больше или равно"}], "values": null, "format_hints": { "currency": "₽", "divisor": 100, "position": "after", "decimals": 2, "decimal_separator": ",", "thousands_separator": " ", "edit_min": null, "edit_max": null, "edit_step": null }, "editable": false, "hidden": false, "align": "right", "searchable": false, "cell_view": null, "filter_popover": "range", "qb_value_type": "number" }
    },
    "savedViews": {
      "all":  { "key": "all",  "label": "Все",      "default": true,  "color": null,      "icon": null,    "position": null, "conditions": [] },
      "paid": { "key": "paid", "label": "Оплачен",  "default": false, "color": "success", "icon": "check", "position": 1,    "conditions": [ {"field":"status","operator":"eq","value":"paid"} ] }
    },
    "capabilities": { "filter": true, "sort": true, "search": true, "count": true, "cursor": false, "mutate": false, "stream": true, "qbTree": true }
  }
}
```

### Структура блока `schema`

| Подблок | Тип | Описание |
| --- | --- | --- |
| `resource.key` | string | `$resource->key()` — уникальный идентификатор ресурса в host-приложении. |
| `fields` | object | Map `<field-name> → field-описание`. Ключи — `Field::$name`. |
| `savedViews` | object | Map `<view-key> → saved-view-описание`. |
| `capabilities` | object | Прямая копия `Source::capabilities()->toArray()`. Идентичен inline-блоку `capabilities`. |

### Field-описание

| Ключ | Тип | Описание |
| --- | --- | --- |
| `name` | string | Машинное имя поля (`Field::$name`). |
| `label` | string | UI-метка. |
| `type` | string | Kebab-case identifier — см. таблицу типов ниже. |
| `sortable` | bool | `Field::isSortable()`. |
| `searchable` | bool | Поле присутствует в `ListResource::searchable()`. |
| `filterable` | bool | `Field::isFilterable()`. |
| `hidden` | bool | `Field::isHidden()` (`hideByDefault(true)`). |
| `align` | string | `'left' \| 'right' \| 'center'`. |
| `cell_view` | string\|null | Путь до Blade-шаблона ячейки, если задан. |
| `operators` | `[{value,label}]` | Список разрешённых операторов; `label` — русская локализованная строка из `Field::operatorLabel()`. Пуст, если `filterable=false`. |
| `values` | `[{value,label}] \| null` | Enum-значения для select/status-полей; `null` для произвольного ввода. |
| `format_hints` | object | Per-type метаданные форматирования (см. ниже). |
| `filter_popover` | string | `Field::getFilterPopoverType()` — `'text' \| 'select' \| 'range' \| 'daterange' \| 'autocomplete'`. |
| `qb_value_type` | string | `Field::getQbValueType()` — тип значения для QB-плагина. |
| `editable` | bool | `Field::isEditable()` — поле декларативно editable на ресурсе. Это **не** означает, что mutate-API открыт (см. `capabilities.mutate`). |

### Таблица `type`-значений

| `type` | PHP-класс | Применение |
| --- | --- | --- |
| `text` | `TextField` (и базовый default) | произвольная строка |
| `number` | `NumberField` | число |
| `money` | `MoneyField` | денежная сумма (с валютой/делителем) |
| `discounted_money` | `DiscountedMoneyField` | цена со скидкой |
| `date` | `DateField` | дата/время |
| `boolean` | `BooleanField` | флаг да/нет |
| `status` | `StatusField` | enum-статус с цветовой раскраской |
| `belongs_to` | `BelongsToField` | связь many-to-one |
| `belongs_to_many` | `BelongsToManyField` | связь many-to-many |
| `json` | `JsonField` | JSON-полезная нагрузка |
| `progress_bar` | `ProgressBarField` | прогресс-бар |
| `rating` | `RatingField` | рейтинг (звёзды/полоса) |
| `tags` | `TagsField` | теги (chip-list) |
| `badges` | `BadgesField` | бейджи (closure-based) |
| `image` | `ImageField` | изображение |
| `avatar` | `AvatarField` | аватарка с именем/email |
| `relation_count` | `RelationCountField` | счётчик связанных записей |
| `conditional_color` | `ConditionalColorField` | число с условной раскраской |
| `two_line` | `TwoLineField` | двухстрочная ячейка |

Host может добавить свой Field-подкласс с собственным `schemaType()` —
identifier стабилен между релизами (не зависит от PHP-namespace).

### `format_hints` per type

`format_hints` — opaque map с per-type параметрами рендеринга. Структура
зафиксирована per type:

| `type` | Ключи `format_hints` |
| --- | --- |
| `text` | `{}` |
| `number` | `{decimals, decimal_separator, thousands_separator, edit_min, edit_max, edit_step}` |
| `money` | (number) + `{currency, divisor, position}` |
| `discounted_money` | (money) + `{show_percentage, compare_using_closure}` |
| `date` | `{mode, format}` (`mode = 'relative' \| 'absolute'`) |
| `boolean` | `{true_label, false_label, true_kind, false_kind, use_dot}` |
| `status` | `{kinds, labels, default_kind, use_dot, kind_using_closure, label_using_closure}` |
| `belongs_to` | `{relation, display_key, foreign_key}` |
| `belongs_to_many` | `{relation, display_key, related_key, preview_limit}` |
| `json` | `{pretty, max_length, expandable}` |
| `progress_bar` | (number) + `{capacity, low_threshold, high_threshold, bar_width, with_value, color_using_closure}` |
| `rating` | `{max, precision, style, show_value, empty_as_dash, color_using_closure}` |
| `tags` | `{relation, display_key, limit, variant, subtle, add_label, using_closure, kind_using_closure, add_action_closure}` |
| `badges` | `{subtle, gap, using_closure}` |
| `image` | `{size, shape, placeholder_icon, url_using_closure, placeholder_using_closure}` |
| `avatar` | `{size, initials_using_closure, name_using_closure, email_using_closure}` |
| `relation_count` | (number) + `{plural, with_label, icon_before, empty_as_dash}` |
| `conditional_color` | (number) + `{color_using_closure}` |
| `two_line` | `{sub_mono, empty_text, main_using_closure, sub_using_closure}` |

Closure-свойства (`displayUsing`, `colorUsing`, ...) намеренно не сериализуются —
вместо значения публикуется presence-флаг `*_using_closure: bool`, чтобы клиент
понимал, что рендеринг определяется host-callback'ом.

### `operators`

Список `{value, label}` пар. `value` — снейк-кейс из `Operator` enum (`eq`,
`neq`, `gt`, `gte`, `lt`, `lte`, `in`, `not_in`, `between`, `not_between`,
`empty`, `not_empty`, `contains`, `not_contains`, `starts_with`,
`not_starts_with`, `ends_with`, `not_ends_with`). `label` — русская строка
из `Field::operatorLabel()`.

### `values` для select/status

Когда поле имеет конечный набор значений (`StatusField::labels(...)`,
`Field::filterOptions([...])`), `values` — массив `[{value, label}]`. Для
полей с произвольным вводом (текст, число, дата) — `null`. Различие
«пустой массив vs null» — намеренное: `[]` означает «есть enum, но он пуст»,
`null` — «enum не объявлен».

### `savedViews`

Каждая view: `{key, label, default, color, icon, position, conditions}`.
`conditions` — список `{field, operator, value}` (`operator` — `Operator`-value
снейк-кейс). Список фильтруется по `ApiConfig::allowSavedViews`.

### `capabilities`

Прямая копия `Source::capabilities()->toArray()` —
`{filter, sort, search, count, cursor, mutate, stream, qbTree}`. См. секцию
«Capabilities-gating» для семантики флагов.

### Security: whitelist через `allowFields` / `allowSavedViews`

Schema публикует только поля и saved views, разрешённые через `ApiConfig`.
Скрытые из API поля их метаданные не утекают — клиент не узнаёт об их
существовании. То же для saved views (`'hidden'` view из `savedViews()`,
не включённая в `allowSavedViews`, не появится в schema).

`capabilities` публикуется как есть — это runtime-флаги Source'а, не
sensitive-информация.

## Mutations

JSON-API поддерживает write-side операции (cell-edit / row-action / bulk-action)
через единый эндпоинт `POST /{uri}/mutate` с body-дискриминатором `op`.

### Включение

Mutate-эндпоинт публикуется маршрутом всегда (так же, как `/{uri}` и
`/{uri}/schema`), но gate'ится на уровне контроллера через `ApiConfig::allowMutations(true)`:

```php
public function api(): ApiConfig
{
    return ApiConfig::make()
        ->allowMutations(true)        // включает /{uri}/mutate
        ->maxBulkIds(2000);           // лимит ids в bulk-payload (default: 1000)
}
```

Без `allowMutations(true)` любой `POST /{uri}/mutate` возвращает **403** `MUTATIONS_DISABLED`.

### Endpoint

`POST /{uri}/mutate` — `Content-Type: application/json` (form-urlencoded поддерживается
fallback'ом). Discriminator — поле `op` в теле: `"cell" | "row" | "bulk"`.

### Cell-edit (`op: "cell"`)

Inline-редактирование одной ячейки. Поле должно быть в `ApiConfig::allowFields(...)`
(по умолчанию = все `fields()`) и помечено `Field::editable()`.

```http
POST /api/orders/mutate
Content-Type: application/json

{ "op": "cell", "id": 42, "field": "status", "value": "paid" }
```

Response 200:

```json
{ "data": { "id": 42, "row": { "id": 42, "status": "paid", ... } } }
```

### Row-action (`op: "row"`)

Выполнение зарегистрированного `RowAction::make(name)` на одной записи.
Link-kind actions (без `->instant()`/`->confirm()`/`->form()`) не поддерживаются.

```http
POST /api/orders/mutate
Content-Type: application/json

{ "op": "row", "id": 42, "action": "approve", "payload": { "note": "ok" } }
```

Response 200:

```json
{ "data": { "id": 42, "affected": 1, "message": "approved" } }
```

### Bulk-action (`op: "bulk"`)

Bulk-action sync или queued (зависит от `->queueWhen()` + threshold).

```http
POST /api/orders/mutate
Content-Type: application/json

{ "op": "bulk", "action": "delete", "ids": [1, 2, 3], "payload": { "reason": "duplicate" } }
```

Sync response 200:

```json
{ "data": { "affected": 3, "denied": 0, "missing": 0, "skipped": 0, "affected_ids": [] } }
```

Queued response **202**:

```json
{
  "data": {
    "status": "queued",
    "progress_id": "...",
    "progress_url": "/admin/.../action-progress/...",
    "total": 3,
    "action_label": "Delete"
  }
}
```

`progress_url` — точка polling'a `ActionProgress` (тот же контракт что в UI).
Если HTML-routes ресурса не зарегистрированы (`routeBaseName() === null`) —
`progress_url` будет `null`.

### Undo probe (`?include=undoToken`)

Для undoable-action'ов передача `?include=undoToken` в query-string возвращает
дополнительный блок `undo`:

```json
{
  "data": { ... },
  "undo": { "token": 17, "kind": "row", "action": "approve" }
}
```

`token` — ID записи в `tables_action_log`. Endpoint invoke-undo
(`POST /{uri}/undo` или эквивалент) — out of scope, планируется отдельно.
**Cell-edit пока НЕ undoable** — `CellUpdateHandler` не пишет в action_log,
блок `undo` для cell-op никогда не рендерится.

### Whitelist и capabilities

- Cell-op уважает `ApiConfig::allowFields(...)` — если поле скрыто из read-API,
  его и редактировать нельзя (422 `VALIDATION_FAILED` с `details.reason: field_not_allowed`).
- Cell-op требует `Field::editable()` — иначе 422 `CAPABILITY_UNSUPPORTED`.
- Все три op'а требуют `Source::capabilities()->mutate === true` — иначе 422
  `CAPABILITY_UNSUPPORTED`.
- Row/bulk action'ы уважают `Action::policy()` / `->ability()` — failed authz
  → 403 `POLICY_DENIED`.

### Error table

| HTTP | code | когда |
|---|---|---|
| 403 | `MUTATIONS_DISABLED` | `ApiConfig::allowMutations(false)` |
| 403 | `POLICY_DENIED` | policy ресурса/action'а отказал |
| 404 | `RECORD_NOT_FOUND` | id не найден |
| 404 | `ACTION_NOT_FOUND` | row/bulk action не зарегистрирован |
| 422 | `VALIDATION_FAILED` | невалидный shape body (missing_op, missing_field, too_many_ids, ...); validator-fail |
| 422 | `CAPABILITY_UNSUPPORTED` | Source.mutate=false или поле !editable |
| 500 | `MUTATION_FAILED` | unexpected Throwable из handler/callback'а |

### Безопасность mutate-эндпоинта

Route публикуется всегда — для read-only сред оставляйте `allowMutations(false)`
(default). Для write-сред — оборачивайте в `auth`/`auth.sanctum` middleware на
уровне `routes/api.php`:

```php
Route::middleware(['auth:sanctum', 'throttle:api'])
    ->prefix('v1')
    ->group(function () {
        Route::tablesApi('orders', OrdersResource::class)->name('orders');
    });
```

### Что не входит

- actual undo invocation endpoint;
- cell-edit undoable (`CellUpdateHandler` не пишет в action_log);
- multi-cell update в одном request'е;
- CRUD-style verbs (`PATCH /{uri}/{id}`).

## Error envelope

```json
{ "error": { "code": "VALIDATION_FAILED", "message": "...", "details": {...} } }
```

| HTTP | code | `details.kind` / `reason` | когда |
| --- | --- | --- | --- |
| 400 | `MALFORMED_QUERY` | `malformed_base64` | GET `?qb=` содержит невалидный base64 |
| 400 | `MALFORMED_QUERY` | `malformed_json` | base64 декодируется, но контент не JSON |
| 400 | `MALFORMED_QUERY` | `payload_too_large` | GET `?qb=` payload > `qb_max_payload_size` |
| 400 | `MALFORMED_QUERY` | `unknown_node_type` | в дереве `type` не `cond`/`group` |
| 400 | `MALFORMED_QUERY` | `invalid_op` | `group.op` не `AND`/`OR` |
| 400 | `MALFORMED_QUERY` | `depth_exceeded` | глубина > `qb_max_depth` |
| 400 | `MALFORMED_QUERY` | `atoms_exceeded` | атомов > `qb_max_atoms` |
| 400 | `MALFORMED_QUERY` | `unsupported_media_type` | POST без `application/json` Content-Type |
| 400 | `MALFORMED_QUERY` | `qb_specified_twice` | POST с `qb` в body **и** `?qb=` в query |
| 422 | `VALIDATION_FAILED` | `unknown_field` (qb) | поле в qb-дереве не в whitelist или не filterable |
| 422 | `VALIDATION_FAILED` | `operator_not_allowed` (qb) | operator не в `Field::getFilterableOperators()` |
| 422 | `VALIDATION_FAILED` | `empty_value` / `value_not_normalizable` (qb) | значение в atom'е не нормализуется |
| 422 | `VALIDATION_FAILED` | — | поле/operator/savedView не в whitelist; `per_page` вне диапазона; неверный `format`/`include` |
| 422 | `VALIDATION_FAILED` | — | per-field `?format[field]=mode`: `field` не в `allowFields` или `mode` не в `FormatMode` |
| 422 | `CAPABILITY_UNSUPPORTED` | `capability=qbTree` | Source без `qbTree` получил qb-tree |
| 422 | `CAPABILITY_UNSUPPORTED` | `capability=filter/sort/search` | Source без соответствующей capability получил запрос |
| 403 | `MUTATIONS_DISABLED` | — | POST на read-only ресурс (`allowMutations=false`) |
| 404 | `RESOURCE_NOT_FOUND` | — | resource-class не `ListResource` (включая discovery-endpoint `/{uri}/schema`) |
| 500 | `SOURCE_ERROR` | — | Source бросил исключение; для discovery-endpoint'а также catch-all для ошибок `SchemaBuilder` |

## Capabilities-gating

Перед `$source->page(...)` контроллер проверяет правила:

1. `qb-tree` (POST body.qb или GET `?qb=`) → требует `capabilities.qbTree === true`.
2. `?filter[..]` → требует `capabilities.filter === true`.
3. `?sort=` → требует `capabilities.sort === true`.
4. `?q=` → требует `capabilities.search === true`.
5. `?savedView=` → требует `capabilities.filter === true` (saved view conditions раскрываются как filter'ы; при qb-tree-режиме они мерджатся в дерево и `filter`-gate срабатывает только если есть savedView, а qbTree=false).

Нарушение → 422 `CAPABILITY_UNSUPPORTED` с `details.capability`. Тот же
паттерн, что в UI v2.0 — никаких параллельных списков.

## Безопасность

- API из коробки **read-only**. Mutate активируется явным `ApiConfig::allowMutations(true)`.
- Регистрация `Route::tablesApi(...)` — отдельный макрос. Никакой implicit-публикации параллельно `Route::tablesPage(...)`.
- Middleware наследуется от файла маршрутов. Для public API под Sanctum:

  ```php
  // routes/api.php
  Route::middleware(['auth:sanctum'])
      ->prefix('v1')
      ->group(function () {
          Route::tablesApi('orders', OrdersResource::class)->name('orders');
      });
  ```

- Для internal AJAX под session-auth — тот же макрос в `routes/web.php`.

## См. также

- [`sources.md`](sources.md) — Source-драйверы и Capabilities.
- [`api.md`](api.md) — Source-contract (`withQuery`, `page`, `stream`).
- [`summary-cards.md`](summary-cards.md) — KPI / Funnel карточки.
