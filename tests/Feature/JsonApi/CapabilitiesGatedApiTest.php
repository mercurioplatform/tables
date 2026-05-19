<?php

namespace Mercurio\Tables\Tests\Feature\JsonApi;

use Illuminate\Support\Facades\Route;
use Mercurio\Tables\Tests\Fixtures\JsonApi\NoFilterOrdersResource;
use Mercurio\Tables\Tests\TestCase;

final class CapabilitiesGatedApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::tablesApi('/api/nofilter', NoFilterOrdersResource::class);
    }

    public function test_filter_request_returns_422(): void
    {
        $r = $this->getJson('/api/nofilter?filter[status]=paid');
        $r->assertStatus(422);
        $body = $r->json();
        $this->assertSame('CAPABILITY_UNSUPPORTED', $body['error']['code']);
        $this->assertSame('filter', $body['error']['details']['capability']);
    }

    public function test_sort_request_returns_422(): void
    {
        $r = $this->getJson('/api/nofilter?sort=number');
        $r->assertStatus(422);
        $this->assertSame('sort', $r->json('error.details.capability'));
    }

    public function test_search_request_returns_422(): void
    {
        $r = $this->getJson('/api/nofilter?q=hello');
        $r->assertStatus(422);
        $this->assertSame('search', $r->json('error.details.capability'));
    }

    public function test_plain_request_passes(): void
    {
        $r = $this->getJson('/api/nofilter');
        $r->assertOk();
        $this->assertCount(1, $r->json('data'));
    }
}
