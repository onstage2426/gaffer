<?php

declare(strict_types=1);

namespace Gaffer;

use WP_Post;
use WP_Term;
use Gaffer\Facades\Config;
use Gaffer\Types\Post;
use Gaffer\Types\Term;

final class TypeResolver
{
    public static function post(?WP_Post $wp_post): ?Post
    {
        if (!$wp_post instanceof WP_Post) {
            return null;
        }

        $class = (Config::get('theme.types') ?? [])[$wp_post->post_type] ?? Post::class;

        return $class::build($wp_post);
    }

    public static function term(?WP_Term $wp_term): ?Term
    {
        if (!$wp_term instanceof WP_Term) {
            return null;
        }

        $class = (Config::get('theme.terms') ?? [])[$wp_term->taxonomy] ?? Term::class;

        return $class::build($wp_term);
    }
}
