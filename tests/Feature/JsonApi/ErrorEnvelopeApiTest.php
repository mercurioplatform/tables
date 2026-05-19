<?php

namespace Mercurio\Tables\Tests\Feature\JsonApi;

use Illuminate\Support\Facades\Route;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrdersResource;
use Mercurio\Tables\Tests\TestCase;

final class ErrorEnvelopeApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::tablesApi('/api/orders', TestOrdersResource::class);
    }

    public function test_invalid_format_returns_422_envelope(): void
    {
        $r = $this->getJson('/api/orders?format=xml');
        $r->assertStatus(422);
        $body = $r->json();
        $this->assertSame('VALIDATION_FAILED', $body['error']['code']);
        $this->assertSame('xml', $body['error']['details']['format']);
    }

    public function test_unknown_field_returns_422(): void
    {
        $r = $this->getJson('/api/orders?fields=secret');
        $r->assertStatus(422);
        $this->assertSame('VALIDATION_FAILED', $r->json('error.code'));
        $this->assertSame('secret', $r->json('error.details.field'));
    }

    public function test_disallowed_operator_returns_422(): void
    {
        // number allows only [Eq, Contains]; gte должен 422
        $r = $this->getJson('/api/orders?filter[number][gte]=A-1000');
        $r->assertStatus(422);
        $this->assertSame('number', $r->json('error.details.field'));
        $this->assertSame('gte', $r->json('error.details.operator'));
    }

    public function test_unknown_saved_view_returns_422(): void
    {
        $r = $this->getJson('/api/orders?savedView=hidden');
        $r->assertStatus(422);
        $this->assertSame('hidden', $r->json('error.details.savedView'));
    }

    public function test_per_page_out_of_range_returns_422(): void
    {
        $r = $this->getJson('/api/orders?per_page=999');
        $r->assertStatus(422);
        $body = $r->json();
        $this->assertSame('VALIDATION_FAILED', $body['error']['code']);
        $this->assertSame(999, $body['error']['details']['per_page']);
    }

    public function test_page_zero_returns_422(): void
    {
        $r = $this->getJson('/api/orders?page=0');
        $r->assertStatus(422);
    }

    public function test_unknown_include_returns_422(): void
    {
        $r = $this->getJson('/api/orders?include=undocumented');
        $r->assertStatus(422);
        $this->assertSame('undocumented', $r->json('error.details.include'));
    }
}
