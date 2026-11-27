<?php

declare(strict_types=1);

namespace Gaffer\Console\Upgrade\Rules;

use Gaffer\Console\Upgrade\Change;
use Gaffer\Console\Upgrade\Rule;
use Gaffer\Console\Upgrade\Theme;

/**
 * CHANGELOG 2026-11-27: Product::add_to_cart() runs WooCommerce's add-to-cart steps. The starter's
 * CartAdd did them by hand (without WooCommerce's check that the product is published); that exact
 * block is replaced. Other hand-written sequences (an action of the theme's own) are by hand.
 *
 * @internal
 */
final class CartAdd extends Rule
{
    /** The starter's block, quotes and spacing as they come. */
    private const string BLOCK = '/\$product_id = \(int\) apply_filters\(\s*["\']woocommerce_add_to_cart_product_id["\'],\s*\$product->id\(\)\s*\);\s*'
        . '\$variation_id = \$product->variation\(\)\?->get_id\(\) \?\? 0;\s*'
        . '\$quantity = wc_stock_amount\(\$quantity\);\s*'
        . '(?:\/\/[^\n]*\n\s*)?'
        . '\$added = apply_filters\(\s*["\']woocommerce_add_to_cart_validation["\'],\s*true,\s*\$product_id,\s*\$quantity,\s*\$variation_id,\s*\$attributes\s*\)\s*'
        . '&& \$cart->add_to_cart\(\s*\$product_id,\s*\$quantity,\s*\$variation_id,\s*\$variation_id \? \$attributes : \[\]\s*\) !== false;/';

    private const string REPLACEMENT = "// WooCommerce's own add-to-cart steps; a refusal leaves its notice for the answer\n        \$added = \$product->add_to_cart(\$quantity) !== null;";

    #[\Override]
    public function id(): string
    {
        return '2026-11-27-cart-add';
    }

    #[\Override]
    public function describe(): string
    {
        return 'cart actions: WooCommerce\'s add-to-cart steps by hand → $product->add_to_cart($quantity)';
    }

    #[\Override]
    public function changes(Theme $theme): array
    {
        $changes = [];
        foreach ($theme->files(['app/Ajax'], ['php']) as $path) {
            $code = (string) $theme->read($path);
            $after = (string) preg_replace(self::BLOCK, self::REPLACEMENT, $code, 1);
            if ($after !== $code) {
                $changes[] = new Change($path, $code, $after);
            }
        }

        return $changes;
    }

    #[\Override]
    public function by_hand(Theme $theme): array
    {
        $todo = [];
        foreach ($theme->files(['app', 'inc'], ['php']) as $path) {
            $code = (string) $theme->read($path);
            if (str_contains($code, 'woocommerce_add_to_cart_validation') && preg_match('/->add_to_cart\(/', $code) === 1) {
                $todo[] = "{$path} runs WooCommerce's add-to-cart steps by hand: \$product->with_variation(\$attributes)->add_to_cart(\$quantity) does them (with the publish check), returning the cart item key or null.";
            }
        }

        return $todo;
    }
}
