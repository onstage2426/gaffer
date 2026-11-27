<?php

declare(strict_types=1);

namespace Gaffer\Types;

use Gaffer\Config;
use Twig\Markup;
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
    use Wraps;

    private ?string $link = null;

    final protected function __construct(public readonly WP_Post $wp) {}

    /**
     * The post with this ID, as its mapped class, whatever its status (a draft, a private or
     * trashed post too: check before showing it to visitors). Null when it doesn't exist,
     * or when called on a subclass (Product::from()) and the post isn't one.
     */
    final public static function from(int $id): ?static
    {
        return $id > 0 ? self::wrap_one(\get_post($id)) : null;
    }

    /**
     * The queried post: the singular post (or page) the request is for, null on
     * archives and the like (but on the blog's posts page WordPress queries that page, so it's
     * returned). Like Term::current(), never the loop's post: that one
     * is Post::from(get_the_ID()) (WooCommerce's loops move it).
     */
    final public static function current(): ?static
    {
        return self::wrap_one(\get_queried_object());
    }

    /**
     * Posts from get_posts($args), with query filters on like any WP_Query (get_posts()
     * turns them off): WPML and Polylang return the current language, and other plugins'
     * pre_get_posts hooks apply. Pass 'suppress_filters' => true to turn them off. On a
     * subclass, only posts of that class: give its `post_type` too (get_posts() defaults to
     * `post`, so Product::query() without it finds nothing). `fields` is ignored (always posts).
     *
     * @param array<string, mixed> $args
     * @return list<static>
     */
    final public static function query(array $args): array
    {
        unset($args['fields']);

        return self::wrap_all(\get_posts(['suppress_filters' => false, ...$args]));
    }

    /**
     * The main query's posts (WordPress's archives and search results). An archive gaffer-search
     * answers has its results in Search::archive(), not here.
     *
     * @return list<static>
     */
    final public static function main_query(): array
    {
        global $wp_query;

        return self::wrap_all($wp_query->posts ?? []);
    }

    final public function id(): int
    {
        return $this->wp->ID;
    }

    /**
     * Plain text: the_title's entities (&#8217; from texturize, &#038;) are decoded, so
     * Twig's escaping shows them correctly instead of escaping them a second time.
     * The_title's filters change the text itself (wptexturize: "60x60" → "60×60");
     * the stored title is $this->wp->post_title.
     */
    final public function title(): string
    {
        return html_entity_decode(\apply_filters('the_title', $this->wp->post_title, $this->wp->ID), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** The permalink ('' only for broken data: a published post always has one). */
    final public function link(): string
    {
        return $this->link ??= (\get_permalink($this->wp) ?: '');
    }

    /**
     * The content as HTML, through the_content filters (`{{ post.content() }}`, no |raw); WordPress's
     * password form instead while the post is password protected and the visitor hasn't entered it.
     * Empty content is falsy in Twig; in PHP it's an object: `(string) $post->content()` for a string.
     */
    final public function content(): Markup
    {
        if (\post_password_required($this->wp)) {
            return new Markup(\get_the_password_form($this->wp), 'UTF-8'); // trusted: WordPress's own form
        }

        return new Markup((string) \apply_filters('the_content', $this->wp->post_content), 'UTF-8'); // trusted: the editors' content, as WordPress prints it
    }

    /**
     * Plain text, entities decoded (like title()).
     */
    final public function excerpt(): string
    {
        return html_entity_decode(\get_the_excerpt($this->wp), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** The publish date, in $format or the site's date format. */
    final public function date(?string $format = null): string
    {
        return (string) \get_the_date($format ?? '', $this->wp);
    }

    /** The last modified date, in $format or the site's date format. */
    final public function modified_date(?string $format = null): string
    {
        return (string) \get_the_modified_date($format ?? '', $this->wp);
    }

    /** The parent post, null for a top-level post. */
    final public function parent(): ?Post
    {
        return Post::from($this->wp->post_parent);
    }

    /**
     * Parent, grandparent, ... up to the top-level post: nearest first.
     *
     * @return list<Post>
     */
    final public function ancestors(): array
    {
        return array_values(array_filter(array_map(Post::from(...), \get_post_ancestors($this->wp))));
    }

    /**
     * Published direct children of the same post type.
     *
     * @return list<Post>
     */
    final public function children(): array
    {
        return self::query([
            'post_type' => $this->wp->post_type,
            'post_parent' => $this->wp->ID,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
        ]);
    }

    /**
     * The post's terms in a taxonomy, as their mapped classes.
     *
     * @return list<Term>
     */
    final public function terms(string $taxonomy): array
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
    final public function blocks(): array
    {
        return array_values(array_filter(
            \parse_blocks($this->wp->post_content),
            static fn(array $block): bool => $block['blockName'] !== null,
        ));
    }

    /**
     * The stored value ('' included), null when the post has no such key.
     */
    final public function meta(string $key): mixed
    {
        return \metadata_exists('post', $this->wp->ID, $key) ? \get_post_meta($this->wp->ID, $key, true) : null;
    }

    /** The featured image, null without one. A theme subclass may override it. */
    public function thumbnail(): ?Image
    {
        return Image::from((int) \get_post_thumbnail_id($this->wp));
    }

    /**
     * Whether this is the queried post (the page being viewed), like current().
     */
    final public function is_current(): bool
    {
        // get_queried_object_id() is a term's or an author's ID on their archives.
        $queried = \get_queried_object();

        return $queried instanceof \WP_Post && $queried->ID === $this->wp->ID;
    }

    /**
     * The class for a WP_Post: attachments by mime type, everything else via theme.types.
     */
    private static function wrapped(mixed $wp): ?Post
    {
        if (!$wp instanceof WP_Post) {
            return null;
        }
        $class = match (true) {
            $wp->post_type === 'attachment' => str_starts_with($wp->post_mime_type, 'image/') ? Image::class : Attachment::class,
            default => (Config::get('theme.types') ?? [])[$wp->post_type] ?? Post::class,
        };

        return new $class($wp);
    }
}
