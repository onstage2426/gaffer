<?php

declare(strict_types=1);

namespace Gaffer\Search;

use Twig\Markup;

/**
 * What the browser needs to count documents itself: the endpoint and a token that asks for no
 * more than the total. Search::counter() makes it; attrs() writes it on an element, and
 * `@gaffer/search.js`'s createCounter(element) reads it from there.
 *
 * @experimental until gaffer-search 1.0 (API.md, "Experimental").
 */
final readonly class Counter
{
    /**
     * @param bool $available false without the search plugin (nothing to search)
     * @param string $endpoint the URL the browser searches
     * @param string $token signs what the browser may ask for
     *
     * @internal
     */
    public function __construct(
        public bool $available,
        /** @internal written by attrs(), which may change with Gaffer's JavaScript */
        public string $endpoint,
        /** @internal written by attrs(), which may change with Gaffer's JavaScript */
        public string $token,
    ) {}

    /**
     * The attributes for the element whose script counts (today data-gaffer-search-endpoint and
     * data-gaffer-search-token): `<div x-data="finder($el)" {{ counter.attrs() }}>`, then
     * createCounter(el) in the script. Gaffer writes and reads them, so they may change in any
     * release. Empty without the plugin (createCounter() then counts nothing: null).
     */
    public function attrs(): Markup
    {
        return Attributes::form($this->available, $this->endpoint, $this->token);
    }
}
