<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Paths;
use LogicException;

final class PathsTest extends TestCase
{
    public function test_base_throws_before_configure(): void
    {
        $this->expectException(LogicException::class);

        Paths::base();
    }

    public function test_base_and_paths_inside_it(): void
    {
        Paths::set_base(self::THEME . '/');

        self::assertSame(self::THEME, Paths::base());
        self::assertSame(self::THEME . '/views/x.svg', Paths::base('views/x.svg'));
        self::assertSame(self::THEME . '/views/x.svg', Paths::base('/views/x.svg'));
    }

    public function test_defaults_without_config(): void
    {
        Paths::set_base(self::THEME);

        self::assertSame(self::THEME . '/views', Paths::views());
        self::assertSame(self::THEME . '/inc', Paths::includes());
        self::assertSame(self::THEME . '/blocks', Paths::blocks());
        self::assertSame(self::THEME . '/ajax', Paths::ajax());
        self::assertSame(self::THEME . '/storage/cache/views', Paths::twig_cache());
        self::assertSame([], Paths::view_namespaces());
    }

    public function test_config_paths_are_relative_to_the_theme_unless_absolute(): void
    {
        self::boot_fixture();

        self::assertSame(self::THEME . '/var/storage', Paths::storage());
        self::assertSame(self::THEME . '/var/storage/cache/views', Paths::twig_cache());
        self::assertSame('/srv/elsewhere/public', Paths::public());
        self::assertSame(
            ['block' => self::THEME . '/blocks', 'abs' => '/srv/elsewhere/views'],
            Paths::view_namespaces(),
        );
    }
}
