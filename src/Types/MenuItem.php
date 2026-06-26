<?php

declare(strict_types=1);

namespace Gaffer\Types;

use stdClass;
use WP_Post;

class MenuItem extends Model
{
    public int $ID;
    protected string $title  = '';
    public string $url       = '';
    public string $target    = '';
    public array $classes    = [];
    public string $menu_item_parent = '0';
    public bool $current_item_ancestor = false;

    /** @var MenuItem[] */
    public array $children = [];

    public static function build(WP_Post $item): static
    {
        $mi = new static();
        $mi->import($item);
        $mi->ID = $item->ID;
        return $mi;
    }

    public function id(): int
    {
        return $this->ID;
    }

    public function title(): string
    {
        return \apply_filters('nav_menu_item_title', $this->title, $this->ID, new stdClass(), 0);
    }

    public function link(): string
    {
        return $this->url;
    }

    public function target(): string
    {
        return $this->target ?: '_self';
    }

    public function classes(): array
    {
        return array_values(array_filter($this->classes));
    }

    public function class_string(): string
    {
        return implode(' ', $this->classes());
    }

    public function parent_id(): int
    {
        return (int) $this->menu_item_parent;
    }

    public function is_current(): bool
    {
        $current = rtrim(\home_url(\add_query_arg([])), '/');
        return rtrim($this->url, '/') === $current;
    }

    public function is_current_ancestor(): bool
    {
        return $this->current_item_ancestor;
    }

    public function has_children(): bool
    {
        return !empty($this->children);
    }

    public function children(): array
    {
        return $this->children;
    }

    public function is_external(): bool
    {
        return !str_starts_with($this->url, \home_url())
            && !str_starts_with($this->url, '/');
    }

    public function add_child(self $item): void
    {
        $this->children[] = $item;
    }
}
