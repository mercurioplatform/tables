<?php

namespace Mercurio\Tables\Tests\Unit\Api;

use Mercurio\Tables\Api\ApiConfig;
use Mercurio\Tables\Api\FormatMode;
use Mercurio\Tables\Tests\TestCase;

final class ApiConfigTest extends TestCase
{
    public function test_defaults(): void
    {
        $c = ApiConfig::make();

        $this->assertNull($c->getAllowFields());
        $this->assertNull($c->getAllowSavedViews());
        $this->assertFalse($c->getAllowMutations());
        $this->assertSame(FormatMode::Raw, $c->getDefaultFormat());
        $this->assertSame(['data', 'page'], $c->getDefaultIncludes());
        $this->assertSame(25, $c->getDefaultPerPage());
        $this->assertSame(200, $c->getMaxPerPage());
        $this->assertNull($c->getRateLimit());
    }

    public function test_withers_are_immutable(): void
    {
        $base = ApiConfig::make();
        $next = $base->allowMutations(true);

        $this->assertNotSame($base, $next);
        $this->assertFalse($base->getAllowMutations());
        $this->assertTrue($next->getAllowMutations());
    }

    public function test_fluent_chain(): void
    {
        $c = ApiConfig::make()
            ->allowFields(['id', 'number'])
            ->allowSavedViews(['paid'])
            ->allowMutations(true)
            ->defaultFormat(FormatMode::Both)
            ->defaultIncludes(['data', 'page', 'summary'])
            ->defaultPerPage(50)
            ->maxPerPage(100)
            ->rateLimit('60,1');

        $this->assertSame(['id', 'number'], $c->getAllowFields());
        $this->assertSame(['paid'], $c->getAllowSavedViews());
        $this->assertTrue($c->getAllowMutations());
        $this->assertSame(FormatMode::Both, $c->getDefaultFormat());
        $this->assertSame(['data', 'page', 'summary'], $c->getDefaultIncludes());
        $this->assertSame(50, $c->getDefaultPerPage());
        $this->assertSame(100, $c->getMaxPerPage());
        $this->assertSame('60,1', $c->getRateLimit());
    }

    public function test_per_page_clamps_below_one(): void
    {
        $c = ApiConfig::make()->defaultPerPage(-5)->maxPerPage(0);

        $this->assertSame(1, $c->getDefaultPerPage());
        $this->assertSame(1, $c->getMaxPerPage());
    }
}
