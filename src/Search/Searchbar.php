<?php

declare(strict_types=1);

namespace Gaffer\Search;

/**
 * What a searchbar template needs: the endpoint and token its form searches with, and the
 * archive URL its "all results" link opens (with ?q=). Search::searchbar() makes it.
 */
final readonly class Searchbar
{
    /**
     * @param bool $available false without the search plugin (nothing to search)
     * @param string $endpoint the URL the browser searches
     * @param string $token signs what the browser may ask for
     * @param string $results_url the archive's URL for "all results" (the browser adds ?q=)
     *
     * @internal
     */
    public function __construct(
        public bool $available,
        public string $endpoint,
        public string $token,
        public string $results_url,
    ) {}
}
