# Custom Summary cards

Summary-секция Blade-компонента `<x-tables.page>` рендерит произвольный набор «карточек» над таблицей. Пакет поставляет две встроенные карточки — `KpiCard` (значение + delta + sparkline) и `FunnelCard` (этапы воронки) — host может добавить свои без публикации Blade-views пакета.

## Архитектура

`Mercurio\Tables\Summary\Summary` — объект-обёртка вокруг массива карточек. Возвращается из `ListResource::summary($qb)`. Каждая карточка — потомок абстракции `Mercurio\Tables\Summary\SummaryCard`:

```php
abstract class SummaryCard
{
    abstract public function cellView(): string;
}
```

`cellView()` возвращает имя Blade-компонента (анонимного или class-based), которому в скоупе передаётся `$card`. Рендер делает `<x-tables.summary>` (см. `tables/resources/views/components/tables/summary.blade.php`) через `Blade::render('<x-dynamic-component :component="$cardView" :card="$card"/>', …)`.

## Минимальный кастомный card

### 1. Создать класс

```php
namespace App\Tables\Summary;

use Mercurio\Tables\Summary\SummaryCard;

final class ChartCard extends SummaryCard
{
    /** @param array<int, int|float> $points */
    public function __construct(
        public readonly string $title,
        public readonly array $points,
    ) {}

    public function cellView(): string
    {
        return 'app-tables::summary.chart-card';
    }
}
```

### 2. Зарегистрировать namespace анонимных компонентов хоста

В `AppServiceProvider::boot()`:

```php
use Illuminate\Support\Facades\Blade;

Blade::anonymousComponentNamespace(
    resource_path('views/components/app-tables'),
    'app-tables'
);
```

Любой `<x-app-tables::summary.chart-card>` теперь резолвится в `resources/views/components/app-tables/summary/chart-card.blade.php`.

### 3. Написать Blade-template

`resources/views/components/app-tables/summary/chart-card.blade.php`:

```blade
@props(['card'])

<div class="my-chart-card">
    <div class="my-chart-card__title">{{ $card->title }}</div>
    <canvas data-chart-points="{{ json_encode($card->points) }}"></canvas>
</div>
```

### 4. Использовать в Resource

```php
public function summary(Builder $qb): ?Summary
{
    $cards = [
        new KpiCard(title: 'Total', value: (string) $qb->count()),
        new ChartCard(title: 'Trend', points: $this->loadTrendPoints($qb)),
    ];

    return new Summary($cards);
}
```

Готово — карточка появится в summary-слоте без публикации Blade-компонентов пакета.

## Опционально: регистрация через slug

Если карточка строится из конфига или из строки (например, host передаёт `'chart'` в URL), используйте `SummaryCardRegistry`:

```php
// config/tables.php
'summary_cards' => [
    'chart' => \App\Tables\Summary\ChartCard::class,
    'donut' => \App\Tables\Summary\DonutCard::class,
],
```

```php
use Mercurio\Tables\Summary\SummaryCardRegistry;

$registry = app(SummaryCardRegistry::class);
$class = $registry->resolve('chart');     // \App\Tables\Summary\ChartCard::class
$card = new $class(title: '…', points: [...]);
```

Встроенные slug'и: `kpi` → `KpiCard`, `funnel` → `FunnelCard`. Регистрация необязательна — `summary_cards` в config пустой по умолчанию, прямой `new ChartCard(...)` работает без записи в registry.

## Обработка ошибок

`<x-tables.summary>` оборачивает рендер каждой карточки в `try/catch`. На любом `\Throwable`:

1. Пишет `Log::error('tables.summary.card_render_failed', ['view' => ..., 'class' => ..., 'error' => ...])` — никаких `warning`/`debug`.
2. Рендерит плейсхолдер с текстом из `tables::summary.card_render_failed` (`Не удалось отрисовать карточку.` / `Could not render card.`).
3. В debug-режиме (`config('app.debug') === true`) — добавляет stacktrace одной строкой под плейсхолдером.

Соседние карточки рендерятся как обычно — одна сломанная не валит всю страницу.

## Что переопределять можно, а что — нет

| Можно                                              | Нельзя |
|----------------------------------------------------|--------|
| Создавать собственные подклассы `SummaryCard`     | Менять контракт `cellView(): string` |
| Указывать host-namespaced view (`app-tables::…`)  | Использовать `tables::` namespace для своих view |
| Регистрировать в `config('tables.summary_cards')` | Перезаписывать встроенные slug'и (`kpi`, `funnel`) — `register()` молча перезатрёт, но это side-effect |
| Рендерить любую разметку (charts, custom UI)      | Внутри template вызывать `dd()`/`exit()` — try/catch не поймает, но это сломает рендер всей страницы |
