<?php

declare(strict_types=1);

namespace Theme;

use Gaffer\Types\Post;
use Theme\Types\Product;

/**
 * The bs_cart_count cookie: the header badge reads the cart count from it,
 * because pages are cached.
 */
final class Cart
{
    /**
     * Sets the cookie to the cart's item count. Same lifetime as WooCommerce's
     * session, so it survives a browser restart together with the cart. Call it
     * before any output: WooCommerce only sends its own cookies at shutdown.
     */
    public static function sync_count_cookie(): void
    {
        if (!WC()->cart) {
            return;
        }

        wc_setcookie(
            "bs_cart_count",
            (string) WC()->cart->get_cart_contents_count(),
            time() + (int) apply_filters("wc_session_expiration", 2 * DAY_IN_SECONDS),
        );
    }

    /**
     * Shown in the drawer after adding a product: the three best sellers other
     * than that product, without variable products (they need a choice first).
     *
     * @return list<Post>
     */
    public static function cross_sells(Product $added): array
    {
        return Post::query([
            "post_type" => "product",
            "meta_key" => "total_sales",
            "orderby" => "meta_value_num",
            "order" => "desc",
            "posts_per_page" => 3,
            "post__not_in" => [$added->id()],
            "tax_query" => [
                [
                    "taxonomy" => "product_type",
                    "field" => "slug",
                    "terms" => ["variable"],
                    "operator" => "NOT IN",
                ],
            ],
        ]);
    }
}
