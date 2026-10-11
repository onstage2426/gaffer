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
        return self::env()->render($template, self::$use_shared ? [...self::shared(array_keys($data)), ...$data] : $data);
    }

    /**
     * Data for the page's templates (the ones render()/fetch() get, not their
     * isolated includes). Closures are resolved once, on the first render that
     * doesn't pass that key itself, then cached for the request: a page can skip
     * a costly one (a large menu on checkout) by passing it, e.g. as null. Ajax actions don't get it: they answer with a
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

    /**
     * @param list<string> $except keys the render passes itself: not resolved
     * @return array<string, mixed>
     */
    private static function shared(array $except): array
    {
        $shared = [];
        foreach (self::$shared as $key => $value) {
            if (in_array($key, $except, true)) {
                continue;
            }
            if (!array_key_exists($key, self::$resolved)) {
                self::$resolved[$key] = $value instanceof Closure ? $value() : $value;
            }
            $shared[$key] = self::$resolved[$key];
        }

        return $shared;
    }
}
