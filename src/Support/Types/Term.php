<?php

declare(strict_types=1);

namespace Gaffer\Support\Types;

use WP_Term;
use Gaffer\Support\Facades\Theme;
use Gaffer\Support\Types\Attachment;
use Gaffer\Support\Types\Image;
use Gaffer\Factory\TermFactory;
use Gaffer\Support\Traits\ClassImporter;

class Term
{
    use ClassImporter;

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

    public function thumbnail(): Image
    {
        $id = $this->thumbnail_id();
        return Theme::get_image($id > 0 ? $id : null);
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

        return new TermFactory()->from_id($parent_id);
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
