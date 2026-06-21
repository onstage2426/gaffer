<?php

declare(strict_types=1);

namespace Gaffer\Twig;

use WP_Post;
use WP_Term;

use Twig\Attribute\AsTwigFunction;
use Gaffer\Facades\Config;
use Gaffer\Facades\Theme;
use Gaffer\Types\Image;
use Gaffer\Types\Product;
use Gaffer\Types\Term;

class ThemeExtension
{
    #[AsTwigFunction("config")]
    public static function config(string $key): mixed
    {
        return Config::get($key);
    }

    #[AsTwigFunction("get_product")]
    public static function getProduct(int|WP_Post|null $product = null): ?Product
    {
        return Theme::get_product($product);
    }

    #[AsTwigFunction("get_term")]
    public static function getTerm(int|WP_Term|null $term = null): ?Term
    {
        return Theme::get_term($term);
    }

    #[AsTwigFunction("get_image")]
    public static function getImage(int|string|null $id = null): ?Image
    {
        return Theme::get_image($id);
    }

    #[AsTwigFunction("nav_tree")]
    public static function navTree(int $menu_id): array
    {
        return bs_nav_tree($menu_id);
    }

    #[AsTwigFunction("fuzor_generate_token")]
    public static function fuzorGenerateToken(array $params): string
    {
        return fuzor_generate_token($params);
    }

}
