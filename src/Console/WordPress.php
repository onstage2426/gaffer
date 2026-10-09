<?php

declare(strict_types=1);

namespace Gaffer\Console;

use Gaffer\Paths;
use Gaffer\Gaffer;

/**
 * Loads WordPress for CLI commands, once.
 */
final class WordPress
{
    private static bool $loaded = false;

    public static function load(?string $url = null): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        self::fake_request($url ?? Env::get('SITE_URL'));
        self::skip_page_cache();
        self::fail_on_unknown_site();

        // Let fatal errors reach the terminal instead of WordPress's HTML error page.
        if (!defined('WP_DISABLE_FATAL_ERROR_HANDLER')) {
            define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
        }

        // wp-config.php runs inside this method, so its variables would be local here.
        // WordPress reads $table_prefix as a global, so bind it before loading.
        global $table_prefix;

        require_once Paths::wordpress() . '/wp-load.php';

        // functions.php boots Gaffer when this theme is active; this covers the case
        // where it isn't (and is a no-op otherwise).
        Gaffer::boot(Paths::base());
    }

    /**
     * A page cache drop-in (advanced-cache.php, e.g. WP Rocket) would answer the
     * fake request with a cached page and exit before the command runs, or hand
     * doctor:render cached HTML. WordPress only loads it when this filter allows;
     * hooks set in $wp_filter before WordPress loads are picked up by plugin.php.
     */
    public static function skip_page_cache(): void
    {
        if (function_exists('add_filter')) {
            return; // WordPress is loaded already: the drop-in was decided on then
        }
        $GLOBALS['wp_filter']['enable_loading_advanced_cache_dropin'][10][] = [
            'function' => static fn(): bool => false,
            'accepted_args' => 1,
        ];
    }

    /**
     * On multisite, WordPress picks the site from the request's host and path. When
     * none matches it prints "Error establishing a database connection" or redirects
     * and exits silently; say what happened instead. Hooked before WordPress loads,
     * like skip_page_cache().
     */
    public static function fail_on_unknown_site(): void
    {
        if (function_exists('add_action')) {
            return;
        }
        $fail = static fn(string $domain, string $path): never => throw new \RuntimeException(
            "This multisite has no site at {$domain}{$path}. Set SITE_URL in the theme's .env (or pass --url) to the site that uses this theme, e.g. https://example.com/shop/.",
        );
        $GLOBALS['wp_filter']['ms_network_not_found'][10][] = ['function' => $fail, 'accepted_args' => 2];
        $GLOBALS['wp_filter']['ms_site_not_found'][10][] = [
            'function' => static fn(object $network, string $domain, string $path): never => $fail($domain, $path),
            'accepted_args' => 3,
        ];
    }

    /**
     * WordPress expects a web request (host, URI, HTTPS) to build URLs and pick the site
     * (on a subdirectory multisite also by the path).
     */
    private static function fake_request(?string $url): void
    {
        $parts = $url ? parse_url($url) : [];

        if (isset($parts['host'])) {
            $_SERVER['HTTP_HOST'] = $parts['host'] . (isset($parts['port']) ? ":{$parts['port']}" : '');
            $_SERVER['SERVER_NAME'] = $parts['host'];
        }
        if (($parts['scheme'] ?? null) === 'https') {
            $_SERVER['HTTPS'] = 'on';
        }

        $_SERVER['HTTP_HOST'] ??= 'localhost';
        $_SERVER['SERVER_NAME'] ??= 'localhost';
        $_SERVER['REQUEST_URI'] ??= rtrim($parts['path'] ?? '', '/') . '/';
        $_SERVER['REQUEST_METHOD'] ??= 'GET';
    }
}
