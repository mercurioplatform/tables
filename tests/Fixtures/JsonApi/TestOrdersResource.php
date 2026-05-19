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

/**
 * Тестовая модель ресурса для unit/feature JSON API-тестов.
 * Работает поверх {@see ArraySource} с фиксированным набором заказов.
 */
final class TestOrdersResource extends ListResource
{
    /** @var array<int, array<string, mixed>>|null */
    public static ?array $overrideRows = null;

    public function key(): string
    {
        return 'test_orders';
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
            TextField::make('number')->sortable()->filterable([Operator::Eq, Operator::Contains]),
            TextField::make('status')
                ->sortable()
                ->filterable([Operator::Eq, Operator::Neq, Operator::In, Operator::Empty_, Operator::NotEmpty]),
            NumberField::make('total')
                ->sortable()
                ->filterable([Operator::Eq, Operator::Gt, Operator::Gte, Operator::Lt, Operator::Lte, Operator::Between]),
            TextField::make('customer')->sortable(),
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
            SavedView::conditions('paid', 'Paid only', [
                new FilterCondition('status', Operator::Eq, 'paid'),
            ]),
            SavedView::conditions('hidden', 'Hidden view', [
                new FilterCondition('status', Operator::Eq, 'cancelled'),
            ]),
        ];
    }

    public function api(): ApiConfig
    {
        return ApiConfig::make()
            ->allowSavedViews(['all', 'paid']) // 'hidden' намеренно не входит
            ->defaultFormat(FormatMode::Raw)
            ->defaultPerPage(2)
            ->maxPerPage(10);
    }

    public function summary(): ?Summary
    {
        return new Summary([
            new KpiCard('Orders', '4'),
            new KpiCard('Revenue', '5400', '₽', '+12%', 'up'),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function rows(): array
    {
        if (self::$overrideRows !== null) {
            return self::$overrideRows;
        }

        return [
            ['id' => 1, 'number' => 'A-1001', 'status' => 'paid', 'total' => 1200, 'customer' => 'Alice'],
            ['id' => 2, 'number' => 'A-1002', 'status' => 'pending', 'total' => 800, 'customer' => 'Bob'],
            ['id' => 3, 'number' => 'A-1003', 'status' => 'paid', 'total' => 1500, 'customer' => 'Carol'],
            ['id' => 4, 'number' => 'A-1004', 'status' => 'cancelled', 'total' => 1900, 'customer' => 'Dan'],
        ];
    }
}
