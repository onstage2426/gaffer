<?php

declare(strict_types=1);

namespace Gaffer\Console\Render;

use Gaffer\Paths;
use InvalidArgumentException;

/**
 * A full copy of the theme next to it in the themes folder (`cp -r blueprint
 * blueprint-stage`, vendor/ included), changed there before the live theme is:
 * renders through the copy's own `gaffer` render the copy (doctor:render runs as
 * the theme whose `gaffer` started it), so staged code can be compared with live
 * on a site without staging.
 */
final class StagedTheme
{
    /**
     * The copy's `gaffer` entry file, from its directory name (or path).
     *
     * @throws InvalidArgumentException when it isn't a copy next to this theme
     */
    public static function entry(string $theme): string
    {
        $dir = realpath(str_contains($theme, '/') ? $theme : dirname(Paths::base()) . "/{$theme}");
        if ($dir === false || !is_dir($dir)) {
            throw new InvalidArgumentException("No theme directory \"{$theme}\" next to this theme (" . dirname(Paths::base()) . ')');
        }
        if (dirname($dir) !== dirname(Paths::base())) {
            throw new InvalidArgumentException("{$dir} isn't next to this theme in " . dirname(Paths::base()) . ': WordPress only loads themes from its themes folder');
        }
        if ($dir === Paths::base()) {
            throw new InvalidArgumentException("\"{$theme}\" is this theme itself, not a copy of it");
        }
        foreach (['gaffer', 'functions.php', 'style.css', 'vendor/autoload.php'] as $file) {
            if (!is_file("{$dir}/{$file}")) {
                throw new InvalidArgumentException("{$dir} has no {$file}: copy the whole theme (cp -r, vendor/ included)");
            }
        }

        return "{$dir}/gaffer";
    }
}
