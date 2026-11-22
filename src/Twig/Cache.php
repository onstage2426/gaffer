<?php

declare(strict_types=1);

namespace Gaffer\Twig;

use Gaffer\Gaffer;
use Gaffer\TwigCache;
use Twig\Cache\FilesystemCache;

/**
 * The Twig cache that can't break a page: a folder per Gaffer version and per state of the theme's
 * Twig extension files (an update or an edited extension starts fresh and the previous folder is
 * removed), and a template it can't write (the folder not writable for the web server, a full
 * disk) renders without the cache, as with the cache off.
 *
 * The extension files count because a compiled template calls the theme's filter and function
 * methods by name: after renaming one (or making it static, giving it a required argument,
 * marking it safe), templates compiled before would call what isn't there any more.
 *
 * @internal
 */
final class Cache extends FilesystemCache
{
    private readonly string $folder;

    /**
     * @param list<string> $files the theme's Twig extension files (TwigCache::extension_files())
     */
    public function __construct(string $root, array $files = [])
    {
        $stamp = Gaffer::version();
        foreach ($files as $file) {
            $stat = @stat($file);
            $stamp .= $stat !== false ? "|{$stat['mtime']}:{$stat['size']}" : '|-';
        }
        $this->folder = rtrim($root, '/') . '/' . substr(md5($stamp), 0, 8);
        parent::__construct($this->folder);
    }

    /** The folder this cache writes to. */
    public function folder(): string
    {
        return $this->folder;
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
