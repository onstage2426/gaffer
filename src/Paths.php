<?php

declare(strict_types=1);

namespace Gaffer;

class Paths
{
    private static ?string $base = null;

    public static function set_base(string $dir): void
    {
        self::$base = rtrim($dir, '/');
    }

    /**
     * Theme root, or a path inside it.
     */
    public static function base(string $path = ''): string
    {
        if (self::$base === null) {
            throw new \LogicException('Gaffer::configure() or Gaffer::boot() has not run yet.');
        }

        return $path === '' ? self::$base : self::$base . '/' . ltrim($path, '/');
    }

    public static function views(): string
    {
        return self::from_config('views', 'views');
    }

    /** @return array<string, string> */
    public static function view_namespaces(): array
    {
        return array_map(self::resolve(...), Config::get('path.view_namespaces') ?? []);
    }

    public static function includes(): string
    {
        return self::from_config('includes', 'inc');
    }

    public static function storage(): string
    {
        return self::from_config('storage', 'storage');
    }

    /**
     * Compiled Twig templates (when theme.cache is on).
     */
    public static function twig_cache(): string
    {
        return self::storage() . '/cache/views';
    }

    public static function blocks(): string
    {
        return self::from_config('blocks', 'blocks');
    }

    public static function ajax(): string
    {
        return self::from_config('ajax', 'ajax');
    }

    public static function public(): string
    {
        return self::from_config('public', 'public');
    }

    private static function from_config(string $key, string $default): string
    {
        return self::resolve(Config::get("path.{$key}") ?? $default);
    }

    /**
     * Config paths are relative to the theme root; absolute paths are kept as-is.
     */
    private static function resolve(string $path): string
    {
        return str_starts_with($path, '/') ? rtrim($path, '/') : self::base($path);
    }
}
