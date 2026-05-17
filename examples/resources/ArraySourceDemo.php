<?php

namespace App\Tables\Demo;

use Mercurio\Tables\Field\NumberField;
use Mercurio\Tables\Field\StatusField;
use Mercurio\Tables\Field\TextField;
use Mercurio\Tables\Filter\FilterCondition;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\ArraySource;
use Mercurio\Tables\Source\Source;
use Mercurio\Tables\Summary\FunnelCard;
use Mercurio\Tables\Summary\KpiCard;
use Mercurio\Tables\Summary\Summary;
use Mercurio\Tables\View\SavedView;

/**
 * Demo-Resource на ArraySource.
 *
 * 32 row'а in-memory справочника: search / sort / chip-filters / QB /
 * saved views / pagination / export CSV. Mutate-UI полностью скрыт.
 *
 * Route: /admin/tables-demo/array
 */
final class ArraySourceDemo extends ListResource
{
    public function key(): string
    {
        return 'demo.array';
    }

    public function source(): ?Source
    {
        $rows = [
            ['id' => 1, 'code' => 'EUR', 'name' => 'Euro', 'group' => 'fiat', 'amount' => 1200, 'status' => 'active'],
            ['id' => 2, 'code' => 'USD', 'name' => 'US Dollar', 'group' => 'fiat', 'amount' => 980, 'status' => 'active'],
            ['id' => 3, 'code' => 'GBP', 'name' => 'British Pound', 'group' => 'fiat', 'amount' => 540, 'status' => 'active'],
            ['id' => 4, 'code' => 'CHF', 'name' => 'Swiss Franc', 'group' => 'fiat', 'amount' => 310, 'status' => 'active'],
            ['id' => 5, 'code' => 'JPY', 'name' => 'Japanese Yen', 'group' => 'fiat', 'amount' => 88000, 'status' => 'active'],
            ['id' => 6, 'code' => 'CNY', 'name' => 'Chinese Yuan', 'group' => 'fiat', 'amount' => 7100, 'status' => 'active'],
            ['id' => 7, 'code' => 'TRY', 'name' => 'Turkish Lira', 'group' => 'fiat', 'amount' => 290, 'status' => 'archived'],
            ['id' => 8, 'code' => 'UAH', 'name' => 'Ukrainian Hryvnia', 'group' => 'fiat', 'amount' => 41, 'status' => 'archived'],

            ['id' => 9, 'code' => 'BTC', 'name' => 'Bitcoin', 'group' => 'crypto', 'amount' => 65000, 'status' => 'active'],
            ['id' => 10, 'code' => 'ETH', 'name' => 'Ethereum', 'group' => 'crypto', 'amount' => 3500, 'status' => 'active'],
            ['id' => 11, 'code' => 'SOL', 'name' => 'Solana', 'group' => 'crypto', 'amount' => 145, 'status' => 'active'],
            ['id' => 12, 'code' => 'ADA', 'name' => 'Cardano', 'group' => 'crypto', 'amount' => 0.45, 'status' => 'active'],
            ['id' => 13, 'code' => 'DOGE', 'name' => 'Dogecoin', 'group' => 'crypto', 'amount' => 0.18, 'status' => 'archived'],
            ['id' => 14, 'code' => 'LTC', 'name' => 'Litecoin', 'group' => 'crypto', 'amount' => 82, 'status' => 'active'],
            ['id' => 15, 'code' => 'XRP', 'name' => 'Ripple', 'group' => 'crypto', 'amount' => 0.52, 'status' => 'active'],
            ['id' => 16, 'code' => 'BNB', 'name' => 'Binance Coin', 'group' => 'crypto', 'amount' => 580, 'status' => 'active'],

            ['id' => 17, 'code' => 'AAPL', 'name' => 'Apple Inc.', 'group' => 'stocks', 'amount' => 230, 'status' => 'active'],
            ['id' => 18, 'code' => 'GOOG', 'name' => 'Alphabet', 'group' => 'stocks', 'amount' => 175, 'status' => 'active'],
            ['id' => 19, 'code' => 'MSFT', 'name' => 'Microsoft', 'group' => 'stocks', 'amount' => 430, 'status' => 'active'],
            ['id' => 20, 'code' => 'AMZN', 'name' => 'Amazon', 'group' => 'stocks', 'amount' => 190, 'status' => 'active'],
            ['id' => 21, 'code' => 'TSLA', 'name' => 'Tesla', 'group' => 'stocks', 'amount' => 250, 'status' => 'active'],
            ['id' => 22, 'code' => 'NVDA', 'name' => 'NVIDIA', 'group' => 'stocks', 'amount' => 1100, 'status' => 'active'],
            ['id' => 23, 'code' => 'META', 'name' => 'Meta', 'group' => 'stocks', 'amount' => 540, 'status' => 'active'],

            ['id' => 24, 'code' => 'XAU', 'name' => 'Gold', 'group' => 'commodities', 'amount' => 2380, 'status' => 'active'],
            ['id' => 25, 'code' => 'XAG', 'name' => 'Silver', 'group' => 'commodities', 'amount' => 30, 'status' => 'active'],
            ['id' => 26, 'code' => 'XPT', 'name' => 'Platinum', 'group' => 'commodities', 'amount' => 940, 'status' => 'active'],
            ['id' => 27, 'code' => 'XPD', 'name' => 'Palladium', 'group' => 'commodities', 'amount' => 980, 'status' => 'archived'],
            ['id' => 28, 'code' => 'WTI', 'name' => 'WTI Crude Oil', 'group' => 'commodities', 'amount' => 78, 'status' => 'active'],
            ['id' => 29, 'code' => 'BRENT', 'name' => 'Brent Crude Oil', 'group' => 'commodities', 'amount' => 82, 'status' => 'active'],

            ['id' => 30, 'code' => 'SPX', 'name' => 'S&P 500 Index', 'group' => 'indices', 'amount' => 5400, 'status' => 'active'],
            ['id' => 31, 'code' => 'DJI', 'name' => 'Dow Jones', 'group' => 'indices', 'amount' => 40500, 'status' => 'active'],
            ['id' => 32, 'code' => 'IXIC', 'name' => 'NASDAQ Composite', 'group' => 'indices', 'amount' => 17200, 'status' => 'active'],
        ];

        return new ArraySource(collect($rows), resource: $this);
    }

    public function fields(): array
    {
        return [
            TextField::make('code', 'Код')
                ->mono()
                ->sortable(),

            TextField::make('name', 'Название')
                ->sortable(),

            TextField::make('group', 'Группа')
                ->sortable()
                ->filterable([Operator::Eq, Operator::In]),

            NumberField::make('amount', 'Сумма')
                ->sortable()
                ->filterable([Operator::Between, Operator::Gt, Operator::Lt]),

            StatusField::make('status', 'Статус')
                ->kinds([
                    'active' => 'success',
                    'archived' => 'secondary',
                ])
                ->labels([
                    'active' => 'Активно',
                    'archived' => 'В архиве',
                ])
                ->filterable(),
        ];
    }

    public function searchable(): array
    {
        return ['code', 'name'];
    }

    public function savedViews(): array
    {
        return [
            SavedView::all('Все'),
            SavedView::conditions('active', 'Активные', [
                new FilterCondition('status', Operator::Eq, 'active'),
            ]),
            SavedView::conditions('archived', 'В архиве', [
                new FilterCondition('status', Operator::Eq, 'archived'),
            ]),
            SavedView::conditions('crypto', 'Крипта', [
                new FilterCondition('group', Operator::Eq, 'crypto'),
            ]),
            SavedView::conditions('fiat', 'Фиат', [
                new FilterCondition('group', Operator::Eq, 'fiat'),
            ]),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['amount', 'desc'];
    }

    public function summary(): ?Summary
    {
        return new Summary([
            new KpiCard(
                title: 'Всего инструментов',
                value: '32',
                suffix: 'позиций',
                sparklineValues: [12, 18, 22, 25, 28, 30, 32],
            ),
            new KpiCard(
                title: 'Активных',
                value: '28',
                suffix: 'из 32',
                delta: '+87.5%',
                deltaTone: 'positive',
                sparklineValues: [20, 22, 24, 26, 27, 28, 28],
            ),
            new FunnelCard(
                label: 'Крипта',
                value: 8,
                viewKey: 'crypto',
                kind: 'primary',
            ),
            new FunnelCard(
                label: 'Фиат',
                value: 8,
                viewKey: 'fiat',
                kind: 'info',
            ),
        ]);
    }

    public function perPage(): int
    {
        return 10;
    }

    public function pageTitle(): ?string
    {
        return 'Tables Demo: ArraySource';
    }

    public function browserTitle(): ?string
    {
        return 'Demo ArraySource';
    }
}
