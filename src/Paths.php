<?php

declare(strict_types=1);

namespace Gaffer;

/**
 * The theme's fixed layout: views/, inc/, blocks/, ajax/, public/, storage/.
 */
final class Paths
{
    private static ?string $base = null;

    private static ?string $wordpress = null;

    public static function set_base(string $dir): void
    {
        self::$base = rtrim($dir, '/');
        self::$wordpress = null;
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
        return self::base('views');
    }

    public static function includes(): string
    {
        return self::base('inc');
    }

    public static function blocks(): string
    {
        return self::base('blocks');
    }

    public static function ajax(): string
    {
        return self::base('ajax');
    }

    public static function public(): string
    {
        return self::base('public');
    }

    /**
     * Twig namespaces next to views/: `@block/x/x.twig` and `@ajax/X/x.twig`.
     *
     * @return array<string, string>
     */
    public static function view_namespaces(): array
    {
        return ['block' => self::blocks(), 'ajax' => self::ajax()];
    }

    /**
     * Writable runtime files: Twig cache, ACF JSON, logs. storage/ in the theme,
     * unless the server defines GAFFER_STORAGE in wp-config.php (e.g. when the
     * theme directory isn't writable).
     */
    public static function storage(): string
    {
        return defined('GAFFER_STORAGE') ? rtrim((string) constant('GAFFER_STORAGE'), '/') : self::base('storage');
    }

    /**
     * Compiled Twig templates (when theme.cache is on).
     */
    public static function twig_cache(): string
    {
        return self::storage() . '/cache/views';
    }

    /**
     * The WordPress root (the directory with wp-load.php), found by walking up
     * from the theme. Used by ajax.php and the CLI to load WordPress.
     */
    public static function wordpress(): string
    {
        if (self::$wordpress !== null) {
            return self::$wordpress;
        }

        for ($dir = self::base(); $dir !== dirname($dir); $dir = dirname($dir)) {
            if (is_file("{$dir}/wp-load.php")) {
                return self::$wordpress = $dir;
            }
        }

        throw new \RuntimeException('wp-load.php not found in any parent directory of ' . self::base());
    }
}
