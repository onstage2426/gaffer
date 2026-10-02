<?php

declare(strict_types=1);

namespace Gaffer\Types;

use Gaffer\Config;
use WP_Post;

/**
 * A post of any type, wrapping its WP_Post. Raw fields live on `wp`
 * (`$post->wp->post_name`, `post.wp.post_name`); methods only exist where they
 * add something (filters, URLs, related objects).
 *
 * `theme.types` maps post types to subclasses, so Post::from() can return e.g.
 * a theme's Product. Attachments become Attachment or Image.
 */
class Post
{
    private ?string $link = null;

    protected function __construct(public readonly WP_Post $wp) {}

    /**
     * The post with this ID, as its mapped class. Null when it doesn't exist,
     * or when called on a subclass (Product::from()) and the post isn't one.
     */
    public static function from(int $id): ?static
    {
        $wp = $id > 0 ? \get_post($id) : null;

        return $wp instanceof WP_Post ? self::narrow(self::wrap($wp)) : null;
    }

    /**
     * The current post (the loop's post, or the queried singular post).
     */
    public static function current(): ?static
    {
        $wp = \get_post();

        return $wp instanceof WP_Post ? self::narrow(self::wrap($wp)) : null;
    }

    /**
     * Posts from get_posts($args). On a subclass, only posts of that class.
     *
     * @param array<string, mixed> $args
     * @return list<static>
     */
    public static function query(array $args): array
    {
        unset($args['fields']);

        return self::wrap_all(\get_posts($args));
    }

    /**
     * The main query's posts (archives, search results).
     *
     * @return list<static>
     */
    public static function main_query(): array
    {
        global $wp_query;

        return self::wrap_all($wp_query->posts ?? []);
    }

    public function id(): int
    {
        return $this->wp->ID;
    }

    /**
     * Plain text: the_title's entities (&#8217; from texturize, &#038;) are decoded, so
     * Twig's escaping shows them correctly instead of escaping them a second time.
     */
    public function title(): string
    {
        return html_entity_decode(\apply_filters('the_title', $this->wp->post_title, $this->wp->ID), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function link(): string
    {
        return $this->link ??= (\get_permalink($this->wp) ?: '');
    }

    public function content(): string
    {
        return \apply_filters('the_content', $this->wp->post_content);
    }

    /**
     * Plain text, entities decoded (like title()).
     */
    public function excerpt(): string
    {
        return html_entity_decode(\get_the_excerpt($this->wp), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function date(?string $format = null): string
    {
        return (string) \get_the_date($format ?? '', $this->wp);
    }

    public function modified_date(?string $format = null): string
    {
        return (string) \get_the_modified_date($format ?? '', $this->wp);
    }

    public function parent(): ?Post
    {
        return self::from($this->wp->post_parent);
    }

    /** @return list<Post> */
    public function children(): array
    {
        return self::query([
            'post_type' => $this->wp->post_type,
            'post_parent' => $this->wp->ID,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
        ]);
    }

    /** @return list<Term> */
    public function terms(string $taxonomy): array
    {
        $terms = \get_the_terms($this->wp, $taxonomy);

        return is_array($terms)
            ? array_values(array_filter(array_map(static fn(\WP_Term $t): ?Term => Term::from($t->term_id), $terms)))
            : [];
    }

    /**
     * Parsed blocks, without the empty "null" blocks between them.
     *
     * @return list<array<string, mixed>>
     */
    public function blocks(): array
    {
        return array_values(array_filter(
            \parse_blocks($this->wp->post_content),
            static fn(array $block): bool => $block['blockName'] !== null,
        ));
    }

    public function meta(string $key): mixed
    {
        return \get_post_meta($this->wp->ID, $key, true);
    }

    public function thumbnail(): ?Image
    {
        return Image::from((int) \get_post_thumbnail_id($this->wp));
    }

    public function is_current(): bool
    {
        return $this->wp->ID === \get_the_ID();
    }

    /**
     * The class for a WP_Post: attachments by mime type, everything else via theme.types.
     */
    private static function wrap(WP_Post $wp): Post
    {
        $class = match (true) {
            $wp->post_type === 'attachment' => str_starts_with($wp->post_mime_type, 'image/') ? Image::class : Attachment::class,
            default => (Config::get('theme.types') ?? [])[$wp->post_type] ?? Post::class,
        };

        return new $class($wp);
    }

    /**
     * @param array<mixed> $posts
     * @return list<static>
     */
    private static function wrap_all(array $posts): array
    {
        $wrapped = [];
        foreach ($posts as $wp) {
            $post = $wp instanceof WP_Post ? self::narrow(self::wrap($wp)) : null;
            if ($post !== null) {
                $wrapped[] = $post;
            }
        }

        return $wrapped;
    }

    private static function narrow(Post $post): ?static
    {
        return $post instanceof static ? $post : null;
    }
}
