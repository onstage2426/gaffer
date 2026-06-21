<?php

declare(strict_types=1);

namespace Gaffer\Factory;

use WP_Term;
use Gaffer\Support\Types\Term;

class TermFactory
{
    public function from(mixed $data): ?Term
    {
        return match(true) {
            is_int($data)            => $this->from_id($data),
            $data instanceof WP_Term => $this->from_term($data),
            default                  => null,
        };
    }

    public function from_id(int $id): ?Term
    {
        $term = \get_term($id);
        return $this->build($term instanceof \WP_Term ? $term : null);
    }

    public function from_term(WP_Term $term): Term
    {
        return $this->build($term);
    }

    public function build(?WP_Term $term): ?Term
    {
        if (!$term instanceof \WP_Term) {
            return null;
        }

        return Term::build($term);
    }
}
