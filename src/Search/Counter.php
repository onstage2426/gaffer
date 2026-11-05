<?php

declare(strict_types=1);

namespace Gaffer\Search;

/**
 * What the browser needs to count documents itself: the endpoint and a token that asks for no
 * more than the total. Search::counter() makes it. `@gaffer/search.js`'s createCounter() uses it.
 */
final readonly class Counter
{
    /** @internal */
    public function __construct(
        public bool $available,
        public string $endpoint,
        public string $token,
    ) {}
}
