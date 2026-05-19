<?php

namespace Mercurio\Tables\Tests\Feature\JsonApi;

use Illuminate\Support\Facades\Route;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrdersResource;
use Mercurio\Tables\Tests\TestCase;

final class ArraySourceApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::tablesApi('/api/orders', TestOrdersResource::class)->name('orders');
    }

    public function test_default_envelope_has_data_and_page_only(): void
    {
        $r = $this->getJson('/api/orders');
        $r->assertOk();
        $body = $r->json();

        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('page', $body);
        $this->assertArrayNotHasKey('summary', $body);
        $this->assertArrayNotHasKey('savedViews', $body);
        $this->assertArrayNotHasKey('capabilities', $body);

        // per_page=2 (default из TestOrdersResource)
        $this->assertCount(2, $body['data']);
        $this->assertSame('offset', $body['page']['mode']);
        $this->assertSame(4, $body['page']['total']);
        $this->assertSame(2, $body['page']['per_page']);
        $this->assertSame(1, $body['page']['current_page']);
    }

    public function test_explicit_includes(): void
    {
        $body = $this->getJson('/api/orders?include=summary,savedViews,capabilities')
            ->assertOk()->json();

        $this->assertArrayHasKey('summary', $body);
        $this->assertArrayHasKey('savedViews', $body);
        $this->assertArrayHasKey('capabilities', $body);

        $this->assertCount(2, $body['summary']);
        $this->assertSame('kpi', $body['summary'][0]['type']);

        // savedViews отфильтрован allowSavedViews → только all, paid (без hidden)
        $keys = array_column($body['savedViews'], 'key');
        $this->assertSame(['all', 'paid'], $keys);

        $this->assertTrue($body['capabilities']['filter']);
        $this->assertTrue($body['capabilities']['sort']);
    }

    public function test_sparse_fields(): void
    {
        $body = $this->getJson('/api/orders?fields=id,total')->assertOk()->json();

        foreach ($body['data'] as $row) {
            $this->assertSame(['id', 'total'], array_keys($row));
        }
    }

    public function test_format_raw_returns_scalars(): void
    {
        $body = $this->getJson('/api/orders?format=raw')->assertOk()->json();
        $this->assertSame(1200, $body['data'][0]['total']);
    }

    public function test_format_formatted_returns_strings(): void
    {
        $body = $this->getJson('/api/orders?format=formatted')->assertOk()->json();
        $this->assertSame('1200', $body['data'][0]['total']);
    }

    public function test_format_both_returns_objects(): void
    {
        $body = $this->getJson('/api/orders?format=both')->assertOk()->json();
        $this->assertSame(['raw', 'display', 'tone'], array_keys($body['data'][0]['total']));
        $this->assertSame(1200, $body['data'][0]['total']['raw']);
        $this->assertSame('1200', $body['data'][0]['total']['display']);
        $this->assertNull($body['data'][0]['total']['tone']);
    }

    public function test_filter_equality(): void
    {
        $body = $this->getJson('/api/orders?filter[status]=paid&per_page=10')->assertOk()->json();
        $this->assertCount(2, $body['data']);
        foreach ($body['data'] as $row) {
            $this->assertSame('paid', $row['status']);
        }
    }

    public function test_filter_gte(): void
    {
        $body = $this->getJson('/api/orders?filter[total][gte]=1500&per_page=10')->assertOk()->json();
        $this->assertCount(2, $body['data']);
        foreach ($body['data'] as $row) {
            $this->assertGreaterThanOrEqual(1500, $row['total']);
        }
    }

    public function test_filter_in(): void
    {
        $body = $this->getJson('/api/orders?filter[status][in][]=paid&filter[status][in][]=pending&per_page=10')
            ->assertOk()->json();
        $this->assertCount(3, $body['data']);
    }

    public function test_sort_desc(): void
    {
        $body = $this->getJson('/api/orders?sort=-total&per_page=10')->assertOk()->json();
        $totals = array_column($body['data'], 'total');
        $sorted = $totals;
        rsort($sorted);
        $this->assertSame($sorted, $totals);
    }

    public function test_search_q(): void
    {
        $body = $this->getJson('/api/orders?q=Alice&per_page=10')->assertOk()->json();
        $this->assertCount(1, $body['data']);
        $this->assertSame('Alice', $body['data'][0]['customer']);
    }

    public function test_per_page_limit(): void
    {
        $body = $this->getJson('/api/orders?per_page=1')->assertOk()->json();
        $this->assertCount(1, $body['data']);
        $this->assertSame(1, $body['page']['per_page']);
    }

    public function test_pagination_second_page(): void
    {
        $body = $this->getJson('/api/orders?page=2')->assertOk()->json();
        $this->assertCount(2, $body['data']);
        $this->assertSame(2, $body['page']['current_page']);
    }

    public function test_saved_view_paid(): void
    {
        $body = $this->getJson('/api/orders?savedView=paid&per_page=10')->assertOk()->json();
        $this->assertCount(2, $body['data']);
        foreach ($body['data'] as $row) {
            $this->assertSame('paid', $row['status']);
        }
    }

    public function test_saved_view_plus_filter_merge(): void
    {
        $body = $this->getJson('/api/orders?savedView=paid&filter[total][gt]=1300&per_page=10')
            ->assertOk()->json();
        $this->assertCount(1, $body['data']);
        $this->assertSame('Carol', $body['data'][0]['customer']);
    }
}
