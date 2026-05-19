<?php

namespace Mercurio\Tables\Tests\Feature\JsonApi;

use Illuminate\Support\Facades\Route;
use Mercurio\Tables\Tests\Fixtures\JsonApi\RichFieldsResource;
use Mercurio\Tables\Tests\TestCase;

final class PerFieldFormatApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::tablesApi('/api/rich', RichFieldsResource::class)->name('rich');
    }

    public function test_get_per_field_returns_mixed_shape(): void
    {
        $body = $this->getJson('/api/rich?format[total]=both&format[status]=raw')
            ->assertOk()
            ->json();

        $row = $body['data'][0];

        // total → Both shape via per-field override
        $this->assertIsArray($row['total']);
        $this->assertSame(1500, $row['total']['raw']);
        $this->assertIsString($row['total']['display']);
        $this->assertArrayHasKey('tone', $row['total']);

        // status → scalar (Raw override)
        $this->assertSame('paid', $row['status']);

        // id → scalar (no override, falls back to global default = Raw)
        $this->assertSame(1, $row['id']);
    }

    public function test_post_body_per_field_format(): void
    {
        $body = $this->postJson('/api/rich', ['format' => ['total' => 'both']])
            ->assertOk()
            ->json();

        $row = $body['data'][0];

        $this->assertIsArray($row['total']);
        $this->assertSame(1500, $row['total']['raw']);
        $this->assertIsString($row['total']['display']);
        $this->assertSame(1, $row['id']);
    }

    public function test_star_sentinel_sets_base(): void
    {
        $body = $this->getJson('/api/rich?format[*]=both&format[status]=raw')
            ->assertOk()
            ->json();

        $row = $body['data'][0];

        // base = Both: total and id are array
        $this->assertIsArray($row['total']);
        $this->assertIsArray($row['id']);

        // status: scalar (per-field=Raw wins)
        $this->assertSame('paid', $row['status']);
    }

    public function test_unknown_field_in_format_returns_422(): void
    {
        $response = $this->getJson('/api/rich?format[secret]=both')
            ->assertStatus(422);

        $body = $response->json();

        $this->assertSame('VALIDATION_FAILED', $body['error']['code']);
        $this->assertSame('secret', $body['error']['details']['field']);
        $this->assertArrayHasKey('allowed', $body['error']['details']);
    }

    public function test_invalid_mode_in_format_returns_422(): void
    {
        $response = $this->getJson('/api/rich?format[total]=xml')
            ->assertStatus(422);

        $body = $response->json();

        $this->assertSame('VALIDATION_FAILED', $body['error']['code']);
        $this->assertSame('xml', $body['error']['details']['format']);
        $this->assertSame('total', $body['error']['details']['field']);
        $this->assertSame(['raw', 'formatted', 'both'], $body['error']['details']['allowed']);
    }

    public function test_schema_include_unaffected_by_per_field_format(): void
    {
        $body = $this->getJson('/api/rich?include=schema&format[total]=both')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('format_hints', $body['schema']['fields']['total']);

        // Guard: per-field overrides are NOT advertised in schema.
        $this->assertArrayNotHasKey('available_formats', $body['schema']['fields']['total']);
    }
}
