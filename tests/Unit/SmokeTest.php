<?php

namespace Mercurio\Tables\Tests\Unit;

use Mercurio\Tables\TablesServiceProvider;
use Mercurio\Tables\Tests\TestCase;

final class SmokeTest extends TestCase
{
    public function test_package_service_provider_boots(): void
    {
        $this->assertNotSame([], $this->app->getProviders(TablesServiceProvider::class));
    }
}
