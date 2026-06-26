<?php

declare(strict_types=1);

namespace Gaffer\Twig;

use WP_Post;
use WP_Term;

use Twig\Attribute\AsTwigFunction;
use Gaffer\Facades\Config;
use Gaffer\Facades\Theme;
use Gaffer\Types\Image;
use Gaffer\Types\Menu;
use Gaffer\Types\Post;
use Gaffer\Types\Term;

class ThemeExtension
{
    #[AsTwigFunction("config")]
    public static function config(string $key): mixed
    {
        return Config::get($key);
    }

    #[AsTwigFunction("get_post")]
    public static function getPost(int|WP_Post|null $post = null): ?Post
    {
        return Theme::get_post($post);
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

    #[AsTwigFunction("menu")]
    public static function menu(int|string $menu): ?Menu
    {
        return Theme::get_menu($menu);
    }
}
