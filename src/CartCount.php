<?php

declare(strict_types=1);

namespace Gaffer;

/**
 * The built-in `GafferCartCount` action (GET): sets the `gaffer_cart` cookie and answers the counts
 * as JSON. For a page from a cache with a cart that has no cookie yet (`@gaffer/cart.js` asks once).
 *
 * @internal
 */
final class CartCount extends AjaxAction
{
    public const string METHOD = 'GET';

    public function run(): void
    {
        if (Cart::cart() === null && function_exists('wc_load_cart')) {
            \wc_load_cart();
        }
        Cart::sync();
        header('Content-Type: application/json');
        echo json_encode(Cart::counts());
    }
}
