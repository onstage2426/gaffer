<?php

declare(strict_types=1);

namespace Gaffer\Types;

/**
 * One entry of Pagination::pages(): a page (`number`, `link`, `current`) or a
 * gap where pages are left out (`gap`; number and link null).
 */
final readonly class PaginationPage
{
    /** @internal */
    public function __construct(
        public ?int $number,
        public ?string $link,
        public bool $current,
        public bool $gap,
    ) {}
}
