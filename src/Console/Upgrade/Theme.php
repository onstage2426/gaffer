<?php

declare(strict_types=1);

namespace Gaffer\Console\Upgrade;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The theme an upgrade reads: its files by path relative to the root.
 *
 * @internal
 */
final readonly class Theme
{
    /** @param array<string, ?string> $overlay files as earlier rules of the same run leave them (null: deleted) */
    public function __construct(public string $root, private array $overlay = []) {}

    /**
     * The theme as it is after these changes, without writing them: the next rule of a run reads
     * what the rules before it changed.
     *
     * @param list<Change> $changes
     */
    public function with(array $changes): self
    {
        $overlay = $this->overlay;
        foreach ($changes as $change) {
            $overlay[$change->path] = $change->after;
        }

        return new self($this->root, $overlay);
    }

    public function read(string $path): ?string
    {
        if (array_key_exists($path, $this->overlay)) {
            return $this->overlay[$path];
        }
        $file = "{$this->root}/{$path}";

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    /**
     * The theme's top-level files with the given extensions (WordPress's templates: page.php,
     * single.php, …), sorted.
     *
     * @param list<string> $extensions
     * @return list<string>
     */
    public function root_files(array $extensions): array
    {
        $found = [];
        foreach (glob("{$this->root}/*") ?: [] as $file) {
            if (is_file($file) && in_array(pathinfo($file, PATHINFO_EXTENSION), $extensions, true)) {
                $found[] = basename($file);
            }
        }
        foreach (array_keys($this->overlay) as $path) {
            if (!str_contains($path, '/') && in_array(pathinfo($path, PATHINFO_EXTENSION), $extensions, true)) {
                $found[] = $path;
            }
        }
        $found = array_values(array_filter(array_unique($found), fn(string $path): bool => $this->read($path) !== null));
        sort($found);

        return $found;
    }

    /**
     * Files under the given folders with the given extensions, relative and sorted.
     *
     * @param list<string> $dirs
     * @param list<string> $extensions
     * @return list<string>
     */
    public function files(array $dirs, array $extensions): array
    {
        $found = [];
        foreach ($dirs as $dir) {
            if (!is_dir("{$this->root}/{$dir}")) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$this->root}/{$dir}", FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (in_array($file->getExtension(), $extensions, true) && !str_contains($file->getPathname(), '/node_modules/')) {
                    $found[] = substr($file->getPathname(), strlen($this->root) + 1);
                }
            }
        }
        foreach ($this->overlay as $path => $content) {
            $inside = array_filter($dirs, static fn(string $dir): bool => str_starts_with($path, rtrim($dir, '/') . '/')) !== [];
            if ($inside && in_array(pathinfo($path, PATHINFO_EXTENSION), $extensions, true)) {
                $found[] = $path;
            }
        }
        $found = array_values(array_filter(array_unique($found), fn(string $path): bool => $this->read($path) !== null));
        sort($found);

        return $found;
    }
}
