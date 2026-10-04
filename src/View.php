<?php

declare(strict_types=1);

namespace Gaffer;

use Closure;
use LogicException;
use Twig\Environment;

/**
 * Renders Twig templates and owns the Twig environment.
 */
final class View
{
    private static ?Environment $env = null;

    /** @var array<string, mixed> */
    private static array $shared = [];

    /** @var array<string, mixed> */
    private static array $resolved = [];

    /** @param array<string, mixed> $data */
    public static function render(string $template, array $data = []): void
    {
        echo self::fetch($template, $data);
    }

    /** @param array<string, mixed> $data */
    public static function fetch(string $template, array $data = []): string
    {
        return self::env()->render($template, [...self::shared(), ...$data]);
    }

    /**
     * Data available to every template. Closures are resolved once, on the first
     * render that needs shared data, then cached for the request.
     */
    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
        unset(self::$resolved[$key]);
    }

    /**
     * The keys passed to share(), without resolving them.
     *
     * @return list<string>
     *
     * @internal
     */
    public static function shared_keys(): array
    {
        return array_keys(self::$shared);
    }

    /** @internal */
    public static function env(): Environment
    {
        return self::$env ?? throw new LogicException('Gaffer::boot() has not run yet, so there is no Twig environment.');
    }

    /**
     * @internal Called by the Twig bootstrapper.
     */
    public static function set_env(Environment $env): void
    {
        self::$env = $env;
    }

    /** @return array<string, mixed> */
    private static function shared(): array
    {
        foreach (self::$shared as $key => $value) {
            if (!array_key_exists($key, self::$resolved)) {
                self::$resolved[$key] = $value instanceof Closure ? $value() : $value;
            }
        }

        return self::$resolved;
    }
}
