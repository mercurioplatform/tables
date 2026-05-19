<?php

namespace Mercurio\Tables\Tests\Feature\JsonApi;

use Illuminate\Support\Facades\Route;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrdersResource;
use Mercurio\Tables\Tests\TestCase;

/**
 * End-to-end feature-тесты QB-tree через POST body и GET ?qb=<base64>.
 *
 * Stack: HTTP → JsonApiController → ApiQueryParser → QueryBuilderParser →
 * ArraySource (через TestOrdersResource).
 */
final class QbApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::tablesApi('/api/orders', TestOrdersResource::class)->name('orders');
    }

    public function test_post_or_tree_returns_filtered_rows(): void
    {
        $tree = [
            'type' => 'group',
            'op' => 'OR',
            'children' => [
                ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'paid'],
                ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'pending'],
            ],
        ];

        $body = $this->postJson('/api/orders', ['qb' => $tree, 'per_page' => 10])
            ->assertOk()
            ->json();

        $statuses = array_column($body['data'], 'status');
        sort($statuses);

        // 4 rows in fixture: 1 pending + 2 paid + 1 cancelled
        $this->assertSame(['paid', 'paid', 'pending'], $statuses);
    }

    public function test_get_qb_base64_equivalent_to_post_body(): void
    {
        $tree = [
            'type' => 'group',
            'op' => 'OR',
            'children' => [
                ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'paid'],
                ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'pending'],
            ],
        ];

        $postBody = $this->postJson('/api/orders', ['qb' => $tree, 'per_page' => 10])
            ->assertOk()
            ->json();

        $b64 = base64_encode((string) json_encode($tree));
        $getBody = $this->getJson('/api/orders?per_page=10&qb='.urlencode($b64))
            ->assertOk()
            ->json();

        // Идентичный envelope (модуль порядка) — data, page совпадают
        $this->assertSame($postBody['data'], $getBody['data']);
        $this->assertSame($postBody['page'], $getBody['page']);
    }

    public function test_post_qb_with_saved_view_and_flat_filter_merges_into_and(): void
    {
        $tree = [
            'type' => 'group',
            'op' => 'OR',
            'children' => [
                ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'paid'],
                ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'pending'],
            ],
        ];

        // savedView 'paid' добавляет AND status='paid', и filter добавляет AND total >= 1500.
        // Итого: (status='paid' OR status='pending') AND status='paid' AND total >= 1500
        // → только строки paid с total >= 1500. В fixture это id=3 (total=1500).
        $body = $this->postJson('/api/orders', [
            'qb' => $tree,
            'savedView' => 'paid',
            'filter' => ['total' => ['gte' => 1500]],
            'per_page' => 10,
        ])
            ->assertOk()
            ->json();

        $this->assertCount(1, $body['data']);
        $this->assertSame(3, $body['data'][0]['id']);
        $this->assertSame('paid', $body['data'][0]['status']);
    }

    public function test_post_body_keys_drive_the_pipeline_like_query_string(): void
    {
        // POST с теми же top-level keys, что и query (sort, per_page, q) — без qb.
        $body = $this->postJson('/api/orders', [
            'sort' => '-total',
            'per_page' => 2,
            'page' => 1,
        ])
            ->assertOk()
            ->json();

        $this->assertCount(2, $body['data']);
        // sort desc по total: 1900 (cancelled), 1500 (paid)
        $this->assertSame(1900, $body['data'][0]['total']);
        $this->assertSame(1500, $body['data'][1]['total']);
    }
}
