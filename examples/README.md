# Tables — examples

GitHub-only набор готовых demo-ресурсов для пакета [`mercurioplatform/tables`](https://github.com/mercurioplatform/tables). Шесть `ListResource`-классов — по одному на каждый Source-адаптер пакета, плюс fixtures, route-snippet, migration и seeder.

> **Этот каталог не попадает в `vendor/` после `composer require`.** Он исключён через `.gitattributes` и `composer.json#archive.exclude`. Examples живут только в репозитории — читайте их на GitHub, копируйте нужное в свой проект.

## Что внутри

| Demo | Source | Fixtures | URL после установки |
| --- | --- | --- | --- |
| [`EloquentSourceDemo`](resources/EloquentSourceDemo.php) | `EloquentSource` поверх `App\Models\User` | таблица `users` (есть в любом fresh Laravel) | `/admin/tables-demo/eloquent` |
| [`ArraySourceDemo`](resources/ArraySourceDemo.php) | `ArraySource` поверх 32-строкового справочника | inline в файле | `/admin/tables-demo/array` |
| [`SqlSourceDemo`](resources/SqlSourceDemo.php) | `SqlSource::for('tables_demo_orders')` | миграция + сидер ниже | `/admin/tables-demo/sql` |
| [`HttpSourceDemo`](resources/HttpSourceDemo.php) | `HttpSource` поверх JSONPlaceholder | внешний API | `/admin/tables-demo/http` |
| [`FileSourceCsvDemo`](resources/FileSourceCsvDemo.php) | `FileSource::csv(...)` | `fixtures/currencies.csv` | `/admin/tables-demo/file-csv` |
| [`FileSourceJsonlDemo`](resources/FileSourceJsonlDemo.php) | `FileSource::jsonl(...)` | `fixtures/instruments.jsonl` | `/admin/tables-demo/file-jsonl` |

## Что показывает каждый пример

- **EloquentSourceDemo** — классический Builder-режим поверх стандартной модели `App\Models\User`. `savedViews` (Verified / Unverified / Recent), summary с KPI и FunnelCard, `defaultSort` по `created_at desc`. Запускается на fresh Laravel без дополнительных моделей.
- **ArraySourceDemo** — статический справочник из 32 валют/токенов/акций/индексов через `new ArraySource(collect($rows))`. Демонстрирует, что весь pipeline (search/sort/chip-filters/saved views/summary/pagination/export CSV) работает поверх обычной in-memory коллекции.
- **SqlSourceDemo** — read-only выборка через `SqlSource::for('tables_demo_orders')` поверх default-connection без Eloquent-модели. Таблица создаётся миграцией `tables_demo_orders` — она префиксирована и не конфликтует с возможной хост-таблицей `orders`. `summary` использует прямые `DB::table()` агрегации.
- **HttpSourceDemo** — внешний REST-источник (JSONPlaceholder `/users`), `cacheTtlSeconds: 300`, materialized-режим. `Capabilities` явно выключает filter/sort/search/cursor/mutate/stream — это смок-проверка fetch-closure без сложного пайплайна.
- **FileSourceCsvDemo** — `FileSource::csv(...)` поверх `currencies.csv` (32 строки). При первом обращении файл загружается в materialized-режим и дальше работает по ArraySource-пайплайну.
- **FileSourceJsonlDemo** — `FileSource::jsonl(...)` поверх `instruments.jsonl` (32 объекта). Strict-режим (default) бросает `LogicException` на битой JSON-строке — для smoke-демо это ок, файл валиден.

## Как использовать (copy-paste)

Скопируйте нужные файлы из этого каталога в ваш Laravel-проект. Карта путей:

```
examples/resources/*.php                        →  app/Tables/Demo/
examples/fixtures/currencies.csv                →  storage/app/tables-demo/currencies.csv
examples/fixtures/instruments.jsonl             →  storage/app/tables-demo/instruments.jsonl
examples/routes/tables-demo.php                 →  routes/tables-demo.php
examples/database/migrations/2026_05_17_*.php   →  database/migrations/
examples/database/seeders/DemoOrdersSeeder.php  →  database/seeders/
```

Если вы хотите только часть примеров — копируйте только нужные resource-файлы и связанные с ними fixtures/migrations/seeder. Файлы независимы друг от друга.

После копирования:

```bash
php artisan migrate
php artisan db:seed --class=DemoOrdersSeeder
```

И подключите routes-снiппет — добавьте в `routes/web.php`:

```php
require __DIR__.'/tables-demo.php';
```

Откройте `http://your-app.test/admin/tables-demo/eloquent` — должна отобразиться таблица пользователей. Остальные URL — из таблицы выше.

## Способы скачать целиком

Через `git clone`:

```bash
git clone --depth 1 https://github.com/mercurioplatform/tables.git /tmp/tables
cp -R /tmp/tables/examples/resources/*       app/Tables/Demo/
cp -R /tmp/tables/examples/fixtures/*        storage/app/tables-demo/
cp /tmp/tables/examples/routes/tables-demo.php routes/tables-demo.php
cp /tmp/tables/examples/database/migrations/* database/migrations/
cp /tmp/tables/examples/database/seeders/*    database/seeders/
```

Либо вручную — открыть [examples/ на GitHub](https://github.com/mercurioplatform/tables/tree/main/examples) и через «Raw → Save as» сохранить нужные файлы.

Либо архивом — GitHub → Code → Download ZIP, распаковать, перенести содержимое `examples/` в соответствующие каталоги проекта.

## Требования к хосту

- Laravel 13.x, PHP 8.3.
- Установленный пакет: `composer require mercurioplatform/tables`.
- Layout `app.blade.php` (стандартный из `laravel/breeze` или `laravel/jetstream` подойдёт — `Route::tablesPage()` рендерит свою страницу внутрь общего layout'а).
- Таблица `users` в БД (нужна только для `EloquentSourceDemo` — есть в любом fresh Laravel).
- Default DB-connection настроен.

**Никаких дополнительных моделей создавать не нужно.** Demo использует только стандартную `App\Models\User`.

Если хотите защитить demo-страницы за авторизацией — раскомментируйте альтернативный блок с `auth:<guard>` в [`routes/tables-demo.php`](routes/tables-demo.php).

## Где почитать подробнее

- [docs/examples.md](../docs/examples.md) — подробный разбор каждого demo, какие фичи задействованы, как адаптировать под свои данные, decision-tree «какой Source выбрать?».
- [docs/sources.md](../docs/sources.md) — справочник по Source-API в целом.
- [docs/summary-cards.md](../docs/summary-cards.md) — KPI/Funnel cards, sparkline, deltaTone.
- [README.md](../README.md) — главная страница пакета.
