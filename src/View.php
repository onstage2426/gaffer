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

    private static bool $use_shared = true;

    /** @param array<string, mixed> $data */
    public static function render(string $template, array $data = []): void
    {
        echo self::fetch($template, $data);
    }

    /** @param array<string, mixed> $data */
    public static function fetch(string $template, array $data = []): string
    {
        return self::env()->render($template, self::$use_shared ? [...self::shared(), ...$data] : $data);
    }

    /**
     * Data for the page's templates (the ones render()/fetch() get, not their
     * isolated includes). Closures are resolved once, on the first render, then
     * cached for the request. Ajax actions don't get it: they answer with a
     * fragment, so a menu isn't looked up for nothing.
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

    /**
     * Renders from here on get only the data they're given (Ajax::handle()).
     *
     * @internal
     */
    public static function without_shared(): void
    {
        self::$use_shared = false;
    }

    /** @internal */
    public static function env(): Environment
    {
        return self::$env ?? throw new LogicException('Gaffer::boot() has not run yet, so there is no Twig environment.');
    }

    /**
     * @internal Called by Gaffer::twig().
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
