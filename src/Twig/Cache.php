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
 * Only the web server writes: a CLI process (doctor's renders, an index build) reads what's there
 * and compiles the rest in memory, so a CLI running as another user (root) can't leave folders
 * the web server can't write to, and a CLI on newer code than the web server's (OPcache) can't
 * remove the folder the web server uses.
 *
 * @internal
 */
final class Cache extends FilesystemCache
{
    private readonly string $folder;

    /**
     * @param list<string> $files the theme's Twig extension files (TwigCache::extension_files())
     */
    public function __construct(string $root, array $files = [], private readonly bool $write = PHP_SAPI !== 'cli')
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
        if (!$this->write) {
            return; // Twig compiles it in memory (eval) when the class isn't there
        }
        $first = !is_dir($this->folder);
        try {
            parent::write($key, $content);
        } catch (\RuntimeException) {
            return;
        }
        if ($first) {
            TwigCache::keep_only($this->folder);
        }
    }
}
