<?php

declare(strict_types=1);

namespace Gaffer\Facades;

use WP_Post;
use WP_Post_Type;
use WP_Taxonomy;
use WP_Term;

use Gaffer\Types\Attachment;
use Gaffer\Types\Image;
use Gaffer\Types\Post;
use Gaffer\Types\Product;
use Gaffer\Types\PostType;
use Gaffer\Types\Term;
use Gaffer\Types\Taxonomy;
use Gaffer\Types\Pagination;
use Gaffer\Factory\PostFactory;

class Theme
{
    private static ?PostFactory $post_factory = null;

    public static function render(string $name, array $data = []): void
    {
        echo Twig::env()->render(
            $name,
            apply_filters("Theme/ViewData", [
                ...apply_filters("Theme/ViewDataStatic", []),
                ...$data,
            ]),
        );
    }

    public static function get_post(int|WP_Post|null $post = null): ?Post
    {
        $factory = self::$post_factory ??= new PostFactory();

        return isset($post) ? $factory->from($post) : $factory->from(\get_post());
    }

    public static function get_product(int|WP_Post|null $product = null): ?Product
    {
        // Fresh factory per call — variation resolution depends on current $_GET.
        $factory = new PostFactory($_GET);
        $post    = isset($product) ? $factory->from($product) : $factory->from(\get_post());

        return $post instanceof Product ? $post : null;
    }

    public static function get_posts(?array $args = null): array
    {
        global $wp_query;

        $posts   = $args ? get_posts($args) : $wp_query->posts;
        $factory = self::$post_factory ??= new PostFactory();

        return array_map($factory->from_post(...), $posts);
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
