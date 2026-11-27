<?php

declare(strict_types=1);

namespace Gaffer\Console\Upgrade\Rules;

use Gaffer\Console\Upgrade\Rule;
use Gaffer\Console\Upgrade\Theme;

/**
 * CHANGELOG 2026-11-27: the search objects' endpoint and token are Gaffer's (internal); attrs()
 * writes them (Counter::attrs() is new). Templates that read them are by hand: how a theme's own
 * script gets them (x-data arguments, JSON) is its own code.
 *
 * @internal
 */
final class SearchValues extends Rule
{
    #[\Override]
    public function id(): string
    {
        return '2026-11-27-search-values';
    }

    #[\Override]
    public function describe(): string
    {
        return 'search objects: .endpoint/.token → attrs() (createCounter(element) for a counter)';
    }

    #[\Override]
    public function changes(Theme $theme): array
    {
        return [];
    }

    #[\Override]
    public function by_hand(Theme $theme): array
    {
        $todo = [];
        foreach ($theme->files(['views', 'blocks'], ['twig']) as $path) {
            if (preg_match('/\b\w+\.(?:endpoint|token)\b/', (string) $theme->read($path)) === 1) {
                $todo[] = "{$path} reads a search object's endpoint or token, which are Gaffer's now: write {{ x.attrs() }} on the form or element instead (a counter: createCounter(element) reads them; CHANGELOG 2026-11-27).";
            }
        }
        foreach ($theme->files(['app', 'inc', 'blocks', 'woocommerce'], ['php']) as $path) {
            if (preg_match('/(?:counter|searchbar|archive)\w*(?:\([^)]*\))?\??->(?:endpoint|token)\b/i', (string) $theme->read($path)) === 1) {
                $todo[] = "{$path} reads a search object's endpoint or token, which are Gaffer's now: pass the object to the template and write {{ x.attrs() }} (CHANGELOG 2026-11-27).";
            }
        }

        return $todo;
    }
}
