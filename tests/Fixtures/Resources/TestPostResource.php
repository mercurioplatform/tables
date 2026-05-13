<?php

namespace Mercurio\Tables\Tests\Fixtures\Resources;

use Illuminate\Database\Eloquent\Builder;
use Mercurio\Tables\Field\DateField;
use Mercurio\Tables\Field\StatusField;
use Mercurio\Tables\Field\TextField;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Tests\Fixtures\Models\TestPost;

class TestPostResource extends ListResource
{
    public function key(): string
    {
        return 'test-posts';
    }

    public function query(): Builder
    {
        return TestPost::query();
    }

    public function fields(): array
    {
        return [
            TextField::make('id', 'ID'),
            TextField::make('title', 'Заголовок'),
            StatusField::make('status', 'Статус'),
            DateField::make('published_at', 'Опубликовано'),
        ];
    }

    public function searchable(): array
    {
        return ['title', 'body'];
    }

    public function defaultSort(): ?array
    {
        return ['id', 'desc'];
    }

    public function perPage(): int
    {
        return 25;
    }
}
