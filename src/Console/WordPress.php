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
        self::prepare($url);

        // wp-config.php runs inside this method, so its variables would be local here.
        // WordPress reads $table_prefix as a global, so bind it before loading. (A render
        // loads WordPress at the top level of bin/render.php instead, like a web request.)
        global $table_prefix;

        require_once Paths::wordpress() . '/wp-load.php';

        self::booted();
    }

    /**
     * Everything before wp-load.php: the fake request, no page cache drop-in, a clear error
     * for an unknown multisite site, the admin memory limit, fatal errors on the terminal.
     */
    public static function prepare(?string $url): void
    {
        self::fake_request($url ?? Env::get('SITE_URL'));
        self::skip_page_cache();
        self::fail_on_unknown_site();
        self::raise_memory_limit();
        self::report_fatal();

        // Let fatal errors reach the terminal instead of WordPress's HTML error page.
        if (!defined('WP_DISABLE_FATAL_ERROR_HANDLER')) {
            define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
        }
    }

    /**
     * After wp-load.php: functions.php boots Gaffer when this theme is active; this
     * covers the case where it isn't (and is a no-op otherwise).
     */
    public static function booted(): void
    {
        Gaffer::boot(Paths::base());
    }

    /**
     * Whether the loaded site runs this theme. When SITE_URL points at another site
     * (another site of a multisite), commands describe that site instead.
     */
    private static function is_theme_active(): bool
    {
        return realpath(\get_stylesheet_directory()) === realpath(Paths::base());
    }

    /**
     * Why the loaded site isn't the one to check, or null when it is. Commands describe the
     * site at SITE_URL (or --url): its host must be the site's own (WordPress redirects every
     * request on another host to it, so renders would only see redirects; a .env copied with
     * the theme points at the original site), and the site must run this theme (on a
     * multisite, SITE_URL may name another site).
     */
    public static function wrong_site(): ?string
    {
        $host = strtolower((string) parse_url(\home_url('/'), PHP_URL_HOST));
        $requested = strtolower((string) ($_SERVER['SERVER_NAME'] ?? ''));
        if ($host !== $requested) {
            return Env::get('SITE_URL') === null && $requested === 'localhost'
                ? 'SITE_URL isn\'t set in .env (this WordPress install is ' . \home_url('/') . ')'
                : "The host {$requested} (SITE_URL in .env, or --url) isn't this WordPress install's (" . \home_url('/') . '): a .env copied with the theme points at the original site';
        }
        if (!self::is_theme_active()) {
            return 'The loaded site (' . \home_url('/') . ') runs the theme "' . \get_stylesheet() . '", not this one';
        }

        return null;
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
     * PHP's CLI default (128M) is too little for WordPress with WooCommerce and friends, and
     * running out ends the process before any output. Raise it to WP_MAX_MEMORY_LIMIT, as
     * wp-admin and WP-CLI do (wp_raise_memory_limit('admin'), which doesn't exist yet when the
     * plugins load). Hooked on the first filter after wp_initial_constants() defines the
     * constant: wp_debug_mode()'s, before any plugin loads.
     */
    public static function raise_memory_limit(): void
    {
        if (function_exists('add_filter')) {
            return;
        }
        $GLOBALS['wp_filter']['enable_wp_debug_mode_checks'][10][] = [
            'function' => static function (bool $enabled): bool {
                $limit = (string) constant('WP_MAX_MEMORY_LIMIT');
                $current = wp_convert_hr_to_bytes((string) ini_get('memory_limit'));
                $max = wp_convert_hr_to_bytes($limit);
                if ($current !== -1 && ($max === -1 || $max > $current)) {
                    ini_set('memory_limit', $limit);
                }
                return $enabled;
            },
            'accepted_args' => 1,
        ];
    }

    /**
     * WordPress turns display_errors off without WP_DEBUG, so a fatal error (out of memory, a
     * plugin's parse error) would end the command with exit code 255 and nothing printed.
     */
    private static function report_fatal(): void
    {
        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && !ini_get('display_errors')) {
                fwrite(STDERR, "PHP fatal error: {$error['message']} in {$error['file']}:{$error['line']}\n");
            }
        });
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
