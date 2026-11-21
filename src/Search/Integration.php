<?php

declare(strict_types=1);

namespace Gaffer\Search;

use Gaffer\Config;

/**
 * Hooks the search into WordPress when config/search.php defines indexes and the plugin is
 * there: the definitions for the engine, and with `archive` its redirects (/page/N/,
 * WooCommerce's own params, the theme's old ones with `urls.redirect_params`; WordPress's ?s=
 * search with `urls.redirect_search`), canonical URL and robots (ArchiveUrl, Search::seo()).
 *
 * @internal
 */
final class Integration
{
    public static function register(): void
    {
        $indexes = Indexes::all();
        $engine = $indexes !== [] ? Engine::find() : null;
        if ($engine === null) {
            return;
        }
        $engine->define(Indexes::definitions($indexes));

        if (Search::archive_index() === null) {
            return;
        }
        \add_action('template_redirect', self::redirect(...), 5);
        \add_action('template_redirect', self::unavailable(...), 6);
        \add_filter('wpseo_canonical', static fn(mixed $canonical): mixed => Search::seo()['url'] ?? $canonical, 20);
        \add_filter('wpseo_robots_array', static function (array $robots): array {
            $seo = Search::seo();
            if ($seo !== null) {
                $robots['index'] = $seo['indexable'] ? 'index' : 'noindex';
            }
            return $robots;
        });
        \add_filter('wp_robots', static fn(array $robots): array => (Search::seo()['indexable'] ?? true) ? $robots : [...$robots, 'noindex' => true, 'follow' => true]);
    }

    /**
     * An archive whose search couldn't run (the engine's `code`: the index isn't built yet, or
     * the search failed) answers 503 with Retry-After, before the template prints anything: the
     * empty page it shows is temporary, so search engines and caches don't keep it. Building the
     * archive here is the template's own Search::archive() (it's built once per request).
     */
    private static function unavailable(): void
    {
        if (!Search::on_archive() || \is_admin()) {
            return;
        }
        $archive = Search::archive();
        if ($archive !== null && is_string($archive->results['code'] ?? null)) {
            \status_header(503);
            if (!headers_sent()) {
                header('Retry-After: 120');
            }
        }
    }

    private static function redirect(): void
    {
        $index = Search::archive_index();
        if (Engine::find() === null || $index === null) {
            return;
        }

        $shop = Search::shop_url();
        // The site's search goes to the shop; a search of another post type (the blog's) stays.
        $type = \get_query_var('post_type');
        $products = $type === '' || $type === 'product' || $type === ['product'];
        if (Config::get('search.archive.urls.redirect_search') === true && \is_search() && !\is_admin() && $shop !== null && $products) {
            \wp_safe_redirect(ArchiveUrl::url($shop, ['q' => \get_search_query(false)]), 302);
            exit;
        }
        if (!Search::on_archive()) {
            return;
        }
        // The theme's own old URLs (a filter plugin's params) as the archive's query.
        $translate = Config::get('search.archive.urls.redirect_params');
        $url = ArchiveUrl::redirect((array) Indexes::get($index), (array) \wp_unslash($_GET), (int) \get_query_var('paged'),
            max(1, (int) Config::get('search.archive.per_page') ?: 24), Search::archive_base(), is_callable($translate) ? $translate : null);
        if ($url !== null) {
            \wp_safe_redirect($url, 301);
            exit;
        }
    }
}
