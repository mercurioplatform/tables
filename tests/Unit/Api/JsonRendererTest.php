<?php

namespace Mercurio\Tables\Tests\Unit\Api;

use Mercurio\Tables\Api\FormatMode;
use Mercurio\Tables\Api\JsonRenderer;
use Mercurio\Tables\Api\ParsedApiQuery;
use Mercurio\Tables\Source\Query;
use Mercurio\Tables\Tests\Fixtures\JsonApi\RichFieldsResource;
use Mercurio\Tables\Tests\TestCase;

/**
 * @covers \Mercurio\Tables\Api\JsonRenderer
 */
final class JsonRendererTest extends TestCase
{
    private RichFieldsResource $resource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resource = new RichFieldsResource;
    }

    /**
     * @param  array<int, string>|null  $fields
     * @param  array<string, FormatMode>  $perFieldFormats
     * @return array<string, mixed>
     */
    private function render(FormatMode $global, array $perFieldFormats, ?array $fields = null): array
    {
        $config = $this->resource->resolveApiConfig();
        $allowFields = (array) $config->getAllowFields();

        $parsed = new ParsedApiQuery(
            query: new Query,
            includes: ['data', 'page'],
            fields: $fields ?? $allowFields,
            format: $global,
            perPage: 10,
            page: 1,
            perFieldFormats: $perFieldFormats,
        );

        $source = $this->resource->resolveSource()->withQuery($parsed->query);
        $page = $source->page($parsed->page, $parsed->perPage);

        return app(JsonRenderer::class)->render($page, $source, $this->resource, $parsed, $config);
    }

    public function test_per_field_override_wins_over_global(): void
    {
        $result = $this->render(FormatMode::Both, ['status' => FormatMode::Raw]);

        $row = $result['data'][0];

        $this->assertIsArray($row['total'], 'global=Both should make total a {raw,display,tone} array');
        $this->assertArrayHasKey('raw', $row['total']);
        $this->assertArrayHasKey('display', $row['total']);
        $this->assertArrayHasKey('tone', $row['total']);

        $this->assertIsString($row['status'], 'per-field=Raw should make status a scalar string');
        $this->assertSame('paid', $row['status']);
    }

    public function test_per_field_for_field_outside_sparse_fieldset_is_silently_ignored(): void
    {
        $result = $this->render(
            global: FormatMode::Raw,
            perFieldFormats: ['status' => FormatMode::Both],
            fields: ['id', 'total'],
        );

        $row = $result['data'][0];

        $this->assertSame(['id', 'total'], array_keys($row));
        $this->assertArrayNotHasKey('status', $row);
    }
}
