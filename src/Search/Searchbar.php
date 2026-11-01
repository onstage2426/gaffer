<?php

declare(strict_types=1);

namespace Gaffer\Search;

/**
 * What a searchbar template needs: the endpoint and token its form searches with, and the
 * archive URL its "all results" link opens (with ?q=). Search::searchbar() makes it.
 */
final readonly class Searchbar
{
    /** @internal */
    public function __construct(
        public bool $available,
        public string $endpoint,
        public string $token,
        public string $results_url,
    ) {}
}
