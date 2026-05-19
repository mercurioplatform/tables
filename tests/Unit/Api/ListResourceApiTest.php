<?php

namespace Mercurio\Tables\Tests\Unit\Api;

use Mercurio\Tables\Api\ApiConfig;
use Mercurio\Tables\Tests\Fixtures\JsonApi\TestOrdersResource;
use Mercurio\Tables\Tests\TestCase;

final class ListResourceApiTest extends TestCase
{
    public function test_default_api_returns_apiconfig_make(): void
    {
        $resource = new DefaultApiResource;

        $this->assertInstanceOf(ApiConfig::class, $resource->api());
        $this->assertNull($resource->api()->getAllowFields());
        $this->assertNull($resource->api()->getAllowSavedViews());
    }

    public function test_resolve_api_config_fills_allow_fields_from_fields_memo(): void
    {
        $resource = new DefaultApiResource;
        $config = $resource->resolveApiConfig();

        $this->assertSame(['id', 'name'], $config->getAllowFields());
    }

    public function test_resolve_api_config_fills_allow_saved_views(): void
    {
        $resource = new TestOrdersResource;
        $config = $resource->resolveApiConfig();

        // override в TestOrdersResource::api() — только ['all','paid']
        $this->assertSame(['all', 'paid'], $config->getAllowSavedViews());
        $this->assertSame(['id', 'number', 'status', 'total', 'customer'], $config->getAllowFields());
    }

    public function test_explicit_api_override_takes_priority(): void
    {
        $resource = new TestOrdersResource;
        $config = $resource->resolveApiConfig();

        $this->assertSame(2, $config->getDefaultPerPage());
        $this->assertSame(10, $config->getMaxPerPage());
    }

    public function test_resolve_api_config_is_memoized(): void
    {
        $resource = new TestOrdersResource;
        $a = $resource->resolveApiConfig();
        $b = $resource->resolveApiConfig();

        $this->assertSame($a, $b);
    }
}
