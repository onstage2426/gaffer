<?php

declare(strict_types=1);

namespace Gaffer\Console;

use FilesystemIterator;
use Gaffer\Facades\Paths;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The theme's own source files: everything except dependencies, build output and storage.
 */
final class ThemeFiles
{
    private const array SKIP = ['vendor', 'node_modules', 'public', 'storage', '.git'];

    /**
     * @param list<string> $extensions e.g. ['php', 'twig']
     * @return list<string> absolute paths, sorted
     */
    public static function find(array $extensions, ?string $dir = null): array
    {
        $root = $dir ?? Paths::base();
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static fn(SplFileInfo $file): bool => !($file->isDir() && in_array($file->getFilename(), self::SKIP, true)),
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
