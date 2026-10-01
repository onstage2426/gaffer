<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Paths;
use LogicException;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;

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

    public function test_the_layout_is_fixed(): void
    {
        Paths::set_base(self::THEME);

        self::assertSame(self::THEME . '/views', Paths::views());
        self::assertSame(self::THEME . '/inc', Paths::includes());
        self::assertSame(self::THEME . '/blocks', Paths::blocks());
        self::assertSame(self::THEME . '/ajax', Paths::ajax());
        self::assertSame(self::THEME . '/public', Paths::public());
        self::assertSame(self::THEME . '/storage', Paths::storage());
        self::assertSame(self::THEME . '/storage/cache/views', Paths::twig_cache());
        self::assertSame(['block' => self::THEME . '/blocks', 'ajax' => self::THEME . '/ajax'], Paths::view_namespaces());
    }

    #[RunInSeparateProcess]
    public function test_storage_can_be_moved_by_the_server(): void
    {
        define('GAFFER_STORAGE', '/var/lib/site-storage/');
        Paths::set_base(self::THEME);

        self::assertSame('/var/lib/site-storage', Paths::storage());
        self::assertSame('/var/lib/site-storage/cache/views', Paths::twig_cache());
        self::assertSame(self::THEME . '/blocks', Paths::blocks());
    }

    public function test_wordpress_root_is_found_above_the_theme(): void
    {
        Paths::set_base(__DIR__ . '/fixtures/site/wp-content/themes/demo');

        self::assertSame(__DIR__ . '/fixtures/site', Paths::wordpress());
    }

    public function test_wordpress_root_missing_throws(): void
    {
        Paths::set_base(self::THEME);

        $this->expectException(RuntimeException::class);
        Paths::wordpress();
    }
}
