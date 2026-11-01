<?php

declare(strict_types=1);

namespace Gaffer\Search;

use Gaffer\Config;

/**
 * Hooks the search into WordPress when config/search.php defines indexes and the plugin is
 * there: the definitions for the engine, and with `archive` its redirects (/page/N/ and
 * WooCommerce's own params; WordPress's ?s= search with `redirect_search`), canonical URL and
 * robots.
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

    private static function redirect(): void
    {
        $engine = Engine::find();
        $index = Search::archive_index();
        if ($engine === null || $index === null) {
            return;
        }

        $shop = Search::shop_url();
        if (Config::get('search.archive.redirect_search') === true && \is_search() && !\is_admin() && $shop !== null) {
            \wp_safe_redirect($engine->url($shop, ['q' => \get_search_query(false)]), 302);
            exit;
        }
        if (!Search::on_archive()) {
            return;
        }
        $url = $engine->redirect($index, (array) \wp_unslash($_GET), (int) \get_query_var('paged'), max(1, (int) Config::get('search.archive.per_page') ?: 24), Search::archive_base());
        if ($url !== null) {
            \wp_safe_redirect($url, 301);
            exit;
        }
    }
}
