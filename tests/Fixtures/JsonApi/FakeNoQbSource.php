<?php

namespace Mercurio\Tables\Tests\Fixtures\JsonApi;

use Generator;
use Mercurio\Tables\Source\ArraySource;
use Mercurio\Tables\Source\Capabilities;
use Mercurio\Tables\Source\Page;
use Mercurio\Tables\Source\Query;
use Mercurio\Tables\Source\Source;

/**
 * Read-only Source-обёртка над {@see ArraySource} с принудительно выключенной
 * capability `qbTree`. Имитирует HttpSource-like Source-драйверы, которые
 * умеют флэт-фильтры, но не понимают полноценное QB-дерево.
 *
 * Используется в `QbErrorsApiTest` для проверки 422 `CAPABILITY_UNSUPPORTED`,
 * когда клиент шлёт `?qb=` или POST body.qb на Source без `qbTree`.
 *
 * API-уровень (`JsonApiController::assertCapabilities`) отбивает запрос
 * до вызова `withQuery()`/`page()`, поэтому обёртка делегирует pipeline
 * на внутренний ArraySource «как есть» и подменяет только флаг `qbTree`.
 */
final class FakeNoQbSource implements Source
{
    private ArraySource $inner;

    private readonly Capabilities $caps;

    /**
     * @param  array<int, array<string, mixed>>|null  $rows  При первом вызове
     *                                                       передаётся массив строк;
     *                                                       последующие withQuery
     *                                                       клонируют экземпляр с
     *                                                       пре-фильтрованным inner.
     */
    public function __construct(?array $rows = null, ?ArraySource $existing = null)
    {
        $this->inner = $existing ?? new ArraySource($rows ?? []);
        $this->caps = new Capabilities(
            filter: true,
            sort: true,
            search: true,
            count: true,
            cursor: false,
            mutate: false,
            stream: true,
            qbTree: false,
        );
    }

    public function capabilities(): Capabilities
    {
        return $this->caps;
    }

    public function withQuery(Query $query): static
    {
        // qbTree=false: API уже должен был отбить qbRoot до сюда; но как
        // defensive safety-net обнуляем его перед передачей в inner.
        $forwarded = clone $query;
        $forwarded->qbRoot = null;

        return new self(existing: $this->inner->withQuery($forwarded));
    }

    public function count(): ?int
    {
        return $this->inner->count();
    }

    public function page(int $page, int $perPage): Page
    {
        return $this->inner->page($page, $perPage);
    }

    public function stream(int $chunkSize): Generator
    {
        yield from $this->inner->stream($chunkSize);
    }

    public function find(int|string $id): mixed
    {
        return $this->inner->find($id);
    }

    public function findMany(array $ids): iterable
    {
        return $this->inner->findMany($ids);
    }

    public function update(int|string $id, array $changes): mixed
    {
        return $this->inner->update($id, $changes);
    }

    public function probe(): mixed
    {
        return $this->inner->probe();
    }
}
