<?php

declare(strict_types=1);

namespace Gaffer\Factory;

use WP_Taxonomy;
use Gaffer\Support\Types\Taxonomy;

class TaxonomyFactory
{
    public function from(mixed $data): ?Taxonomy
    {
        return match(true) {
            is_string($data)                => $this->from_name($data),
            $data instanceof WP_Taxonomy    => $this->from_taxonomy($data),
            default                         => null,
        };
    }

    public function from_name(string $name): ?Taxonomy
    {
        return $this->build(\get_taxonomy($name));
    }

    public function from_taxonomy(WP_Taxonomy $taxonomy): Taxonomy
    {
        return $this->build($taxonomy);
    }

    public function build(?WP_Taxonomy $taxonomy): ?Taxonomy
    {
        if (!$taxonomy instanceof \WP_Taxonomy) {
            return null;
        }

        return Taxonomy::build($taxonomy);
    }
}
