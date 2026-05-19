<?php

namespace Mercurio\Tables\Tests\Unit\Api;

use Mercurio\Tables\Api\FormatMode;
use Mercurio\Tables\Tests\TestCase;

final class FormatModeTest extends TestCase
{
    public function test_valid_strings_are_mapped(): void
    {
        $this->assertSame(FormatMode::Raw, FormatMode::fromString('raw'));
        $this->assertSame(FormatMode::Formatted, FormatMode::fromString('formatted'));
        $this->assertSame(FormatMode::Both, FormatMode::fromString('both'));
    }

    public function test_invalid_string_throws_value_error(): void
    {
        $this->expectException(\ValueError::class);
        FormatMode::fromString('xml');
    }

    public function test_try_from_string_returns_null_for_invalid(): void
    {
        $this->assertNull(FormatMode::tryFromString('xml'));
        $this->assertSame(FormatMode::Raw, FormatMode::tryFromString('raw'));
    }
}
