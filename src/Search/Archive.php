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
     * @param list<array{name: string, value: string, url: string, current: bool}> $views the layouts to
     *        switch to (the index's `cards`; `value` is the ?view= param, '' for the default), each
     *        this page's search in it; empty with one layout
     * @param array<string, mixed> $bounds field => {min, max} over the whole archive (`bounds`)
     * @param array<mixed> $url_query this page's URL query, for page_url()
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
        public string $view = '',
        public array $views = [],
        public array $bounds = [],
        private string $base = '',
        private array $url_query = [],
        private string $view_param = '',
    ) {}

    /**
     * A link to another page of this archive's search (1 is the first): its filters, sort, query
     * and page size, the base params (?show=all) and the layout (?view=list) kept. For crawlable
     * page links (seo.index_pages); the browser pages with goTo(). Empty without the plugin.
     */
    public function page_url(int $page): string
    {
        if (!$this->available) {
            return '';
        }
        $url = ArchiveUrl::url($this->base, [...$this->url_query, 'offset' => max(0, $page - 1) * max(1, (int) ($this->results['limit'] ?? 1))]);

        return $this->view_param !== '' ? $url . (str_contains($url, '?') ? '&' : '?') . 'view=' . rawurlencode($this->view_param) : $url;
    }

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
