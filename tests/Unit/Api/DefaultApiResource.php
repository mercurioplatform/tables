<?php

namespace Mercurio\Tables\Tests\Unit\Api;

use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Field\TextField;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\ArraySource;
use Mercurio\Tables\Source\Source;

/**
 * Ресурс без override `api()` — для теста default'ов и автоматического
 * резолва `allowFields` через `fieldsMemo()`.
 */
final class DefaultApiResource extends ListResource
{
    public function key(): string
    {
        return 'default_api';
    }

    public function source(): ?Source
    {
        return new ArraySource([], null, 'id', $this);
    }

    /**
     * @return array<int, Field>
     */
    public function fields(): array
    {
        return [
            TextField::make('id'),
            TextField::make('name'),
        ];
    }
}
