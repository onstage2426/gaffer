<?php

declare(strict_types=1);

namespace Gaffer;

/**
 * The theme's fixed layout: views/, inc/, blocks/, fields/, app/Ajax/, public/, storage/.
 */
final class Paths
{
    private static ?string $base = null;

    private static ?string $wordpress = null;

    /** @internal */
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

    /** @internal */
    public static function views(): string
    {
        return self::base('views');
    }

    /** @internal */
    public static function includes(): string
    {
        return self::base('inc');
    }

    /** @internal */
    public static function blocks(): string
    {
        return self::base('blocks');
    }

    /** @internal */
    public static function fields(): string
    {
        return self::base('fields');
    }

    /** @internal */
    public static function ajax(): string
    {
        return self::base('app/Ajax');
    }

    /** @internal */
    public static function public(): string
    {
        return self::base('public');
    }

    /**
     * Twig namespaces next to views/: `@block/x/x.twig`.
     *
     * @return array<string, string>
     *
     * @internal
     */
    public static function view_namespaces(): array
    {
        return ['block' => self::blocks()];
    }

    /**
     * Gaffer's own partials (`@gaffer/forms/meta.twig`): the template halves of pairs Gaffer
     * checks itself, so both change together in an update.
     *
     * @internal
     */
    public static function gaffer_views(): string
    {
        return dirname(__DIR__) . '/resources/views';
    }

    /**
     * The site's own private files (Storage::private_dir()): uploads/gaffer/ in WordPress's uploads
     * folder, per site on a multisite.
     *
     * @internal
     */
    public static function data(): string
    {
        if (!function_exists('wp_upload_dir')) {
            throw new \LogicException('Gaffer\'s private folders are in uploads/gaffer/: they need WordPress loaded (not SHORTINIT).');
        }

        return rtrim((string) \wp_upload_dir(null, false)['basedir'], '/') . '/gaffer';
    }

    /**
     * Files that can be made again: the Twig cache, screenshots, ajax sessions, snapshots. storage/ in the theme,
     * unless the server defines GAFFER_STORAGE in wp-config.php (e.g. when the
     * theme directory isn't writable).
     *
     * @internal
     */
    public static function storage(): string
    {
        return defined('GAFFER_STORAGE') ? rtrim((string) constant('GAFFER_STORAGE'), '/') : self::base('storage');
    }

    /**
     * ACF local JSON (what's made in the ACF UI: post types, taxonomies, options pages, the field
     * groups fields/ can't express): acf-json/ in the theme, committed with it. GAFFER_STORAGE
     * never moves it (it's code, not runtime files).
     *
     * @internal
     */
    public static function acf_json(): string
    {
        return self::base('acf-json');
    }

    /**
     * Where ACF JSON was until 2026-11-22 (storage/acf-json, which GAFFER_STORAGE moved): still
     * loaded, and saved in place, until the theme moves it to acf-json/ (doctor warns).
     *
     * @internal
     */
    public static function acf_json_before(): string
    {
        return self::storage() . '/acf-json';
    }

    /**
     * The theme's ACF JSON files in both folders (acf-json/ first).
     *
     * @internal
     * @return list<string>
     */
    public static function acf_json_files(string $pattern = '*.json'): array
    {
        return [...glob(self::acf_json() . "/{$pattern}") ?: [], ...glob(self::acf_json_before() . "/{$pattern}") ?: []];
    }

    /**
     * Compiled Twig templates (unless theme.cache is false), in a folder per Gaffer version.
     *
     * @internal
     */
    public static function twig_cache(): string
    {
        return self::storage() . '/cache/views';
    }

    /**
     * The WordPress root (the directory with wp-load.php), found by walking up
     * from the theme. Used by ajax.php and the CLI to load WordPress.
     *
     * @internal
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
