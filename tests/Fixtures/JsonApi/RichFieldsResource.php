<?php

namespace Mercurio\Tables\Tests\Fixtures\JsonApi;

use Mercurio\Tables\Api\ApiConfig;
use Mercurio\Tables\Field\BooleanField;
use Mercurio\Tables\Field\DateField;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Field\MoneyField;
use Mercurio\Tables\Field\StatusField;
use Mercurio\Tables\Field\TextField;
use Mercurio\Tables\Filter\FilterCondition;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\ArraySource;
use Mercurio\Tables\Source\Source;
use Mercurio\Tables\View\SavedView;

/**
 * Тестовая фикстура для покрытия SchemaBuilder/Field-overrides:
 * содержит представителей всех «scalar» Field-типов с фактически выставленными
 * format-hints.
 */
final class RichFieldsResource extends ListResource
{
    public function key(): string
    {
        return 'rich_fields';
    }

    public function source(): ?Source
    {
        return new ArraySource([
            ['id' => 1, 'status' => 'paid', 'total' => 1500, 'created_at' => '2026-05-01', 'verified' => true],
            ['id' => 2, 'status' => 'pending', 'total' => 800, 'created_at' => '2026-05-02', 'verified' => false],
        ], null, 'id', $this);
    }

    /**
     * @return array<int, Field>
     */
    public function fields(): array
    {
        return [
            TextField::make('id')->sortable(),
            StatusField::make('status')
                ->kinds(['paid' => 'success', 'pending' => 'warning'])
                ->labels(['paid' => 'Оплачен', 'pending' => 'Ожидание'])
                ->filterable([Operator::Eq, Operator::In])
                ->sortable(),
            MoneyField::make('total')
                ->divisor(100)
                ->currency('₽')
                ->decimals(2)
                ->sortable()
                ->filterable([Operator::Gte, Operator::Lte, Operator::Between])
                ->align('right'),
            DateField::make('created_at')
                ->format('d.m.Y')
                ->sortable()
                ->filterable([Operator::Between]),
            BooleanField::make('verified')
                ->labels('Да', 'Нет')
                ->filterable([Operator::Eq])
                ->hideByDefault(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function searchable(): array
    {
        return ['id'];
    }

    /**
     * @return array<int, SavedView>
     */
    public function savedViews(): array
    {
        return [
            SavedView::all('Все'),
            SavedView::conditions('paid', 'Оплачен', [
                new FilterCondition('status', Operator::Eq, 'paid'),
            ])->color('success')->icon('check')->position(1)->default(),
            SavedView::conditions('big', 'Крупные', [
                new FilterCondition('total', Operator::Gte, 1000),
            ]),
        ];
    }

    public function api(): ApiConfig
    {
        return ApiConfig::make();
    }
}
