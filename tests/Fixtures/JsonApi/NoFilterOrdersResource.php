<?php

namespace Mercurio\Tables\Tests\Fixtures\JsonApi;

use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Field\TextField;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\Source;

/**
 * Ресурс поверх {@see FakeNoFilterSource} — для тестов capabilities-gating'а.
 */
final class NoFilterOrdersResource extends ListResource
{
    public function key(): string
    {
        return 'no_filter_orders';
    }

    public function source(): ?Source
    {
        return new FakeNoFilterSource([
            ['id' => 1, 'number' => 'X-1', 'status' => 'paid'],
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
            TextField::make('status')->sortable()->filterable([Operator::Eq]),
        ];
    }

    public function searchable(): array
    {
        return ['number'];
    }
}
