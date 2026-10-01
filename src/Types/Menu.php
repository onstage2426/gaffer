<?php

declare(strict_types=1);

namespace Gaffer\Types;

use WP_Post;

/**
 * A nav menu as a tree of MenuItems, looked up by menu location.
 */
final class Menu
{
    /** @param list<MenuItem> $items */
    private function __construct(private readonly array $items) {}

    /**
     * The menu assigned to a registered location. Null when the location has
     * no menu, or the menu is empty.
     */
    public static function location(string $location): ?Menu
    {
        $menu = \get_nav_menu_locations()[$location] ?? 0;
        $items = $menu ? \wp_get_nav_menu_items($menu) : false;

        return is_array($items) && $items !== [] ? new self(self::build_tree($items)) : null;
    }

    /** @return list<MenuItem> */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * @param array<WP_Post> $wp_items
     * @return list<MenuItem>
     */
    private static function build_tree(array $wp_items): array
    {
        $items = [];
        foreach ($wp_items as $wp) {
            $items[$wp->ID] = new MenuItem($wp);
        }

        $tree = [];
        foreach ($items as $item) {
            $parent = $item->parent_id();
            if ($parent > 0 && isset($items[$parent])) {
                $items[$parent]->add_child($item);
            } else {
                $tree[] = $item;
            }
        }

        // Mark every ancestor of the current item.
        foreach ($items as $item) {
            if (!$item->is_current()) {
                continue;
            }
            for ($parent = $item->parent_id(); $parent > 0 && isset($items[$parent]); $parent = $items[$parent]->parent_id()) {
                $items[$parent]->mark_current_ancestor();
            }
        }

        return $tree;
    }
}
