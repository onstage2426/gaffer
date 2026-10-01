<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Config;

final class ConfigTest extends TestCase
{
    public function test_files_become_top_level_keys_and_non_arrays_are_skipped(): void
    {
        self::boot_fixture();

        self::assertSame(['path', 'theme'], array_keys(Config::all()));
    }

    public function test_dot_notation(): void
    {
        self::boot_fixture();

        self::assertTrue(Config::get('theme.debug'));
        self::assertSame(42, Config::get('theme.nested.deep.value'));
        self::assertSame(['product' => 'Theme\Types\Product'], Config::get('theme.types'));
    }

    public function test_missing_keys_are_null(): void
    {
        self::boot_fixture();

        self::assertNull(Config::get('theme.missing'));
        self::assertNull(Config::get('theme.debug.deeper'));
        self::assertNull(Config::get('nope.at.all'));
    }
}
