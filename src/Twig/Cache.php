<?php

declare(strict_types=1);

namespace Gaffer\Twig;

use Gaffer\Gaffer;
use Gaffer\TwigCache;
use Twig\Cache\FilesystemCache;

/**
 * The Twig cache that can't break a page: a folder per Gaffer version (an update starts fresh
 * and the previous version's folder is removed), and a template it can't write (the folder not
 * writable for the web server, a full disk) renders without the cache, as with the cache off.
 *
 * @internal
 */
final class Cache extends FilesystemCache
{
    private readonly string $folder;

    public function __construct(string $root)
    {
        $this->folder = rtrim($root, '/') . '/' . substr(md5(Gaffer::version()), 0, 8);
        parent::__construct($this->folder);
    }

    #[\Override]
    public function write(string $key, string $content): void
    {
        $first = !is_dir($this->folder);
        try {
            parent::write($key, $content);
        } catch (\RuntimeException) {
            return; // Twig compiles it in memory (eval) when the class isn't there
        }
        if ($first) {
            TwigCache::keep_only($this->folder);
        }
    }
}
