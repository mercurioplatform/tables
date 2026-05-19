<?php

namespace Mercurio\Tables\Tests\Fixtures\JsonApi;

use Generator;
use Mercurio\Tables\Source\Capabilities;
use Mercurio\Tables\Source\Page;
use Mercurio\Tables\Source\Query;
use Mercurio\Tables\Source\Source;

/**
 * Read-only fake Source с отключёнными filter/sort/search/cursor capabilities.
 * Используется в тестах capabilities-gating'а — любая попытка отправить
 * filter/sort/q должна возвращать 422 ДО вызова `page()`.
 */
final class FakeNoFilterSource implements Source
{
    public function __construct(
        /** @var array<int, array<string, mixed>> */
        private array $rows = [],
    ) {}

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            filter: false,
            sort: false,
            search: false,
            count: true,
            cursor: false,
            mutate: false,
            stream: false,
        );
    }

    public function withQuery(Query $query): static
    {
        return $this;
    }

    public function count(): ?int
    {
        return count($this->rows);
    }

    public function page(int $page, int $perPage): Page
    {
        return new Page(
            rows: array_slice($this->rows, ($page - 1) * $perPage, $perPage),
            total: count($this->rows),
            page: $page,
            perPage: $perPage,
        );
    }

    public function stream(int $chunkSize): Generator
    {
        yield from $this->rows;
    }

    public function find(int|string $id): mixed
    {
        foreach ($this->rows as $row) {
            if (($row['id'] ?? null) === $id) {
                return $row;
            }
        }

        return null;
    }

    public function findMany(array $ids): iterable
    {
        return [];
    }

    public function update(int|string $id, array $changes): mixed
    {
        return null;
    }

    public function probe(): mixed
    {
        return null;
    }
}
