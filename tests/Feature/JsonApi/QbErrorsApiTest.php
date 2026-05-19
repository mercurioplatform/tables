<?php

namespace Mercurio\Tables\Tests\Feature\JsonApi;

use Illuminate\Support\Facades\Route;
use Mercurio\Tables\Tests\Fixtures\JsonApi\NoQbTreeOrdersResource;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrdersResource;
use Mercurio\Tables\Tests\TestCase;

/**
 * Feature-тесты на error-pathways QB-tree:
 * - 400 на битый base64 / overflow;
 * - 422 VALIDATION_FAILED на disallowed field / operator в дереве;
 * - 422 CAPABILITY_UNSUPPORTED для Source без qbTree.
 */
final class QbErrorsApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::tablesApi('/api/orders', TestOrdersResource::class)->name('orders');
        Route::tablesApi('/api/noqb-orders', NoQbTreeOrdersResource::class)->name('noqb_orders');
    }

    public function test_get_malformed_base64_returns_400(): void
    {
        $body = $this->getJson('/api/orders?qb=NOT_BASE64@@@')
            ->assertStatus(400)
            ->json();

        $this->assertSame('MALFORMED_QUERY', $body['error']['code']);
        $this->assertSame('malformed_base64', $body['error']['details']['kind']);
    }

    public function test_get_depth_exceeded_returns_400(): void
    {
        config(['tables.qb_max_depth' => 1]);

        $tree = [
            'type' => 'group',
            'op' => 'AND',
            'children' => [[
                'type' => 'group',
                'op' => 'AND',
                'children' => [[
                    'type' => 'group',
                    'op' => 'AND',
                    'children' => [
                        ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'paid'],
                    ],
                ]],
            ]],
        ];

        $body = $this->getJson('/api/orders?qb='.urlencode(base64_encode((string) json_encode($tree))))
            ->assertStatus(400)
            ->json();

        $this->assertSame('MALFORMED_QUERY', $body['error']['code']);
        $this->assertSame('depth_exceeded', $body['error']['details']['kind']);
    }

    public function test_post_qb_unknown_field_returns_422(): void
    {
        $body = $this->postJson('/api/orders', [
            'qb' => ['type' => 'cond', 'field' => 'secret', 'operator' => 'eq', 'value' => 'x'],
        ])
            ->assertStatus(422)
            ->json();

        $this->assertSame('VALIDATION_FAILED', $body['error']['code']);
        $this->assertSame('unknown_field', $body['error']['details']['kind']);
        $this->assertSame('secret', $body['error']['details']['field']);
    }

    public function test_post_qb_operator_not_allowed_returns_422(): void
    {
        $body = $this->postJson('/api/orders', [
            'qb' => ['type' => 'cond', 'field' => 'number', 'operator' => 'gte', 'value' => 'A-1'],
        ])
            ->assertStatus(422)
            ->json();

        $this->assertSame('VALIDATION_FAILED', $body['error']['code']);
        $this->assertSame('operator_not_allowed', $body['error']['details']['kind']);
        $this->assertSame('number', $body['error']['details']['field']);
        $this->assertSame('gte', $body['error']['details']['operator']);
    }

    public function test_post_qb_on_qb_tree_false_source_returns_422_capability_unsupported(): void
    {
        $body = $this->postJson('/api/noqb-orders', [
            'qb' => ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'paid'],
        ])
            ->assertStatus(422)
            ->json();

        $this->assertSame('CAPABILITY_UNSUPPORTED', $body['error']['code']);
        $this->assertSame('qbTree', $body['error']['details']['capability']);
    }

    public function test_get_qb_on_qb_tree_false_source_returns_422_capability_unsupported(): void
    {
        $tree = ['type' => 'cond', 'field' => 'status', 'operator' => 'eq', 'value' => 'paid'];

        $body = $this->getJson('/api/noqb-orders?qb='.urlencode(base64_encode((string) json_encode($tree))))
            ->assertStatus(422)
            ->json();

        $this->assertSame('CAPABILITY_UNSUPPORTED', $body['error']['code']);
        $this->assertSame('qbTree', $body['error']['details']['capability']);
    }

    public function test_post_form_encoded_returns_400_unsupported_media_type(): void
    {
        $body = $this->call(
            'POST',
            '/api/orders',
            ['qb' => 'whatever'],
            [],
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
        );

        $body->assertStatus(400);
        $this->assertSame('MALFORMED_QUERY', $body->json('error.code'));
        $this->assertSame('unsupported_media_type', $body->json('error.details.reason'));
    }
}
