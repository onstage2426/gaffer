<?php

declare(strict_types=1);

namespace Gaffer\Facades;

use WP_Post;
use WP_Post_Type;
use WP_Taxonomy;
use WP_Term;

use Gaffer\TypeResolver;
use Gaffer\Types\Attachment;
use Gaffer\Types\Image;
use Gaffer\Types\Menu;
use Gaffer\Types\Post;
use Gaffer\Types\PostType;
use Gaffer\Types\Site;
use Gaffer\Types\Term;
use Gaffer\Types\Taxonomy;
use Gaffer\Types\Pagination;

class Theme
{
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string|array $name, array $data = []): void
    {
        $shared = array_map(
            fn($v) => is_callable($v) ? $v() : $v,
            self::$shared,
        );

        $context = apply_filters('Theme/ViewData', [
            'site' => new Site(),
            ...$shared,
            ...apply_filters('Theme/ViewDataStatic', []),
            ...$data,
        ]);

        $env = Twig::env();

        if (is_array($name)) {
            foreach ($name as $template) {
                if ($env->getLoader()->exists($template)) {
                    echo $env->render($template, $context);
                    return;
                }
            }
            return;
        }

        echo $env->render($name, $context);
    }

    public static function get_menu(int|string $menu): ?Menu
    {
        return Menu::from($menu);
    }

    public static function get_post(int|WP_Post|null $post = null): ?Post
    {
        $wp_post = isset($post) ? (is_int($post) ? \get_post($post) : $post) : \get_post();

        return TypeResolver::post($wp_post instanceof WP_Post ? $wp_post : null);
    }

    public static function get_posts(?array $args = null): array
    {
        global $wp_query;

        $posts = $args ? \get_posts($args) : $wp_query->posts;

        return array_map(TypeResolver::post(...), $posts);
    }

    public static function get_post_type(string|WP_Post_Type|null $post_type = null): ?PostType
    {
        if (isset($post_type)) {
            return PostType::from($post_type);
        }

        $post_type = get_post_type();
        return $post_type ? PostType::from_name($post_type) : null;
    }

    public static function get_taxonomy(string|WP_Taxonomy|null $taxonomy = null): ?Taxonomy
    {
        if (isset($taxonomy)) {
            return Taxonomy::from($taxonomy);
        }

        global $wp_query;
        if ($wp_query->queried_object instanceof WP_Term) {
            return Taxonomy::from_name($wp_query->queried_object->taxonomy);
        }

        return null;
    }

    public static function get_term(int|WP_Term|null $term = null): ?Term
    {
        if (isset($term)) {
            return Term::from($term);
        }

        global $wp_query;
        if ($wp_query->queried_object instanceof WP_Term) {
            return Term::from($wp_query->queried_object);
        }

        return null;
    }

    public static function get_terms(string|array $args): array
    {
        return array_map(Term::from(...), get_terms($args));
    }

    public static function get_attachment(int $id): null|Attachment|Image
    {
        return Attachment::from_id($id);
    }

    public static function get_image(int|string|null $id = null): ?Image
    {
        if (is_int($id) || is_string($id)) {
            $attachment = self::get_attachment((int) $id);
            if ($attachment instanceof Image) {
                return $attachment;
            }
        }

        $fallback   = Config::get("theme.image_fallback");
        $attachment = self::get_attachment($fallback);
        if ($attachment instanceof Image) {
            return $attachment;
        }

        throw new \RuntimeException(
            'theme.image_fallback is not set or does not point to an image attachment. Check config/theme.php.',
        );
    }

    public static function get_pagination(): ?Pagination
    {
        global $wp_query;
        return Pagination::build($wp_query);
    }
}
