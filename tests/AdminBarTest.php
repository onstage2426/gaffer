<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\AdminBar;
use PHPUnit\Framework\Attributes\DataProvider;

final class AdminBarTest extends TestCase
{
    /** @return iterable<string, array{string, bool, bool, string}> */
    public static function states(): iterable
    {
        yield 'debug in production' => ['production', true, true, 'red'];
        yield 'debug in staging' => ['staging', true, false, 'red'];
        yield 'debug locally' => ['local', true, false, 'green'];
        yield 'debug in development' => ['development', true, true, 'green'];
        yield 'cache off in production' => ['production', false, false, 'orange'];
        yield 'production, cached' => ['production', false, true, 'green'];
        yield 'cache off locally' => ['local', false, false, 'green'];
        yield 'cache off in staging' => ['staging', false, false, 'green'];
    }

    #[DataProvider('states')]
    public function test_status_color(string $environment, bool $debug, bool $cache, string $color): void
    {
        self::assertSame($color, AdminBar::status($environment, $debug, $cache)['color']);
    }

    public function test_the_worst_state_wins(): void
    {
        $green = ['color' => 'green', 'reason' => 'OK'];
        $orange = ['color' => 'orange', 'reason' => 'gaffer-search: products: building'];
        $red = ['color' => 'red', 'reason' => 'Debug is on in production'];

        self::assertSame($green, AdminBar::worst([$green, $green]));
        self::assertSame($orange, AdminBar::worst([$green, $orange]));
        self::assertSame($red, AdminBar::worst([$orange, $red, $green]));
        self::assertSame($orange, AdminBar::worst([$orange, ['color' => 'orange', 'reason' => 'second']]), 'the first of the worst');
    }
}
