<?php

namespace Mercurio\Tables\Tests\Performance;

use Illuminate\Support\Facades\Route;
use Mercurio\Tables\Api\SchemaBuilder;
use Mercurio\Tables\Tests\Fixtures\JsonApi\PerfOrdersResource;
use Mercurio\Tables\Tests\TestCase;

final class JsonRendererPerformanceTest extends TestCase
{
    private const SCHEMA_ABS_UPPER_BOUND_SECONDS = 0.010;

    private const ENVELOPE_DATA_GROWTH_FLOOR = 2.0;

    private const N_SMALL = 1_000;

    private const N_LARGE = 10_000;

    private const ITERATIONS = 5;

    private const WARMUP_ITERATIONS = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Route::tablesApi('/api/perf-orders', PerfOrdersResource::class)->name('perf-orders');
    }

    protected function tearDown(): void
    {
        PerfOrdersResource::$overrideRows = null;
        parent::tearDown();
    }

    public function test_schema_builder_direct_call_is_bounded(): void
    {
        PerfOrdersResource::$overrideRows = $this->generateRows(self::N_LARGE);

        $resource = new PerfOrdersResource;
        $config = $resource->resolveApiConfig();
        $source = $resource->resolveSource();
        $builder = app(SchemaBuilder::class);

        $median = $this->measureMedian(fn () => $builder->build($resource, $config, $source));

        $this->assertLessThan(
            0.05,
            $median,
            sprintf(
                'SchemaBuilder::build() median = %.6fs (> 50ms upper bound). '
                .'Expected O(fields + savedViews), likely added O(N) logic.',
                $median,
            ),
        );
    }

    public function test_schema_block_inside_envelope_is_independent_of_row_count(): void
    {
        $tSmallWithSchema = $this->measureEnvelopeAt(self::N_SMALL, withSchema: true);
        $tSmallNoSchema = $this->measureEnvelopeAt(self::N_SMALL, withSchema: false);

        $tLargeWithSchema = $this->measureEnvelopeAt(self::N_LARGE, withSchema: true);
        $tLargeNoSchema = $this->measureEnvelopeAt(self::N_LARGE, withSchema: false);

        $tSchemaOnlyLarge = max($tLargeWithSchema - $tLargeNoSchema, 0.0);
        $dataGrowthRatio = $tLargeNoSchema / max($tSmallNoSchema, 1e-9);

        $diag = sprintf(
            'schema_only_large=%.6fs (limit %.6fs), data_growth_ratio=%.3f (floor %.3f). '
            .'Raw: with_schema_small=%.6fs, with_schema_large=%.6fs, '
            .'no_schema_small=%.6fs, no_schema_large=%.6fs.',
            $tSchemaOnlyLarge,
            self::SCHEMA_ABS_UPPER_BOUND_SECONDS,
            $dataGrowthRatio,
            self::ENVELOPE_DATA_GROWTH_FLOOR,
            $tSmallWithSchema,
            $tLargeWithSchema,
            $tSmallNoSchema,
            $tLargeNoSchema,
        );

        $this->assertLessThan(
            self::SCHEMA_ABS_UPPER_BOUND_SECONDS,
            $tSchemaOnlyLarge,
            'schema-include delta at N=10k exceeded absolute upper bound — likely O(N × fields) regression. '.$diag,
        );

        $this->assertGreaterThan(
            self::ENVELOPE_DATA_GROWTH_FLOOR,
            $dataGrowthRatio,
            'no-schema envelope did not grow with N — suspicious measurement (cache hit, route not reached, '
            .'or per_page clamp). '.$diag,
        );
    }

    private function measureEnvelopeAt(int $rows, bool $withSchema): float
    {
        PerfOrdersResource::$overrideRows = $this->generateRows($rows);

        $url = '/api/perf-orders?per_page='.$rows;
        if ($withSchema) {
            $url .= '&include=schema';
        }

        return $this->measureMedian(function () use ($url): void {
            $this->get($url)->assertOk();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generateRows(int $n): array
    {
        $statuses = ['paid', 'pending', 'cancelled', 'paid', 'paid'];
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = [
                'id' => $i,
                'number' => 'A-'.$i,
                'status' => $statuses[$i % count($statuses)],
                'total' => 100 + (($i * 37) % 5000),
                'customer' => 'C'.$i,
            ];
        }

        return $rows;
    }

    private function measureMedian(callable $cb): float
    {
        for ($i = 0; $i < self::WARMUP_ITERATIONS; $i++) {
            $cb();
        }

        $times = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $start = hrtime(true);
            $cb();
            $times[] = (hrtime(true) - $start) / 1e9;
        }

        sort($times);

        return $times[(int) (count($times) / 2)];
    }
}
