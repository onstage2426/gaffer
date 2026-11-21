<?php

/**
 * Maintainer tool (not for themes): render one page of a local site in this process and say
 * what a visitor would get: status, cache headers/`DONOTCACHEPAGE`, queries (by caller: WP_Query,
 * WooCommerce, other), time, HTML size, memory, the main query.
 *
 *   php bin/dev/render-probe.php <wp root> <host> <path> [--without=<plugin dir>] [--clone] [--dump=<file>] [--callers]
 *
 * --without   leaves a plugin out of active_plugins for this process only (what a page shows
 *             while a plugin is inactive or its folder is missing: nothing is deactivated)
 * --clone     preloads this Gaffer clone first (unpushed code; as bin/dev/gaffer-test does)
 * --callers   the 15 most frequent query callers
 *
 * Runs WordPress at the top level like index.php (a template's globals stay global), without
 * WP-cron and without the page cache drop-in. Run it with enough memory: `php -d memory_limit=512M`.
 * `php gaffer render:page <path>` (hidden from `list`) reports time and queries too, through the
 * theme's own Gaffer; this one can leave a plugin out and break queries down.
 */

declare(strict_types=1);

/** @var list<string> $argv */
$argv = $_SERVER['argv'] ?? [];

$args = array_values(array_filter(array_slice($argv, 1), static fn(string $a): bool => !str_starts_with($a, '--')));
$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m) === 1) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
if (count($args) !== 3) {
    fwrite(STDERR, "Usage: php bin/dev/render-probe.php <wp root> <host> <path> [--without=<plugin dir>] [--clone] [--dump=<file>] [--callers]\n");
    exit(2);
}
[$probe_root, $probe_host, $probe_path] = $args;

if (isset($opts['clone'])) {
    $gaffer = dirname(__DIR__, 2);
    require "{$gaffer}/vendor/autoload.php";
    $src = "{$gaffer}/src";
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $file) {
        $class = 'Gaffer\\' . str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($src) + 1));
        class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class);
    }
}

$_SERVER['HTTP_HOST'] = $probe_host;
$_SERVER['REQUEST_URI'] = $probe_path;
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PORT'] = 443;
parse_str((string) parse_url($probe_path, PHP_URL_QUERY), $_GET);
define('SAVEQUERIES', true);
define('WP_USE_THEMES', true);

// Before WordPress: no WP-cron, no page cache drop-in, and maybe one plugin less.
$GLOBALS['wp_filter']['pre_get_ready_cron_jobs'][10][] = ['function' => static fn(): array => [], 'accepted_args' => 1];
$GLOBALS['wp_filter']['enable_loading_advanced_cache_dropin'][10][] = ['function' => static fn(): bool => false, 'accepted_args' => 1];
if (is_string($opts['without'] ?? null)) {
    $without = rtrim($opts['without'], '/') . '/';
    $GLOBALS['wp_filter']['option_active_plugins'][10][] = [
        'function' => static fn(mixed $p): array => array_values(array_filter((array) $p, static fn(mixed $x): bool => !str_starts_with((string) $x, $without))),
        'accepted_args' => 1,
    ];
}

$probe_started = microtime(true);
ob_start();
register_shutdown_function(static function () use ($probe_started, $opts): void {
    $html = (string) ob_get_clean();
    global $wpdb, $wp_query;
    $by = [];
    $time = 0.0;
    $callers = [];
    foreach ($wpdb->queries ?? [] as [$sql, $seconds, $caller]) {
        $time += $seconds;
        $key = preg_match('/WC_Query|wc_|woocommerce/i', $caller) ? 'woocommerce' : (str_contains($caller, 'WP_Query->get_posts') ? 'WP_Query' : 'other');
        $by[$key] = ($by[$key] ?? 0) + 1;
        $short = implode(' < ', array_slice(array_reverse(explode(', ', $caller)), 0, 3));
        $callers[$short] = ($callers[$short] ?? 0) + 1;
    }
    $cache = array_filter(headers_list(), static fn(string $h): bool => preg_match('/cache|robots|retry/i', $h) === 1);
    fprintf(STDERR, "status %d, %.0f ms, %d queries (%.1f ms) %s, html %d bytes, peak %.0f MB\n", http_response_code(), (microtime(true) - $probe_started) * 1000,
        count($wpdb->queries ?? []), $time * 1000, json_encode($by), strlen($html), memory_get_peak_usage() / 1048576);
    fprintf(STDERR, "DONOTCACHEPAGE %s%s\n", defined('DONOTCACHEPAGE') ? 'yes' : 'no', $cache !== [] ? '; ' . implode(' | ', $cache) : ' (the CLI sends no headers)');
    if (preg_match("/<meta name=['\"]robots['\"] content=['\"]([^'\"]*)/", $html, $m) === 1) {
        fprintf(STDERR, "robots %s\n", $m[1]);
    }
    if ($wp_query instanceof WP_Query) {
        fprintf(STDERR, "main query: found_posts %d, post_count %d\n", $wp_query->found_posts, $wp_query->post_count);
    }
    if (isset($opts['callers'])) {
        arsort($callers);
        foreach (array_slice($callers, 0, 15, true) as $caller => $n) {
            fprintf(STDERR, "%4d %s\n", $n, substr($caller, 0, 200));
        }
    }
    if (is_string($opts['dump'] ?? null)) {
        file_put_contents($opts['dump'], $html);
    }
});

require rtrim($probe_root, '/') . '/wp-blog-header.php';
