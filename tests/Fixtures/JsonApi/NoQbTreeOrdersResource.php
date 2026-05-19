<?php

namespace Mercurio\Tables\Tests\Fixtures\JsonApi;

use Mercurio\Tables\Api\ApiConfig;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Field\NumberField;
use Mercurio\Tables\Field\TextField;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\Source;

/**
 * Ресурс поверх {@see FakeNoQbSource} — для тестов capabilities-gating'а
 * по `qbTree=false`. Источник имеет `filter=true`, поэтому плоские
 * `?filter[..]` работают; но любой qb (POST body или ?qb=) должен
 * получить 422 `CAPABILITY_UNSUPPORTED`.
 */
final class NoQbTreeOrdersResource extends ListResource
{
    public function key(): string
    {
        return 'no_qb_tree_orders';
    }

    public function source(): ?Source
    {
        return new FakeNoQbSource([
            ['id' => 1, 'number' => 'X-1', 'status' => 'paid', 'total' => 100],
            ['id' => 2, 'number' => 'X-2', 'status' => 'pending', 'total' => 200],
        ]);
    }

    /**
     * @return array<int, Field>
     */
    public function fields(): array
    {
        return [
            TextField::make('id')->sortable(),
            TextField::make('number')->sortable()->filterable([Operator::Eq]),
            TextField::make('status')->sortable()->filterable([Operator::Eq, Operator::In]),
            NumberField::make('total')->sortable()->filterable([Operator::Eq, Operator::Gte]),
        ];
    }

    public function searchable(): array
    {
        return ['number'];
    }

    public function api(): ApiConfig
    {
        return ApiConfig::make()
            ->defaultPerPage(10)
            ->maxPerPage(50);
    }
}
