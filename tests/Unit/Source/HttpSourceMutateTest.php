<?php

namespace Mercurio\Tables\Tests\Unit\Source;

use Closure;
use Illuminate\Support\Facades\Log;
use LogicException;
use Mercurio\Tables\Source\Capabilities;
use Mercurio\Tables\Source\HttpSource;
use Mercurio\Tables\Source\Query;
use Mercurio\Tables\Tests\TestCase;
use Mockery;

final class HttpSourceMutateTest extends TestCase
{
    private function nullFetch(): Closure
    {
        return static fn (Query $q, ?string $cursor): array => ['rows' => []];
    }

    public function test_update_throws_logic_exception_by_default(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('HttpSource is read-only by design');

        HttpSource::for($this->nullFetch())->update(1, ['status' => 'paid']);
    }

    public function test_update_logs_warning_with_resource_and_id_and_columns(): void
    {
        Log::spy();

        try {
            HttpSource::for($this->nullFetch())->update(1, ['status' => 'paid']);
            $this->fail('Expected LogicException was not thrown.');
        } catch (LogicException) {
            // expected
        }

        Log::shouldHaveReceived('warning')
            ->with(
                'tables.source.http.mutate_denied',
                Mockery::on(static fn (array $ctx): bool => array_key_exists('resource', $ctx)
                    && $ctx['resource'] === null
                    && ($ctx['id'] ?? null) === 1
                    && ($ctx['columns'] ?? null) === ['status']),
            )
            ->once();
    }

    public function test_update_with_mutate_true_capability_still_throws(): void
    {
        $source = HttpSource::for(
            fetch: $this->nullFetch(),
            capabilities: new Capabilities(
                filter: true,
                sort: true,
                search: true,
                count: false,
                cursor: true,
                mutate: true,
                stream: true,
            ),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('HttpSource is read-only by design');

        $source->update(1, []);
    }
}
