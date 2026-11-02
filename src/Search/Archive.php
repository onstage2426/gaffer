<?php

declare(strict_types=1);

namespace Gaffer\Search;

use Twig\Markup;

/**
 * What an archive template shows (the shop, a product category, its search results): the first
 * page's cards and what the browser needs to continue the same search (endpoint, token, the
 * initial results). Search::archive() makes it.
 */
final readonly class Archive
{
    /**
     * @param list<Markup> $cards the first page, rendered at index time
     * @param array<string, mixed> $results the search response for this URL
     * @param list<array{value: string, label: string}> $sorts
     *
     * @internal
     */
    public function __construct(
        public bool $available,
        public string $endpoint,
        public string $token,
        public string $query,
        public int $total,
        public array $cards,
        public array $results,
        public array $sorts,
        public string $sort,
        public string $card_field,
    ) {}

    /** @internal Without the search plugin: no cards, nothing to search. */
    public static function unavailable(): self
    {
        return new self(false, '', '', '', 0, [], Engine::response(null), [], '', 'card');
    }

    /**
     * The results as JSON for a <script type="application/json"> the archive component starts
     * from: escaped so it can't end the script tag.
     */
    public function initial(): Markup
    {
        return new Markup((string) json_encode($this->results, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'UTF-8');
    }
}
