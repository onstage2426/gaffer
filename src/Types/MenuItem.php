<?php

declare(strict_types=1);

namespace Gaffer\Types;

use WP_Post;

/**
 * One nav menu item (a WP_Post that wp_get_nav_menu_items() decorated with
 * title, url, target and classes). Built by Menu.
 */
final class MenuItem
{
    /** @param list<MenuItem> $children */
    private function __construct(
        public readonly WP_Post $wp,
        private readonly array $children,
        private readonly bool $current_ancestor,
    ) {}

    /**
     * @internal Menu::location() builds the tree.
     *
     * The tree of these nav menu items: items whose parent isn't in the list are top level.
     *
     * @param array<WP_Post> $wp_items
     * @return list<MenuItem> the top-level items
     */
    public static function tree(array $wp_items): array
    {
        $ids = array_flip(array_map(static fn(WP_Post $wp): int => $wp->ID, $wp_items));
        $by_parent = [];
        foreach ($wp_items as $wp) {
            $parent = (int) ($wp->menu_item_parent ?? 0);
            $by_parent[isset($ids[$parent]) && $parent !== $wp->ID ? $parent : 0][] = $wp;
        }

        return self::build($by_parent, 0);
    }

    /**
     * @param array<int, list<WP_Post>> $by_parent
     * @return list<MenuItem>
     */
    private static function build(array $by_parent, int $parent): array
    {
        return array_map(static function (WP_Post $wp) use ($by_parent): MenuItem {
            $children = self::build($by_parent, $wp->ID);

            return new self($wp, $children, array_any(
                $children,
                static fn(MenuItem $child): bool => $child->is_current() || $child->is_current_ancestor(),
            ));
        }, $by_parent[$parent] ?? []);
    }

    public function id(): int
    {
        return $this->wp->ID;
    }

    /**
     * Plain text, entities decoded (menu items take their title from the post's
     * get_the_title() or the term's stored name, both with entities).
     */
    public function title(): string
    {
        return html_entity_decode((string) $this->field('title'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function link(): string
    {
        return (string) $this->field('url');
    }

    public function target(): string
    {
        return (string) $this->field('target') ?: '_self';
    }

    /** @return list<string> */
    public function classes(): array
    {
        return array_values(array_filter((array) $this->field('classes')));
    }

    /**
     * The link points at the current page (paths compared, so query strings
     * like ?per_page=24 don't matter; links to other hosts never match).
     */
    public function is_current(): bool
    {
        $link = \wp_parse_url($this->link());
        $host = \wp_parse_url(\home_url(), PHP_URL_HOST);
        if (isset($link['host']) && $link['host'] !== $host) {
            return false;
        }

        $current = (string) \wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        return \untrailingslashit($link['path'] ?? '/') === \untrailingslashit($current);
    }

    public function is_current_ancestor(): bool
    {
        return $this->current_ancestor;
    }

    public function is_external(): bool
    {
        return !str_starts_with($this->link(), \home_url()) && !str_starts_with($this->link(), '/');
    }

    /** @return list<MenuItem> */
    public function children(): array
    {
        return $this->children;
    }

    public function has_children(): bool
    {
        return $this->children !== [];
    }

    /**
     * Menu fields (title, url, target, classes, menu_item_parent) are added to
     * the WP_Post by wp_setup_nav_menu_item(), so they aren't declared on WP_Post.
     */
    private function field(string $name): mixed
    {
        return $this->wp->{$name} ?? null;
    }
}
