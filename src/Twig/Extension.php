<?php

declare(strict_types=1);

namespace Gaffer\Twig;

use Twig\Attribute\AsTwigFunction;
use Twig\Attribute\AsTwigFilter;

class Extension
{
    #[AsTwigFunction("ajax")]
    public static function ajax(string $action): string
    {
        return bs_ajax($action);
    }

    #[AsTwigFunction("relative_link")]
    public static function relativeLink(string $link): string
    {
        return preg_replace("|^(https?:)?//[^/]+(/?.*)|i", '$2', $link);
    }

    #[AsTwigFilter("values")]
    public static function values(array $array): array
    {
        return array_values($array);
    }

    #[AsTwigFunction("shortcode")]
    public static function shortcode(string $shortcode): string
    {
        return do_shortcode($shortcode);
    }

    #[AsTwigFunction("background")]
    public static function background(?string $color): string
    {
        return match ($color) {
            "primary" => "bg-white",
            "secondary" => "bg-light",
            default => "bg-white",
        };
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
