<?php

namespace Mercurio\Tables\Tests\Fixtures\JsonApi;

use Mercurio\Tables\Api\ApiConfig;
use Mercurio\Tables\Api\FormatMode;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Field\NumberField;
use Mercurio\Tables\Field\TextField;
use Mercurio\Tables\Filter\FilterCondition;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\ArraySource;
use Mercurio\Tables\Source\Source;
use Mercurio\Tables\Summary\KpiCard;
use Mercurio\Tables\Summary\Summary;
use Mercurio\Tables\View\SavedView;

final class PerfOrdersResource extends ListResource
{
    /** @var array<int, array<string, mixed>>|null */
    public static ?array $overrideRows = null;

    public function key(): string
    {
        return 'perf_orders';
    }

    public function source(): ?Source
    {
        return new ArraySource(self::rows(), null, 'id', $this);
    }

    /**
     * @return array<int, Field>
     */
    public function fields(): array
    {
        return [
            TextField::make('id')->sortable(),
            TextField::make('number')
                ->sortable()
                ->filterable([Operator::Eq, Operator::Contains]),
            TextField::make('status')
                ->sortable()
                ->filterable([Operator::Eq, Operator::Neq, Operator::In, Operator::Empty_, Operator::NotEmpty]),
            NumberField::make('total')
                ->sortable()
                ->filterable([Operator::Eq, Operator::Gt, Operator::Gte, Operator::Lt, Operator::Lte, Operator::Between]),
            TextField::make('customer')
                ->sortable()
                ->filterable([Operator::Eq, Operator::Contains]),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function searchable(): array
    {
        return ['number', 'customer'];
    }

    /**
     * @return array<int, SavedView>
     */
    public function savedViews(): array
    {
        return [
            SavedView::all('All orders'),
            SavedView::conditions('paid', 'Paid', [
                new FilterCondition('status', Operator::Eq, 'paid'),
            ]),
            SavedView::conditions('pending', 'Pending', [
                new FilterCondition('status', Operator::Eq, 'pending'),
            ]),
            SavedView::conditions('cancelled', 'Cancelled', [
                new FilterCondition('status', Operator::Eq, 'cancelled'),
            ]),
            SavedView::conditions('high_value', 'High value', [
                new FilterCondition('total', Operator::Gte, 1000),
            ]),
        ];
    }

    public function api(): ApiConfig
    {
        return ApiConfig::make()
            ->allowSavedViews(['all', 'paid', 'pending', 'cancelled', 'high_value'])
            ->defaultFormat(FormatMode::Raw)
            ->defaultPerPage(10_000)
            ->maxPerPage(100_000);
    }

    public function summary(): ?Summary
    {
        return new Summary([
            new KpiCard('Orders', '0'),
            new KpiCard('Revenue', '0', '₽'),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function rows(): array
    {
        return self::$overrideRows ?? [];
    }
}
