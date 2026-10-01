<?php

declare(strict_types=1);

namespace Gaffer\Console;

use Gaffer\Config;
use Gaffer\Paths;
use Gaffer\Gaffer;
use RuntimeException;

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

        self::fake_request($url ?? Config::get('console.url'));

        // Let fatal errors reach the terminal instead of WordPress's HTML error page.
        if (!defined('WP_DISABLE_FATAL_ERROR_HANDLER')) {
            define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
        }

        // wp-config.php runs inside this method, so its variables would be local here.
        // WordPress reads $table_prefix as a global, so bind it before loading.
        global $table_prefix;

        require_once self::find_wp_load();

        // functions.php boots Gaffer when this theme is active; this covers the case
        // where it isn't (and is a no-op otherwise).
        Gaffer::boot(Paths::base());
    }

    /**
     * WordPress expects a web request (host, URI, HTTPS) to build URLs and pick the site.
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
        $_SERVER['REQUEST_URI'] ??= '/';
        $_SERVER['REQUEST_METHOD'] ??= 'GET';
    }

    private static function find_wp_load(): string
    {
        for ($dir = Paths::base(); $dir !== dirname($dir); $dir = dirname($dir)) {
            if (is_file("{$dir}/wp-load.php")) {
                return "{$dir}/wp-load.php";
            }
        }

        throw new RuntimeException('wp-load.php not found in any parent directory of ' . Paths::base());
    }
}
