<?php

declare(strict_types=1);

namespace Gaffer\Types;

use Gaffer\Config;
use WP_Term;

/**
 * A taxonomy term, wrapping its WP_Term (`$term->wp->slug`, `term.wp.slug`).
 * `theme.terms` maps taxonomies to subclasses.
 */
class Term
{
    use Wraps;

    private ?string $link = null;

    protected function __construct(public readonly WP_Term $wp) {}

    /**
     * The term with this ID, as its mapped class. Null when it doesn't exist,
     * or when called on a subclass and the term isn't one.
     */
    public static function from(int $id): ?static
    {
        return $id > 0 ? self::wrap_one(\get_term($id)) : null;
    }

    /**
     * The queried term (taxonomy archives).
     */
    public static function current(): ?static
    {
        return self::wrap_one(\get_queried_object());
    }

    /**
     * Terms from get_terms($args). On a subclass, only terms of that class.
     *
     * @param array<string, mixed> $args
     * @return list<static>
     */
    public static function query(array $args): array
    {
        unset($args['fields']);
        $terms = \get_terms($args);

        return self::wrap_all(is_array($terms) ? $terms : []);
    }

    public function id(): int
    {
        return $this->wp->term_id;
    }

    /**
     * Plain text: WordPress stores term names HTML-escaped ("A &amp; B"); decoded here
     * so Twig's escaping isn't applied twice.
     */
    public function title(): string
    {
        return html_entity_decode($this->wp->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function link(): string
    {
        if ($this->link === null) {
            $link = \get_term_link($this->wp);
            $this->link = is_string($link) ? $link : '';
        }

        return $this->link;
    }

    public function description(): string
    {
        return \term_description($this->wp->term_id);
    }

    public function parent(): ?Term
    {
        return self::from($this->wp->parent);
    }

    /**
     * Parent, grandparent, ... up to the top-level term: nearest first.
     *
     * @return list<Term>
     */
    public function ancestors(): array
    {
        return array_values(array_filter(array_map(self::from(...), \get_ancestors($this->wp->term_id, $this->wp->taxonomy, 'taxonomy'))));
    }

    /** @return list<Term> */
    public function children(): array
    {
        $ids = \get_term_children($this->wp->term_id, $this->wp->taxonomy);

        return is_array($ids) ? array_values(array_filter(array_map(self::from(...), $ids))) : [];
    }

    public function meta(string $key): mixed
    {
        return \get_term_meta($this->wp->term_id, $key, true);
    }

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
