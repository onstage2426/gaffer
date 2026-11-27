<?php

declare(strict_types=1);

namespace Gaffer\Console\Render;

use Gaffer\Console\Ajax\Session;
use Gaffer\Console\WordPress;
use Gaffer\Gaffer;
use Gaffer\Paths;
use Gaffer\View;
use Throwable;
use Twig\Error\Error as TwigError;
use Twig\Extension\ProfilerExtension;
use Twig\Profiler\Profile;

/**
 * One URL rendered the way a web request renders it, driven by bin/render.php: start()
 * before WordPress loads, loaded() after, failed() for an exception from the template
 * loader. The script loads WordPress and the template loader at its own top level (like
 * index.php), so template files run in the global scope as in a real request: their
 * variables are WordPress's globals ($page, $post), which get_header() may overwrite.
 * The result is one JSON line after MARKER, printed on shutdown.
 *
 * @internal
 */
final class Request
{
    public const string MARKER = 'GAFFER_RENDER_RESULT ';

    /** The result line's `v` (render:page's protocol): readers refuse a newer one. */
    public const int PROTOCOL = 1;

    /** @var array{v: int, path: string, status: string, error: ?string, file: ?string, line: ?int, redirect: ?string, http: int, notices: list<string>, bytes: int, time_ms?: int, queries?: int, twig_ms?: int, search?: ?bool, html?: string} */
    private array $result;

    /** Output buffer level below the page's buffer. */
    private ?int $buffer_level = null;

    /** Twig's render time, once the environment exists. */
    private ?Profile $profile = null;

    /**
     * How far the request got: 'loading' (WordPress), 'loaded' (the query and the template),
     * 'done' (the template loader returned). A process that ends before 'done' without a
     * page, a redirect or an error (a plugin's exit, a wp_die()) rendered nothing: an error,
     * not an empty "ok". One that printed a page and exited (a sitemap) is fine.
     */
    private string $stage = 'loading';

    /** What was printed while WordPress loaded (a wp_die() page, a plugin's notice). */
    private string $early = '';

    /** Output buffer level below the buffer that collects $early. */
    private ?int $early_level = null;

    /**
     * Every Gaffer class from this copy, when the theme's own Composer autoloader (loaded
     * by functions.php) would otherwise supply another copy's (a development clone).
     */
    public static function preload(string $autoload, string $theme): void
    {
        if (realpath(dirname($autoload)) === realpath("{$theme}/vendor")) {
            return;
        }
        $src = dirname(__DIR__, 2);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $class = 'Gaffer\\' . str_replace(['/', '.php'], ['\\', ''], substr((string) $file, strlen($src) + 1));
            class_exists($class) || interface_exists($class) || trait_exists($class);
        }
    }

    /**
     * Everything that has to be in place before WordPress loads.
     *
     * @param array{path: string, html: bool, user: ?string, cart: list<string>, session_file?: ?string, url: ?string} $options
     * @throws \InvalidArgumentException on a malformed --user or --cart, or a missing --session-file
     */
    public static function start(string $theme, array $options): self
    {
        Gaffer::configure($theme);
        $request = new self();
        $request->prepare($options);

        return $request;
    }

    /** @param array{path: string, html: bool, user: ?string, cart: list<string>, session_file?: ?string, url: ?string} $options */
    private function prepare(array $options): void
    {
        $session = isset($options['session_file']) ? self::session_file($options['session_file']) : []; // throws on a missing jar
        $this->as_visitor($options['user'], $options['cart'], $session); // throws on a malformed value: before anything else
        $path = $options['path'];
        $started = hrtime(true);
        $this->result = ['v' => self::PROTOCOL, 'path' => $path, 'status' => 'ok', 'error' => null, 'file' => null, 'line' => null, 'redirect' => null, 'http' => 200, 'notices' => [], 'bytes' => 0, 'time_ms' => 0, 'queries' => 0, 'twig_ms' => 0];
        if ($options['html']) {
            $this->result['html'] = '';
        }

        // Templates may redirect and exit, or fatal; report from shutdown either way.
        register_shutdown_function(function () use ($started): void {
            // Runs before WordPress's own shutdown flush: empty the page into the result first.
            while ($this->buffer_level !== null && ob_get_level() > $this->buffer_level) {
                ob_end_flush();
            }
            $this->end_early_buffer();
            $error = error_get_last();
            if ($this->result['status'] === 'ok' && $error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $this->fail(strtok($error['message'], "\n") ?: $error['message'], $error['file'], $error['line']);
            }
            if ($this->result['status'] === 'ok' && $this->stage !== 'done' && $this->result['bytes'] === 0) {
                $printed = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('@<(script|style)[^>]*?>.*?</\1>@si', '', $this->early))));
                $this->fail(
                    ($this->stage === 'loading' ? 'the process exited while WordPress loaded' : 'the process exited before the template finished')
                        . ' (a plugin\'s exit or a wp_die())' . ($printed !== '' ? ': ' . mb_substr($printed, 0, 300) : ''),
                    '',
                    0,
                );
            }
            $this->result['time_ms'] = (int) round((hrtime(true) - $started) / 1e6);
            $this->result['queries'] = isset($GLOBALS['wpdb']) ? (int) $GLOBALS['wpdb']->num_queries : 0;
            $this->result['twig_ms'] = $this->profile !== null ? (int) round($this->profile->getDuration() * 1000) : 0;
            // Whether this render saw the search engine (null: the theme defines no indexes): a plugin
            // activated only for the parent process isn't active here (Renderer::without_engine()).
            $this->result['search'] = \Gaffer\Search\Indexes::all() !== [] ? \Gaffer\Search\Engine::find() !== null : null;
            foreach (\Gaffer\Search\Engine::warnings() as $warning) {
                $this->result['notices'][] = "gaffer-search {$warning}";
            }
            fwrite(STDOUT, "\n" . self::MARKER . json_encode($this->result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        });

        // PHP warnings/notices raised in theme files (not plugins or core).
        set_error_handler(function (int $no, string $message, string $file, int $line): bool {
            if (str_starts_with($file, Paths::base() . '/') && !preg_match('~/vendor(\.[\w-]+)?/~', $file)) {
                $this->result['notices'][] = substr($file, strlen(Paths::base()) + 1) . ":{$line} {$message}";
            }
            return false;
        });

        $_SERVER['REQUEST_URI'] = $path;
        // WordPress reads query vars (s, paged, filters) from $_GET, which a CLI request doesn't fill.
        $_SERVER['QUERY_STRING'] = (string) parse_url($path, PHP_URL_QUERY);
        parse_str($_SERVER['QUERY_STRING'], $_GET);
        $_REQUEST = $_GET;
        define('WP_USE_THEMES', true);
        // The theme's own output: page cache and optimization plugins leave it alone (WP Rocket and
        // others honor these), so renders are stable and the CLI user writes no cache files.
        define('DONOTCACHEPAGE', true);
        define('DONOTROCKETOPTIMIZE', true);
        // Nonces the same in every render (rendered pages are never submitted), so render:diff
        // doesn't show them as changes: no time tick, and one id for logged-out visitors, which
        // WooCommerce otherwise makes each render's new session id.
        $GLOBALS['wp_filter']['nonce_life'][PHP_INT_MAX][] = ['function' => static fn(): int => PHP_INT_MAX, 'accepted_args' => 1];
        $GLOBALS['wp_filter']['nonce_user_logged_out'][PHP_INT_MAX][] = ['function' => static fn(): string => 'gaffer-render', 'accepted_args' => 1];
        // No WP-cron from a render: wp_cron() on init would spawn the due events (update checks,
        // auto-updates), and a translation or plugin update puts the site in maintenance mode, so
        // the renders after it got WordPress's maintenance page (2026-11-15). Renders write nothing.
        $GLOBALS['wp_filter']['pre_get_ready_cron_jobs'][PHP_INT_MAX][] = ['function' => static fn(): array => [], 'accepted_args' => 1];
        // A wp_die() ends the request without a page: say where and why.
        $GLOBALS['wp_filter']['wp_die_handler'][PHP_INT_MAX][] = ['function' => fn(): \Closure => $this->die(...), 'accepted_args' => 1];
        // What WordPress or a plugin prints before the page's buffer exists.
        $this->early_level = ob_get_level();
        ob_start(function (string $buffer): string {
            $this->early = mb_substr($this->early . $buffer, -5000);
            return '';
        });
        WordPress::prepare($options['url']); // also renders a staged copy as the active theme
    }

    /** The template loader returned: the page is complete. */
    public function done(): void
    {
        $this->stage = 'done';
    }

    /** wp_die()'s handler during a render: the message and the caller, then exit. */
    private function die(mixed $message, mixed $title = '', mixed $args = []): never
    {
        $text = $message instanceof \WP_Error ? $message->get_error_message() : (is_scalar($message) ? (string) $message : '');
        $caller = ['file' => '', 'line' => 0];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if ($frame['function'] === 'wp_die' && isset($frame['file'])) {
                $caller = ['file' => $frame['file'], 'line' => (int) ($frame['line'] ?? 0)];
                break;
            }
        }
        $this->fail('wp_die(): ' . (trim(strip_tags($text)) ?: '(no message)'), $caller['file'], $caller['line']);
        exit;
    }

    private function end_early_buffer(): void
    {
        if ($this->early_level === null) {
            return;
        }
        while (ob_get_level() > $this->early_level) {
            ob_end_flush();
        }
        $this->early_level = null;
    }

    /**
     * After wp-load.php: Gaffer booted (also when this theme isn't the active one), strict
     * variables, timing, and the page captured instead of printed.
     */
    public function loaded(): void
    {
        $this->stage = 'loaded';
        WordPress::booted();
        View::env()->enableStrictVariables();
        $this->profile = new Profile();
        View::env()->addExtension(new ProfilerExtension($this->profile));

        // The HTTP status WordPress sends (404 for a missing page, 3xx for a redirect).
        add_filter('status_header', function (string $header, int $code): string {
            $this->result['http'] = $code;
            return $header;
        }, PHP_INT_MAX, 2);

        add_filter('wp_redirect', function (string $location, int $status): string {
            $this->result['http'] = $status;
            $this->result['status'] = 'redirect';
            $this->result['redirect'] = $location;
            return $location;
        }, PHP_INT_MAX, 2);

        // Keep the page out of stdout, only count it (and keep it with --html). Flushed in prepare()'s shutdown function.
        $this->buffer_level = ob_get_level();
        ob_start(function (string $buffer): string {
            $this->result['bytes'] += strlen($buffer);
            if (isset($this->result['html'])) {
                $this->result['html'] .= $buffer;
            }
            return '';
        });
    }

    public function failed(Throwable $e): void
    {
        $source = $e instanceof TwigError ? $e->getSourceContext()?->getPath() : null;
        $this->fail(
            $e::class . ': ' . ($e instanceof TwigError ? $e->getRawMessage() : $e->getMessage()),
            $source ?: $e->getFile(),
            $e instanceof TwigError && $e->getTemplateLine() > 0 ? $e->getTemplateLine() : $e->getLine(),
        );
    }

    /**
     * Logged in as $user and with these products in the WooCommerce cart, hooked before WordPress
     * loads (the session and cart are set up while it does), or with an ajax:call session's
     * cookies (its WooCommerce session and cart). Writes nothing: no persistent cart (the user's
     * saved cart is neither read nor changed: the cart is exactly --cart) and no WooCommerce
     * session row (a --session's row is read, not saved back).
     *
     * @param list<string> $cart "ID" or "ID:quantity"
     * @param array<string, string> $cookies
     * @throws \InvalidArgumentException on a malformed value
     */
    private function as_visitor(?string $user, array $cart, array $cookies = []): void
    {
        $_COOKIE = [...$_COOKIE, ...$cookies];
        if ($user !== null) {
            $id = ctype_digit($user) ? (int) $user : 0;
            if ($id <= 0) {
                throw new \InvalidArgumentException("--user takes a user ID, not \"{$user}\"");
            }
            $GLOBALS['wp_filter']['determine_current_user'][PHP_INT_MAX][] = ['function' => static fn(): int => $id, 'accepted_args' => 1];
        }
        if ($user === null && $cart === [] && $cookies === []) {
            return;
        }

        $items = [];
        foreach ($cart as $item) {
            if (!preg_match('/^(\d+)(?::(\d+))?$/', $item, $m)) {
                throw new \InvalidArgumentException("--cart takes a product ID or ID:quantity, not \"{$item}\"");
            }
            $items[] = [(int) $m[1], (int) ($m[2] ?? 1)];
        }
        // A logged-in visitor or a cart gets a WooCommerce session: keep it in this process.
        $GLOBALS['wp_filter']['woocommerce_persistent_cart_enabled'][10][] = ['function' => static fn(): bool => false, 'accepted_args' => 1];
        $GLOBALS['wp_filter']['wp_loaded'][PHP_INT_MAX][] = ['function' => function () use ($items): void {
            if (!function_exists('WC')) {
                if ($items !== []) {
                    $this->fail('--cart needs WooCommerce', __FILE__, __LINE__);
                }
                return;
            }
            if (WC()->session !== null) {
                remove_action('shutdown', [WC()->session, 'save_data'], 20);
            }
            foreach ($items as [$product, $quantity]) {
                if (WC()->cart === null || WC()->cart->add_to_cart($product, $quantity) === false) {
                    $this->fail("Product {$product} could not be added to the cart (not purchasable, out of stock, or a variable product: pass the variation's ID)", __FILE__, __LINE__);
                    return;
                }
            }
        }, 'accepted_args' => 1];
    }

    /**
     * @return array<string, string> the cookies of an ajax:call session's jar, by its path
     * @throws \InvalidArgumentException on a jar that doesn't exist
     */
    private static function session_file(string $file): array
    {
        if (!is_file($file)) {
            throw new \InvalidArgumentException("No session jar {$file}: start one with php gaffer ajax:call <action> --session=<name>");
        }

        return Session::cookies_in($file);
    }

    private function fail(string $message, string $file, int $line): void
    {
        $this->result['status'] = 'error';
        $this->result['error'] = $message;
        $this->result['file'] = $file;
        $this->result['line'] = $line;
    }
}
