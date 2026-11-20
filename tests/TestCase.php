<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Config;
use Gaffer\Paths;
use PHPUnit\Framework\TestCase as BaseTestCase;
use ReflectionProperty;

abstract class TestCase extends BaseTestCase
{
    protected const string THEME = __DIR__ . '/fixtures/theme';

    /**
     * Gaffer keeps its state in static properties; put them back to the
     * "nothing booted" state so tests don't depend on each other.
     */
    #[\Override]
    protected function setUp(): void
    {
        self::reset(Paths::class, 'base', null);
        self::reset(Config::class, 'config', []);
    }

    protected static function reset(string $class, string $property, mixed $value): void
    {
        new ReflectionProperty($class, $property)->setValue(null, $value);
    }

    protected static function boot_fixture(): void
    {
        Paths::set_base(self::THEME);
        Config::load(self::THEME . '/config');
    }
}
