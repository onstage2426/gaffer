<?php

declare(strict_types=1);

namespace Gaffer\Types;

use WP_Term;
use Gaffer\Facades\Theme;
use Gaffer\TypeResolver;
use Gaffer\Types\Image;

class Term extends Model
{

    protected string $permalink;
    public int $term_id;
    public string $name;
    public string $slug;
    public int $term_group;
    public int $term_taxonomy_id;
    public string $taxonomy;
    public string $description;
    public int $parent;
    public int $count;
    public string $filter;

    public static function build(WP_Term $wp_term): static
    {
        $term = new static();
        $term->import($wp_term);
        return $term;
    }

    public static function from_id(int $id): ?Term
    {
        $term = \get_term($id);
        return TypeResolver::term($term instanceof WP_Term ? $term : null);
    }

    public static function from(mixed $data): ?Term
    {
        return match(true) {
            is_int($data)            => static::from_id($data),
            $data instanceof WP_Term => TypeResolver::term($data),
            default                  => null,
        };
    }

    public function id(): int
    {
        return $this->term_id;
    }

    public function title(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function description(): string
    {
        return \term_description($this->term_id);
    }

    public function link(): string
    {
        if (isset($this->permalink)) {
            return $this->permalink;
        }
        $link = \get_term_link($this->term_id);
        return $this->permalink = \is_wp_error($link) ? '' : $link;
    }

    public function thumbnail_id(): int
    {
        return (int) $this->meta("thumbnail_id");
    }

    public function thumbnail(): ?Image
    {
        return Theme::get_image($this->thumbnail_id());
    }

    public function taxonomy(): string
    {
        return $this->taxonomy;
    }

    public function parent_id(): int
    {
        return $this->parent;
    }

    public function parent(): ?Term
    {
        $parent_id = $this->parent_id();

        if (0 === $parent_id) {
            return null;
        }

        return static::from_id($parent_id);
    }

    public function children(): array
    {
        $children = \get_term_children($this->id(), $this->taxonomy());
        return is_array($children) ? array_map(Theme::get_term(...), $children) : [];
    }

    public function meta(string $key = ""): mixed
    {
        return \get_term_meta($this->term_id, $key, "" !== $key);
    }
}
