<?php

declare(strict_types=1);

namespace Gaffer\Types;

use Gaffer\Config;
use Twig\Markup;
use WP_Term;

/**
 * A taxonomy term, wrapping its WP_Term (`$term->wp->slug`, `term.wp.slug`).
 * `theme.terms` maps taxonomies to subclasses.
 */
class Term
{
    use Wraps;

    private ?string $link = null;

    final protected function __construct(public readonly WP_Term $wp) {}

    /**
     * The term with this ID, as its mapped class. Null when it doesn't exist,
     * or when called on a subclass and the term isn't one.
     */
    final public static function from(int $id): ?static
    {
        return $id > 0 ? self::wrap_one(\get_term($id)) : null;
    }

    /**
     * The queried term (taxonomy archives).
     */
    final public static function current(): ?static
    {
        return self::wrap_one(\get_queried_object());
    }

    /**
     * Terms from get_terms($args). On a subclass, only terms of that class.
     *
     * @param array<string, mixed> $args
     * @return list<static>
     */
    final public static function query(array $args): array
    {
        unset($args['fields']);
        $terms = \get_terms($args);

        return self::wrap_all(is_array($terms) ? $terms : []);
    }

    final public function id(): int
    {
        return $this->wp->term_id;
    }

    /**
     * Plain text: WordPress stores term names HTML-escaped ("A &amp; B"); decoded here
     * so Twig's escaping isn't applied twice.
     */
    final public function title(): string
    {
        return html_entity_decode($this->wp->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** The term's archive URL ('' when it has none). */
    final public function link(): string
    {
        if ($this->link === null) {
            $link = \get_term_link($this->wp);
            $this->link = is_string($link) ? $link : '';
        }

        return $this->link;
    }

    /**
     * The description as HTML, through term_description()'s filters (`{{ term.description() }}`, no
     * |raw). Empty is falsy in Twig; in PHP it's an object: `(string) $term->description()` for a string.
     */
    final public function description(): Markup
    {
        return new Markup((string) \term_description($this->wp->term_id), 'UTF-8'); // trusted: the editors' description, as WordPress prints it
    }

    /** The parent term, null for a top-level term. */
    final public function parent(): ?Term
    {
        return Term::from($this->wp->parent);
    }

    /**
     * Parent, grandparent, ... up to the top-level term: nearest first.
     *
     * @return list<Term>
     */
    final public function ancestors(): array
    {
        return array_values(array_filter(array_map(Term::from(...), \get_ancestors($this->wp->term_id, $this->wp->taxonomy, 'taxonomy'))));
    }

    /**
     * Direct children (not grandchildren), empty ones included, in get_terms()'s
     * order: by name, or the taxonomy's own order where a plugin sets one
     * (WooCommerce's product categories).
     *
     * @return list<Term>
     */
    final public function children(): array
    {
        return self::query([
            'taxonomy' => $this->wp->taxonomy,
            'parent' => $this->wp->term_id,
            'hide_empty' => false,
        ]);
    }

    /**
     * The stored value ('' included), null when the term has no such key.
     */
    final public function meta(string $key): mixed
    {
        return \metadata_exists('term', $this->wp->term_id, $key) ? \get_term_meta($this->wp->term_id, $key, true) : null;
    }

    /**
     * The image in the term's `thumbnail_id` meta (WooCommerce's category image), null
     * without one. A theme subclass may override it.
     */
    public function thumbnail(): ?Image
    {
        return Image::from((int) $this->meta('thumbnail_id'));
    }

    private static function wrapped(mixed $wp): ?Term
    {
        if (!$wp instanceof WP_Term) {
            return null;
        }
        $class = (Config::get('theme.terms') ?? [])[$wp->taxonomy] ?? Term::class;

        return new $class($wp);
    }
}
