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
     * `base_params` names (a category's `?show=all` is another page than the category).
     */
    public static function archive_base(): string
    {
        $base = (string) strtok(\get_pagenum_link(1, false), '?');
        $own = array_intersect_key((array) \wp_unslash($_GET), array_flip(array_map(strval(...), (array) Config::get('search.archive.base_params'))));
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
        $field = Indexes::card_field($index);
        if ($engine === null) {
            return Archive::unavailable();
        }
        $config = (array) Config::get('search.archive');
        $term = function_exists('is_product_taxonomy') && \is_product_taxonomy() ? Term::current() : null;
        $definition = (array) Indexes::get($index);
        $query = (array) \wp_unslash($_GET);

        $archive = $engine->archive($index, [
            ...self::scope($index, 'catalog'),
            'limit' => max(1, (int) ($config['per_page'] ?? 24)),
            'attributes' => ['id', ...($field !== null ? [$field] : [])],
            'default_sort' => array_values(array_map(strval(...), (array) ($config['default_sort'] ?? []))),
            'facets' => array_values(array_map(strval(...), (array) ($config['facets'] ?? []))),
            ...array_intersect_key($config, array_flip(['limits', 'facet_values', 'max_docs', 'index_pages', 'indexable_filters'])),
            ...($term !== null ? ['term' => $term->id()] : []),
            // A variant's page (business prices) is that visitor's: its token stops working after a day.
            ...(Indexes::variant($definition) !== null ? ['ttl' => DAY_IN_SECONDS] : []),
        ], $query, self::archive_base());

        $sorts = [];
        foreach ((array) ($config['sorts'] ?? []) as $value => $label) {
            $sorts[] = ['value' => (string) $value, 'label' => (string) $label];
        }
        $sort = is_array($query['sort'] ?? null) ? (string) (reset($query['sort']) ?: '') : '';
        self::$seo = ['url' => $archive['url'], 'indexable' => $archive['indexable']];

        return new Archive(
            true,
            $archive['endpoint'],
            $archive['token'],
            $archive['results']['query'],
            $archive['results']['total'],
            $field !== null ? self::cards_of($archive['results']['results'], $field) : [],
            $archive['results'],
            $sorts,
            $sort,
            $field ?? 'card',
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
