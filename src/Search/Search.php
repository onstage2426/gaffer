<?php

declare(strict_types=1);

namespace Gaffer\Search;

use Gaffer\Config;
use Gaffer\Types\Post;
use Gaffer\Types\Term;
use Twig\Markup;

/**
 * Search for templates, from the gaffer-search plugin's indexes as config/search.php defines
 * them. Without the plugin everything still renders: unavailable states, no cards.
 */
final class Search
{
    private static ?Archive $archive = null;
    private static bool $archived = false;

    /** Whether the gaffer-search plugin is active and speaks this Gaffer's contract. */
    public static function available(): bool
    {
        return Engine::find() !== null;
    }

    /**
     * The current page as an archive (config/search.php `archive`): the shop, a product category
     * or the shop's search results (?q=). Null on any other page.
     */
    public static function archive(): ?Archive
    {
        if (!self::$archived) {
            self::$archived = true;
            self::$archive = self::make_archive();
        }

        return self::$archive;
    }

    /**
     * Cards from an index, for a slider: the most popular products of a category without the
     * product of the page, say. Products hidden from the catalog are left out.
     *
     * @param list<string> $sort e.g. ['total_sales:desc']
     * @return list<Markup>
     */
    public static function cards(string $index, int $limit, array $sort = [], ?Term $term = null, ?Post $except = null): array
    {
        $engine = Engine::find();
        $field = Indexes::card_field($index);
        if ($engine === null || $field === null) {
            return [];
        }
        $results = $engine->search($index, [
            ...self::scope($index, 'catalog'),
            'limit' => $limit,
            'sort' => $sort,
            'attributes' => ['id', $field],
            ...($term !== null ? ['term' => $term->id()] : []),
            ...($except !== null ? ['filter' => ['id' => ['not' => (string) $except->id()]]] : []),
        ]);

        return self::cards_of($results['results'], $field);
    }

    /**
     * A searchbar's form data: endpoint, token (with these facets and the hit fields its
     * template shows) and the archive's URL for "all results". Products hidden from search are
     * left out.
     *
     * @param list<string> $facets
     * @param list<string> $attributes the fields each hit carries, e.g. ['id', 'url', 'thumbnail', 'price']
     */
    public static function searchbar(string $index, int $limit = 8, array $facets = [], array $attributes = []): Searchbar
    {
        $engine = Engine::find();
        if ($engine === null) {
            return new Searchbar(false, '', '', '');
        }

        return new Searchbar(
            true,
            $engine->endpoint(),
            $engine->token($index, [...self::scope($index, 'search'), 'limit' => $limit, 'facets' => $facets, ...($attributes !== [] ? ['attributes' => $attributes] : [])]),
            self::shop_url() ?? '',
        );
    }

    /** @internal The archive config's index, when the shop's archives come from one. */
    public static function archive_index(): ?string
    {
        $index = Config::get('search.archive.index');

        return is_string($index) && Indexes::get($index) !== null ? $index : null;
    }

    /** @internal Whether this request is a page the archive answers. */
    public static function on_archive(): bool
    {
        return self::archive_index() !== null && function_exists('is_shop') && function_exists('is_product_taxonomy') && (\is_shop() || \is_product_taxonomy());
    }

    /**
     * @internal The archive's first page for this request: without a query, except the params
     * `urls.base_params` names (a category's `?show=all` is another page than the category).
     */
    public static function archive_base(): string
    {
        $base = (string) strtok(\get_pagenum_link(1, false), '?');
        $own = array_intersect_key((array) \wp_unslash($_GET), array_flip(array_map(strval(...), (array) Config::get('search.archive.urls.base_params'))));
        $own = array_filter($own, static fn(mixed $value): bool => is_scalar($value) && (string) $value !== '');

        return $own !== [] ? $base . '?' . http_build_query($own, '', '&', PHP_QUERY_RFC3986) : $base;
    }

    /** @internal */
    public static function shop_url(): ?string
    {
        if (self::archive_index() === null || !function_exists('wc_get_page_id')) {
            return null;
        }
        $url = \get_permalink(\wc_get_page_id('shop'));

        return is_string($url) ? $url : null;
    }

    /** @internal Forget the archive built (tests). */
    public static function reset(): void
    {
        self::$archive = null;
        self::$archived = false;
    }

    private static function make_archive(): ?Archive
    {
        $index = self::archive_index();
        if ($index === null || !self::on_archive()) {
            return null;
        }
        $engine = Engine::find();
        if ($engine === null) {
            return Archive::unavailable();
        }
        $config = (array) Config::get('search.archive');
        $seo = (array) ($config['seo'] ?? []);
        $term = function_exists('is_product_taxonomy') && \is_product_taxonomy() ? Term::current() : null;
        $definition = (array) Indexes::get($index);
        $query = (array) \wp_unslash($_GET);
        $base = self::archive_base();

        // ?view= one of the index's `cards` (a list layout): its own page, with that card.
        $views = array_keys(Indexes::templates($definition));
        $view = is_string($query['view'] ?? null) && in_array($query['view'], $views, true) ? $query['view'] : ($views[0] ?? '');
        $field = Indexes::card_field($index, $view !== '' ? $view : null);
        $per_page = max(1, (int) ($config['per_page'] ?? 24));
        $limits = array_values(array_map(intval(...), (array) ($config['limits'] ?? [])));
        $limit = is_numeric($query['limit'] ?? null) && in_array((int) $query['limit'], $limits, true) ? (int) $query['limit'] : $per_page;

        // What every page of this archive searches: the token signs it, the first page adds the URL's query.
        $request = [
            ...self::scope($index, 'catalog'),
            ...($term !== null ? ['term' => $term->id()] : []),
            'limit' => $per_page,
            'attributes' => ['id', ...($field !== null ? [$field] : [])],
            'default_sort' => array_values(array_map(strval(...), (array) ($config['default_sort'] ?? []))),
            'facets' => array_values(array_map(strval(...), (array) ($config['facets'] ?? []))),
            ...($limits !== [] ? ['limits' => $limits] : []),
            ...array_intersect_key($config, array_flip(['facet_values', 'max_docs'])),
            // A variant's page (business prices) is that visitor's: its token stops working after a day.
            ...(Indexes::variant($definition) !== null ? ['ttl' => DAY_IN_SECONDS] : []),
        ];
        $results = $engine->search($index, [...$request, ...ArchiveUrl::request($query), 'limit' => $limit]);

        $sorts = [];
        foreach ((array) ($config['sorts'] ?? []) as $value => $label) {
            $sorts[] = ['value' => (string) $value, 'label' => (string) $label];
        }
        $sort = is_array($query['sort'] ?? null) ? (string) (reset($query['sort']) ?: '') : '';

        // For search engines: one URL per page (a chosen page size or layout shows the same products).
        $term_field = $term !== null ? ((array) ($definition['taxonomies'] ?? []))[$term->wp->taxonomy] ?? null : null;
        $on_shop = $term === null && function_exists('is_shop') && \is_shop();
        self::$seo = [
            'url' => ArchiveUrl::url($base, array_diff_key($query, ['limit' => true])),
            'indexable' => $view === ($views[0] ?? '') && ArchiveUrl::indexable(
                $query,
                is_string($term_field) ? [$term_field] : [],
                ($seo['index_pages'] ?? false) === true ? $per_page : 0,
                // A filtered page search engines may index is the shop's (its sale page), not a category's or brand's.
                $on_shop ? (array) ($seo['indexable_filters'] ?? []) : [],
            ),
        ];

        // The layouts to switch to: this page's search (first page) in each.
        $links = [];
        if (count($views) > 1) {
            $page = ArchiveUrl::url($base, [...$query, 'offset' => 0]);
            foreach ($views as $i => $name) {
                $links[] = [
                    'name' => $name,
                    'value' => $i === 0 ? '' : $name, // ?view=: none for the default
                    'url' => $i === 0 ? $page : $page . (str_contains($page, '?') ? '&' : '?') . 'view=' . rawurlencode($name),
                    'current' => $name === $view,
                ];
            }
        }

        // The ranges of these fields over the whole archive (a price slider's ends), not the results.
        $fields = array_values(array_map(strval(...), (array) ($config['bounds'] ?? [])));
        $bounds = $fields !== [] ? $engine->search($index, [...$request, 'limit' => 1, 'attributes' => ['id'], 'facets' => $fields])['facetStats'] : [];

        return new Archive(
            true,
            $engine->endpoint(),
            $engine->token($index, $request),
            $results['query'],
            $results['total'],
            $field !== null ? self::cards_of($results['results'], $field) : [],
            $results,
            $sorts,
            $sort,
            $field ?? 'card',
            $view,
            $links,
            $bounds,
        );
    }

    /** @var array{url: string, indexable: bool}|null the archive's canonical URL and whether it's indexable */
    private static ?array $seo = null;

    /**
     * @internal For the canonical and robots hooks (Integration).
     *
     * @return array{url: string, indexable: bool}|null
     */
    public static function seo(): ?array
    {
        self::archive();

        return self::$seo;
    }

    /**
     * Products: what WooCommerce's catalog visibility hides from that use.
     *
     * @return array{visibility?: string}
     */
    private static function scope(string $index, string $use): array
    {
        return (Indexes::get($index)['type'] ?? null) === 'products' ? ['visibility' => $use] : [];
    }

    /**
     * @param list<array<string, mixed>> $hits
     * @return list<Markup>
     */
    private static function cards_of(array $hits, string $field): array
    {
        $cards = [];
        foreach ($hits as $hit) {
            if (is_string($hit[$field] ?? null)) {
                // trusted: rendered from the theme's card template when the index was built
                $cards[] = new Markup($hit[$field], 'UTF-8');
            }
        }

        return $cards;
    }
}
