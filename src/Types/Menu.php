<?php

declare(strict_types=1);

namespace Gaffer\Types;

use WP_Post;

class Menu
{
    /** @var MenuItem[] */
    private array $items;

    private function __construct(array $items)
    {
        $this->items = $items;
    }

    public static function from(int|string $menu): ?Menu
    {
        if (is_string($menu)) {
            $locations = \get_nav_menu_locations();
            if (isset($locations[$menu])) {
                $menu = $locations[$menu];
            }
        }

        $items = \wp_get_nav_menu_items($menu) ?: [];

        if (empty($items)) {
            return null;
        }

        return new self(self::build_tree($items));
    }

    /** @return MenuItem[] */
    public function items(): array
    {
        return $this->items;
    }

    /** @param WP_Post[] $items */
    private static function build_tree(array $items): array
    {
        $indexed = [];

        foreach ($items as $item) {
            $indexed[$item->ID] = MenuItem::build($item);
        }

        $tree = [];

        foreach ($indexed as $item) {
            $parent = $item->parent_id();
            if ($parent > 0 && isset($indexed[$parent])) {
                $indexed[$parent]->add_child($item);
            } else {
                $tree[] = $item;
            }
        }

        // Walk up from each current item to mark ancestors.
        foreach ($indexed as $item) {
            if (!$item->is_current()) {
                continue;
            }
            $parent_id = $item->parent_id();
            while ($parent_id > 0 && isset($indexed[$parent_id])) {
                $indexed[$parent_id]->current_item_ancestor = true;
                $parent_id = $indexed[$parent_id]->parent_id();
            }
        }

        return $tree;
    }
}
