<?php

declare(strict_types=1);

namespace Gaffer\Twig;

use Twig\Loader\FilesystemLoader;

/**
 * Twig's filesystem loader whose cache key carries each template's modification time and size:
 * any change compiles it again, also one to an older time (a rollback, a copy that keeps its
 * times), which Twig's own freshness check (cache newer than the template) misses.
 *
 * @internal
 */
final class Loader extends FilesystemLoader
{
    #[\Override]
    public function getCacheKey(string $name): string
    {
        $key = parent::getCacheKey($name);
        $path = $this->findTemplate($name, false);
        $stat = is_string($path) ? @stat($path) : false;

        return $stat !== false ? "{$key}:{$stat['mtime']}:{$stat['size']}" : $key;
    }
}
