<?php

declare(strict_types=1);

namespace Gaffer\Console\Upgrade\Rules;

use Gaffer\Console\Upgrade\Change;
use Gaffer\Console\Upgrade\Rule;
use Gaffer\Console\Upgrade\Theme;

/**
 * CHANGELOG 2026-11-27: MenuItem::has_children() is gone; `item.children` is empty or not (one way
 * to ask). Twig's `x.has_children` and `x.has_children()` become `x.children`, unless the theme has
 * a has_children() method of its own. PHP calls are by hand.
 *
 * @internal
 */
final class MenuChildren extends Rule
{
    private const string TWIG = '/(\b[a-z_][a-z0-9_]*(?:\.[a-z_][a-z0-9_]*)*)\.has_children\b(?:\(\s*\))?/i';

    #[\Override]
    public function id(): string
    {
        return '2026-11-27-menu-children';
    }

    #[\Override]
    public function describe(): string
    {
        return 'templates: item.has_children → item.children (MenuItem::has_children() removed)';
    }

    #[\Override]
    public function changes(Theme $theme): array
    {
        if ($this->own_method($theme)) {
            return [];
        }
        $changes = [];
        foreach ($theme->files(['views', 'blocks'], ['twig']) as $path) {
            $code = (string) $theme->read($path);
            $after = (string) preg_replace(self::TWIG, '$1.children', $code);
            if ($after !== $code) {
                $changes[] = new Change($path, $code, $after);
            }
        }

        return $changes;
    }

    #[\Override]
    public function by_hand(Theme $theme): array
    {
        $own = $this->own_method($theme);
        $todo = [];
        foreach ($theme->files(['app', 'inc', 'woocommerce', 'blocks'], ['php']) as $path) {
            if (!$own && preg_match('/->has_children\(\)/', (string) $theme->read($path)) === 1) {
                $todo[] = "{$path} calls ->has_children(): MenuItem::has_children() is gone, use \$item->children() !== [] (CHANGELOG 2026-11-27).";
            }
        }
        foreach ($theme->files(['views', 'blocks'], ['twig']) as $path) {
            if (preg_match(self::TWIG, (string) $theme->read($path)) === 1) {
                $todo[] = $own
                    ? "{$path} asks has_children, and the theme has a has_children() of its own: where it's a menu item, use item.children (MenuItem::has_children() is gone, CHANGELOG 2026-11-27)."
                    : "{$path} asks has_children: MenuItem::has_children() is gone, use item.children (CHANGELOG 2026-11-27).";
            }
        }

        return $todo;
    }

    /** The theme's own has_children() (another type's): its templates may mean that one. */
    private function own_method(Theme $theme): bool
    {
        foreach ($theme->files(['app'], ['php']) as $path) {
            if (preg_match('/function\s+has_children\s*\(/', (string) $theme->read($path)) === 1) {
                return true;
            }
        }

        return false;
    }
}
