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
