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

    public function title(): string
    {
        return (string) $this->field('title');
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

    public function is_current(): bool
    {
        return rtrim($this->link(), '/') === rtrim(\home_url(\add_query_arg([])), '/');
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
