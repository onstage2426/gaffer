<?php

declare(strict_types=1);

namespace Gaffer\Console;

use FilesystemIterator;
use Gaffer\Paths;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The theme's own source files: everything except dependencies, build output and storage.
 */
final class ThemeFiles
{
    /** Directories of the theme root (only there: views/public/ or app/Storage/ are the theme's own). */
    private const array SKIP = ['vendor', 'node_modules', 'public', 'storage', '.git'];

    /**
     * @param list<string> $extensions e.g. ['php', 'twig']
     * @param ?string $dir a directory to search instead of the theme root (SKIP still means the theme root's)
     * @return list<string> absolute paths, sorted; none when $dir doesn't exist (a theme without app/Ajax/)
     */
    public static function find(array $extensions, ?string $dir = null): array
    {
        $root = $dir ?? Paths::base();
        if (!is_dir($root)) {
            return [];
        }
        $theme = rtrim(Paths::base(), '/');
        $skip = array_map(static fn(string $name): string => "{$theme}/{$name}", self::SKIP);
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            // .git and node_modules anywhere are a repository's internals and packages, never theme code.
            static fn(SplFileInfo $file): bool => !($file->isDir() && (in_array($file->getFilename(), ['.git', 'node_modules'], true) || in_array($file->getPathname(), $skip, true))),
        ));

        $files = [];
        foreach ($iterator as $file) {
            if (in_array($file->getExtension(), $extensions, true)) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
