<?php

declare(strict_types=1);

namespace Gaffer\Types;

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

        return is_array($items) && $items !== [] ? new self(MenuItem::tree($items)) : null;
    }

    /** @return list<MenuItem> */
    public function items(): array
    {
        return $this->items;
    }
}
