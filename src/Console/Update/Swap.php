<?php

declare(strict_types=1);

namespace Gaffer\Console\Update;

use Gaffer\Console\ChildProcess;
use RuntimeException;

/**
 * The vendor/ swap of `php gaffer update`: composer works in a staging copy of the theme (its
 * composer files, a copy of vendor/, links to everything else, so the autoloader's paths come out
 * relative as in the theme). The new vendor/ moves next to the running one as `vendor.{id}/`, and
 * `vendor` becomes a relative link to it, replaced in one rename: a request that is running keeps
 * the real path it started with (PHP resolves `__DIR__` through the link) and finishes on the old
 * code; the next ones get the new. Measured 2026-11-27: with two renames of a real folder, a
 * request that loaded a class in between failed (~3% of swaps at 35 requests a second). Targets
 * sit at the theme root, at vendor/'s depth, so Composer's `__DIR__ . '/../..'` is still the theme.
 * Where a link can't be made, the old two renames. A rollback points the link back; updates made
 * before links keep their vendor.old in the update's folder.
 *
 * @internal
 */
final class Swap
{
    /** Theme entries a staging copy doesn't link: composer's own, and what's never autoloaded. */
    private const array SKIP = ['vendor', 'composer.json', 'composer.lock', 'storage', 'node_modules', '.git'];

    /** The theme-root folders a linked vendor/ points to. */
    public const string TARGET = '/^vendor\.\d{8}-\d{6}$/';

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
        // Its contents (vendor/ may be a link to vendor.{id}/: the copy is a folder either way).
        $copy = ChildProcess::run(['cp', '-a', "{$theme}/vendor/.", "{$root}/vendor"], null, 600);
        if ($copy === null || $copy['code'] !== 0) {
            throw new RuntimeException("Can't copy vendor/ to {$root}: " . trim($copy['stderr'] ?? ''));
        }
        foreach (scandir($theme) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && !in_array($entry, self::SKIP, true) && !preg_match(self::TARGET, $entry)) {
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

    /**
     * The staged vendor/ in: as vendor.{id}/ with vendor/ linked to it in one rename (the link's
     * previous target, or the old real folder moved to $dir/vendor.old, is what a rollback goes
     * back to: recorded in $dir/swap.json), or by two renames where links don't work. Then the
     * staged composer.lock. Returns how: 'link' or 'renames'.
     */
    public static function swap(string $theme, string $dir, string $root): string
    {
        $id = basename($dir);
        $target = "vendor.{$id}";
        $link = "{$theme}/.vendor-link-{$id}";
        @unlink($link);
        $linked = @symlink($target, $link); // relative: the web server and the CLI may see the theme under two paths
        if ($linked) {
            if (!rename("{$root}/vendor", "{$theme}/{$target}")) {
                @unlink($link);
                throw new RuntimeException("Can't move the new vendor/ to {$target}/.");
            }
            $previous = is_link("{$theme}/vendor") ? (string) readlink("{$theme}/vendor") : null;
            if ($previous === null) {
                // The first linked update: the real folder goes to the update's folder (as before)
                // and the link takes its place: two renames this once.
                if (!rename("{$theme}/vendor", "{$dir}/vendor.old")) {
                    rename("{$theme}/{$target}", "{$root}/vendor");
                    @unlink($link);
                    throw new RuntimeException("Can't move vendor/ aside.");
                }
            }
            if (!rename($link, "{$theme}/vendor")) {
                $previous === null && rename("{$dir}/vendor.old", "{$theme}/vendor");
                rename("{$theme}/{$target}", "{$root}/vendor");
                @unlink($link);
                throw new RuntimeException("Can't switch vendor/ to {$target}/; the old one is back.");
            }
            file_put_contents("{$dir}/swap.json", json_encode(['format' => 1, 'mode' => 'link', 'target' => $target, 'previous' => $previous], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        } else {
            if (!rename("{$theme}/vendor", "{$dir}/vendor.old")) {
                throw new RuntimeException("Can't move vendor/ aside.");
            }
            if (!rename("{$root}/vendor", "{$theme}/vendor")) {
                rename("{$dir}/vendor.old", "{$theme}/vendor"); // put it back at once
                throw new RuntimeException("Can't move the new vendor/ in; the old one is back.");
            }
            file_put_contents("{$dir}/swap.json", json_encode(['format' => 1, 'mode' => 'renames'], JSON_PRETTY_PRINT) . "\n");
        }
        if (is_file("{$root}/composer.lock")) {
            copy("{$root}/composer.lock", "{$theme}/composer.lock");
        }

        return $linked ? 'link' : 'renames';
    }

    /**
     * The other way: vendor/ as it was before the update (the link pointed back in one rename, or
     * vendor.old renamed back; the update's own vendor/ is kept as vendor.failed or as its
     * vendor.{id}/ until pruned), then the composer files and the build's manifest and stamp.
     */
    public static function back(string $theme, string $dir): void
    {
        $swap = json_decode((string) @file_get_contents("{$dir}/swap.json"), true);
        $previous = is_array($swap) && ($swap['mode'] ?? null) === 'link' && is_string($swap['previous'] ?? null) ? $swap['previous'] : null;
        if ($previous !== null) {
            if (!is_dir("{$theme}/{$previous}")) {
                throw new RuntimeException("{$previous}/, which vendor/ linked to before this update, is gone.");
            }
            $link = "{$theme}/.vendor-link-back-" . basename($dir);
            @unlink($link);
            if (!symlink($previous, $link) || !rename($link, "{$theme}/vendor")) {
                @unlink($link);
                throw new RuntimeException("Can't point vendor/ back to {$previous}/.");
            }
        } else {
            if (!is_dir("{$dir}/vendor.old")) {
                throw new RuntimeException("{$dir} has no vendor.old to put back.");
            }
            // The update's vendor/ is a link or a folder; either way its files end up as vendor.failed.
            // A target left at the theme root would be theme code to the Gaffer this goes back to
            // (one from before links scans every folder but vendor/): it moves into the update's
            // folder, though a worker may still resolve vendor/ to it for realpath_cache_ttl
            // (only right after a failed first linked update).
            $current = is_link("{$theme}/vendor") ? (string) readlink("{$theme}/vendor") : null;
            if ($current !== null ? !unlink("{$theme}/vendor") : (file_exists("{$theme}/vendor") && !rename("{$theme}/vendor", "{$dir}/vendor.failed"))) {
                throw new RuntimeException("Can't move the current vendor/ aside.");
            }
            if (!rename("{$dir}/vendor.old", "{$theme}/vendor")) {
                $current !== null ? symlink($current, "{$theme}/vendor") : rename("{$dir}/vendor.failed", "{$theme}/vendor");
                throw new RuntimeException("Can't move the old vendor/ back; the current one is back.");
            }
            if ($current !== null && preg_match(self::TARGET, $current) && is_dir("{$theme}/{$current}")) {
                rename("{$theme}/{$current}", "{$dir}/vendor.failed");
            }
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

    /**
     * Removes the theme's vendor.{id}/ folders nothing needs: not vendor/'s target, not a kept
     * update's own or previous one. Old ones only (a worker may still resolve vendor/ to a target
     * it cached, up to realpath_cache_ttl): pruning runs at the next update.
     *
     * @param list<string> $kept the update folders that stay
     * @return list<string> the folders removed
     */
    public static function prune_targets(string $theme, array $kept): array
    {
        $needed = [];
        if (is_link("{$theme}/vendor")) {
            $needed[(string) readlink("{$theme}/vendor")] = true;
        }
        foreach ($kept as $dir) {
            $swap = json_decode((string) @file_get_contents("{$dir}/swap.json"), true);
            foreach (['target', 'previous'] as $key) {
                if (is_array($swap) && is_string($swap[$key] ?? null)) {
                    $needed[$swap[$key]] = true;
                }
            }
        }
        $removed = [];
        foreach (scandir($theme) ?: [] as $entry) {
            if (preg_match(self::TARGET, $entry) && !isset($needed[$entry]) && is_dir("{$theme}/{$entry}") && !is_link("{$theme}/{$entry}")) {
                ChildProcess::run(['rm', '-rf', "{$theme}/{$entry}"], null, 300);
                $removed[] = $entry;
            }
        }

        return $removed;
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
