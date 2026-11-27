<?php

declare(strict_types=1);

namespace Gaffer\Search;

use Twig\Markup;

/**
 * What an archive template shows (the shop, a product category, its search results): the first
 * page's cards and what the browser needs to continue the same search (endpoint, token, the
 * initial results). Search::archive() makes it.
 *
 * @experimental until gaffer-search 1.0 (API.md, "Experimental").
 */
final readonly class Archive
{
    /**
     * @param bool $available false without the search plugin (nothing to search)
     * @param string $endpoint the URL the browser searches
     * @param string $token signs what the browser may ask for
     * @param string $query the visitor's search (?q=), '' without one
     * @param int $total documents found for this URL's search
     * @param list<Markup> $cards the first page, rendered at index time
     * @param array<string, mixed> $results the search response for this URL
     * @param list<string> $sorts the sort values the visitor picks from ('' = the default), labelled by the template
     * @param list<array{name: string, value: string, url: string, current: bool}> $views the layouts to
     *        switch to (the index's `cards`; `value` is the ?view= param, '' for the default), each
     *        this page's search in it; empty with one layout
     * @param string $sort the sort in use ('' = the default)
     * @param string $card_field the document field with this visitor's card in this layout ("card", "card_list")
     * @param string $view the layout in use (?view=), '' for the default
     * @param array<string, mixed> $bounds field => {min, max} over the whole archive (`bounds`)
     * @param array<mixed> $url_query this page's URL query, for page_url()
     *
     * @internal
     */
    public function __construct(
        public bool $available,
        /** @internal written by attrs(), which may change with Gaffer's JavaScript */
        public string $endpoint,
        /** @internal written by attrs(), which may change with Gaffer's JavaScript */
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

    /**
     * @internal Without the search plugin: no cards, nothing to search. With a code (the plugin
     * missing while the theme defines indexes) the results carry it as an engine's `code`.
     */
    public static function unavailable(string $code = ''): self
    {
        return new self(false, '', '', '', 0, [], Engine::response($code !== '' ? ['code' => $code] : null), [], '', 'card');
    }

    /**
     * The results as JSON for a <script type="application/json"> the archive component starts
     * from: escaped so it can't end the script tag. Without the cards: the page shows them
     * already (each would be sent twice), and the component only replaces them after a search.
     */
    public function initial(): Markup
    {
        $results = $this->results;
        if (is_array($results['results'] ?? null)) {
            $card = [$this->card_field => true];
            $results['results'] = array_map(static fn(mixed $hit): mixed => is_array($hit) ? array_diff_key($hit, $card) : $hit, $results['results']);
        }
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

        return new Markup((string) json_encode($results, $flags), 'UTF-8');
    }
}
