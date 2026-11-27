<?php

declare(strict_types=1);

namespace Theme\Ajax\CartCount;

use Gaffer\AjaxAction;
use Theme\Cart;

class CartCount extends AjaxAction
{
    public const string METHOD = "GET";

    public function run(): void
    {
        Cart::sync_count_cookie(); // before output
        echo WC()->cart->get_cart_contents_count();
    }
}
