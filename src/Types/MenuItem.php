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
    /** @var list<MenuItem> */
    private array $children = [];

    private bool $current_ancestor = false;

    /** @internal Built by Menu. */
    public function __construct(public readonly WP_Post $wp) {}

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

    /** @internal */
    public function parent_id(): int
    {
        return (int) $this->field('menu_item_parent');
    }

    /**
     * Menu fields (title, url, target, classes, menu_item_parent) are added to
     * the WP_Post by wp_setup_nav_menu_item(), so they aren't declared on WP_Post.
     */
    private function field(string $name): mixed
    {
        return $this->wp->{$name} ?? null;
    }

    /** @internal */
    public function add_child(MenuItem $item): void
    {
        $this->children[] = $item;
    }

    /** @internal */
    public function mark_current_ancestor(): void
    {
        $this->current_ancestor = true;
    }
}
