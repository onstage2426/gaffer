<?php

declare(strict_types=1);

namespace Gaffer\Search;

use Twig\Markup;

/**
 * What a searchbar template needs: the endpoint and token its form searches with, and the
 * archive URL its "all results" link opens (with ?q=). Search::searchbar() makes it.
 *
 * @experimental until gaffer-search 1.0 (API.md, "Experimental").
 */
final readonly class Searchbar
{
    /**
     * Gaffer makes it.
     *
     * @internal
     */
    public function __construct(
        /** false without the search plugin (nothing to search) */
        public bool $available,
        /** @internal written by attrs(), which may change with Gaffer's JavaScript */
        public string $endpoint,
        /** @internal written by attrs(), which may change with Gaffer's JavaScript */
        public string $token,
        /** the archive's URL for "all results" (the browser adds ?q=); '' when the theme has no archive */
        public string $results_url,
    ) {}

    /**
     * The attributes for the search <form> that tell Gaffer's search JavaScript where and with
     * what to search (today data-gaffer-search-endpoint and data-gaffer-search-token):
     * `<form method="get" action="…" {{ search.attrs() }}>`. Gaffer writes and reads them, so they
     * may change in any release. Empty without the plugin.
     */
    public function attrs(): Markup
    {
        return Attributes::form($this->available, $this->endpoint, $this->token);
    }
}
