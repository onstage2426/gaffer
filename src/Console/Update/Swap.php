<?php

declare(strict_types=1);

namespace Gaffer\Console\Update;

use Gaffer\Console\ChildProcess;
use RuntimeException;

/**
 * The vendor/ swap of `php gaffer update`: composer works in a staging copy of the theme (its
 * composer files, a copy of vendor/, links to everything else, so the autoloader's paths come out
 * relative as in the theme), and the new vendor/ replaces the running one by two renames: a site
 * never runs half an update. The old vendor/ stays in the update's folder, so a rollback is the
 * same swap back.
 *
 * @internal
 */
final class Swap
{
    /** Theme entries a staging copy doesn't link: composer's own, and what's never autoloaded. */
    private const array SKIP = ['vendor', 'composer.json', 'composer.lock', 'storage', 'node_modules', '.git'];

    /** The staging root inside $dir: composer.json, composer.lock, a copy of vendor/, links to the rest. */
    public static function stage(string $theme, string $dir): string
    {
        $root = "{$dir}/root";
        if (!mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException("Can't create {$root}.");
        }
        foreach (['composer.json', 'composer.lock'] as $file) {
            if (is_file("{$theme}/{$file}") && !copy("{$theme}/{$file}", "{$root}/{$file}")) {
                throw new RuntimeException("Can't copy {$file} to {$root}.");
            }
        }
        $copy = ChildProcess::run(['cp', '-a', "{$theme}/vendor", "{$root}/vendor"], null, 600);
        if ($copy === null || $copy['code'] !== 0) {
            throw new RuntimeException("Can't copy vendor/ to {$root}: " . trim($copy['stderr'] ?? ''));
        }
        foreach (scandir($theme) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && !in_array($entry, self::SKIP, true)) {
                symlink("{$theme}/{$entry}", "{$root}/{$entry}");
            }
        }

        return $root;
    }

    /**
     * Why the staged autoloader can't run from the theme (null: it can): an absolute path to the
     * staging folder or the theme in composer's generated files (paths must be relative).
     */
    public static function problem(string $theme, string $root): ?string
    {
        foreach (glob("{$root}/vendor/composer/*.php") ?: [] as $file) {
            $code = (string) file_get_contents($file);
            foreach ([$root, $theme] as $path) {
                if (str_contains($code, "'{$path}") || str_contains($code, "\"{$path}")) {
                    return 'vendor/composer/' . basename($file) . " names {$path}: the autoloader wouldn't work after the swap.";
                }
            }
        }

        return null;
    }

    /** theme/vendor → $dir/vendor.old, $root/vendor → theme/vendor, and the staged composer.lock. */
    public static function swap(string $theme, string $dir, string $root): void
    {
        if (!rename("{$theme}/vendor", "{$dir}/vendor.old")) {
            throw new RuntimeException("Can't move vendor/ aside.");
        }
        if (!rename("{$root}/vendor", "{$theme}/vendor")) {
            rename("{$dir}/vendor.old", "{$theme}/vendor"); // put it back at once
            throw new RuntimeException("Can't move the new vendor/ in; the old one is back.");
        }
        if (is_file("{$root}/composer.lock")) {
            copy("{$root}/composer.lock", "{$theme}/composer.lock");
        }
    }

    /** The other way: the vendor/ from before the update back, the new one kept as vendor.failed. */
    public static function back(string $theme, string $dir): void
    {
        if (!is_dir("{$dir}/vendor.old")) {
            throw new RuntimeException("{$dir} has no vendor.old to put back.");
        }
        if (is_dir("{$theme}/vendor") && !rename("{$theme}/vendor", "{$dir}/vendor.failed")) {
            throw new RuntimeException("Can't move the current vendor/ aside.");
        }
        if (!rename("{$dir}/vendor.old", "{$theme}/vendor")) {
            rename("{$dir}/vendor.failed", "{$theme}/vendor");
            throw new RuntimeException("Can't move the old vendor/ back; the current one is back.");
        }
        foreach (['composer.lock', 'composer.json'] as $file) {
            if (is_file("{$dir}/before/{$file}")) {
                copy("{$dir}/before/{$file}", "{$theme}/{$file}");
            }
        }
        foreach (['public/.vite/manifest.json', 'public/.vite/gaffer.json'] as $file) {
            if (is_file("{$dir}/before/{$file}")) {
                copy("{$dir}/before/{$file}", "{$theme}/{$file}");
            }
        }
    }

    /** What a rollback needs besides vendor.old: the composer files and the build's manifest and stamp. */
    public static function keep_before(string $theme, string $dir): void
    {
        foreach (['composer.json', 'composer.lock', 'public/.vite/manifest.json', 'public/.vite/gaffer.json'] as $file) {
            if (is_file("{$theme}/{$file}")) {
                if (!is_dir(dirname("{$dir}/before/{$file}"))) {
                    mkdir(dirname("{$dir}/before/{$file}"), 0700, true);
                }
                copy("{$theme}/{$file}", "{$dir}/before/{$file}");
            }
        }
    }

    /** The staging root goes once the swap is done (its links point into the theme). */
    public static function clean_stage(string $root): void
    {
        ChildProcess::run(['rm', '-rf', $root], null, 300);
    }
}
