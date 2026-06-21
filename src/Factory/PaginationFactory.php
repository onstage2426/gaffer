<?php

declare(strict_types=1);

namespace Gaffer\Factory;

use WP_Query;
use Gaffer\Support\Types\Pagination;

class PaginationFactory
{
    public function from(mixed $data): ?Pagination
    {
        return match(true) {
            $data instanceof WP_Query => $this->from_query($data),
            default                   => null,
        };
    }

    public function from_query(WP_Query $query): Pagination
    {
        return $this->build($query);
    }

    public function build(?WP_Query $query): ?Pagination
    {
        if (!$query instanceof \WP_Query) {
            return null;
        }

        return Pagination::build($query);
    }
}
