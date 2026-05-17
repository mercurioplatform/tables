<?php

namespace App\Tables\Demo;

use Illuminate\Support\Facades\DB;
use Mercurio\Tables\Field\MoneyField;
use Mercurio\Tables\Field\NumberField;
use Mercurio\Tables\Field\StatusField;
use Mercurio\Tables\Field\TextField;
use Mercurio\Tables\Filter\FilterCondition;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\Source;
use Mercurio\Tables\Source\SqlSource;
use Mercurio\Tables\Summary\FunnelCard;
use Mercurio\Tables\Summary\KpiCard;
use Mercurio\Tables\Summary\Summary;
use Mercurio\Tables\View\SavedView;

/**
 * Demo-Resource на SqlSource.
 *
 * Источник — таблица `tables_demo_orders` через `SqlSource::for(...)` поверх default
 * connection. Имя таблицы префиксировано (`tables_demo_*`), чтобы demo bundle не
 * конфликтовал с хост-таблицей `orders`. EloquentModel для resource'а не нужна:
 * SqlSource создаёт inline-модель самостоятельно. Mutate-UI скрыт (read-only).
 *
 * Route: /admin/tables-demo/sql
 */
final class SqlSourceDemo extends ListResource
{
    public function key(): string
    {
        return 'demo.sql';
    }

    public function source(): ?Source
    {
        return SqlSource::for('tables_demo_orders', resource: $this);
    }

    public function fields(): array
    {
        return [
            NumberField::make('id', '#')
                ->sortable(),

            TextField::make('number', 'Номер')
                ->mono()
                ->sortable(),

            TextField::make('city', 'Город')
                ->sortable()
                ->filterable([Operator::Eq, Operator::Contains]),

            StatusField::make('status', 'Статус')
                ->kinds([
                    'new' => 'secondary',
                    'packing' => 'info',
                    'shipping' => 'primary',
                    'delivered' => 'success',
                    'cancelled' => 'danger',
                ])
                ->filterable(),

            StatusField::make('payment_status', 'Оплата')
                ->kinds([
                    'pending' => 'secondary',
                    'paid' => 'success',
                    'cod' => 'info',
                    'refunded' => 'warning',
                ])
                ->filterable(),

            MoneyField::make('total', 'Сумма')
                ->sortable()
                ->filterable([Operator::Between, Operator::Gt, Operator::Lt]),
        ];
    }

    public function searchable(): array
    {
        return ['number', 'city'];
    }

    public function savedViews(): array
    {
        return [
            SavedView::all('Все'),
            SavedView::conditions('new', 'Новые', [
                new FilterCondition('status', Operator::Eq, 'new'),
            ]),
            SavedView::conditions('shipping', 'В доставке', [
                new FilterCondition('status', Operator::Eq, 'shipping'),
            ]),
            SavedView::conditions('cancelled', 'Отменённые', [
                new FilterCondition('status', Operator::Eq, 'cancelled'),
            ]),
            SavedView::conditions('paid', 'Оплачены', [
                new FilterCondition('payment_status', Operator::Eq, 'paid'),
            ]),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['id', 'desc'];
    }

    public function summary(): ?Summary
    {
        $total = (int) DB::table('tables_demo_orders')->count();
        $paid = (int) DB::table('tables_demo_orders')->where('payment_status', 'paid')->count();
        $new = (int) DB::table('tables_demo_orders')->where('status', 'new')->count();
        $shipping = (int) DB::table('tables_demo_orders')->where('status', 'shipping')->count();
        $paidShare = $total > 0 ? round($paid / $total * 100, 1) : 0.0;

        return new Summary([
            new KpiCard(
                title: 'Всего заказов',
                value: (string) $total,
                suffix: 'orders',
            ),
            new KpiCard(
                title: 'Оплачено',
                value: (string) $paid,
                suffix: 'из '.$total,
                delta: $paidShare.'%',
                deltaTone: $paidShare >= 50 ? 'positive' : 'neutral',
            ),
            new FunnelCard(
                label: 'Новые',
                value: $new,
                viewKey: 'new',
                kind: 'info',
            ),
            new FunnelCard(
                label: 'В доставке',
                value: $shipping,
                viewKey: 'shipping',
                kind: 'primary',
            ),
        ]);
    }

    public function perPage(): int
    {
        return 10;
    }

    public function pageTitle(): ?string
    {
        return 'Tables Demo: SqlSource';
    }

    public function browserTitle(): ?string
    {
        return 'Demo SqlSource';
    }
}
