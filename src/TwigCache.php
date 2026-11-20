<?php

declare(strict_types=1);

namespace Gaffer;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The compiled Twig template cache (storage/cache/views).
 *
 * @internal
 */
final class TwigCache
{
    public static function enabled(): bool
    {
        return (bool) Config::get('theme.cache');
    }

    /**
     * Deletes every compiled template. Returns how many files were removed.
     */
    public static function clear(): int
    {
        $files = 0;
        foreach (self::items() as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
                $files++;
            }
        }

        return $files;
    }

    /**
     * @return array{files: int, bytes: int}
     */
    public static function stats(): array
    {
        $stats = ['files' => 0, 'bytes' => 0];
        foreach (self::items() as $item) {
            if ($item->isFile()) {
                $stats['files']++;
                $stats['bytes'] += $item->getSize();
            }
        }

        return $stats;
    }

    /**
     * Everything inside the cache directory, deepest first (so directories
     * come after their contents).
     *
     * @return iterable<SplFileInfo>
     */
    private static function items(): iterable
    {
        $dir = Paths::twig_cache();
        if (!is_dir($dir)) {
            return [];
        }

        /** @var iterable<SplFileInfo> */
        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
    }
}
