<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\AdminBar;
use PHPUnit\Framework\Attributes\DataProvider;

final class AdminBarTest extends TestCase
{
    /** @return iterable<string, array{string, bool, string}> */
    public static function states(): iterable
    {
        yield 'debug in production' => ['production', true, 'red'];
        yield 'debug in staging' => ['staging', true, 'red'];
        yield 'debug locally' => ['local', true, 'green'];
        yield 'debug in development' => ['development', true, 'green'];
        yield 'production' => ['production', false, 'green'];
    }

    #[DataProvider('states')]
    public function test_status_color(string $environment, bool $debug, string $color): void
    {
        self::assertSame($color, AdminBar::status($environment, $debug)['color']);
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
