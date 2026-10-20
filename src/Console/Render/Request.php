<?php

declare(strict_types=1);

namespace Gaffer\Console\Render;

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

    /** @var array{path: string, status: string, error: ?string, file: ?string, line: ?int, redirect: ?string, http: int, notices: list<string>, bytes: int, time_ms?: int, queries?: int, twig_ms?: int, html?: string} */
    private array $result;

    /** Output buffer level below the page's buffer. */
    private ?int $buffer_level = null;

    /** Twig's render time, once the environment exists. */
    private ?Profile $profile = null;

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
     * @param array{path: string, html: bool, user: ?string, cart: list<string>, url: ?string} $options
     * @throws \InvalidArgumentException on a malformed --user or --cart
     */
    public static function start(string $theme, array $options): self
    {
        Gaffer::configure($theme);
        $request = new self();
        $request->prepare($options);

        return $request;
    }

    /** @param array{path: string, html: bool, user: ?string, cart: list<string>, url: ?string} $options */
    private function prepare(array $options): void
    {
        $this->as_visitor($options['user'], $options['cart']); // throws on a malformed value: before anything else
        $path = $options['path'];
        $started = hrtime(true);
        $this->result = ['path' => $path, 'status' => 'ok', 'error' => null, 'file' => null, 'line' => null, 'redirect' => null, 'http' => 200, 'notices' => [], 'bytes' => 0, 'time_ms' => 0, 'queries' => 0, 'twig_ms' => 0];
        if ($options['html']) {
            $this->result['html'] = '';
        }

        // Templates may redirect and exit, or fatal; report from shutdown either way.
        register_shutdown_function(function () use ($started): void {
            // Runs before WordPress's own shutdown flush: empty the page into the result first.
            while ($this->buffer_level !== null && ob_get_level() > $this->buffer_level) {
                ob_end_flush();
            }
            $error = error_get_last();
            if ($this->result['status'] === 'ok' && $error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $this->fail(strtok($error['message'], "\n") ?: $error['message'], $error['file'], $error['line']);
            }
            $this->result['time_ms'] = (int) round((hrtime(true) - $started) / 1e6);
            $this->result['queries'] = isset($GLOBALS['wpdb']) ? (int) $GLOBALS['wpdb']->num_queries : 0;
            $this->result['twig_ms'] = $this->profile !== null ? (int) round($this->profile->getDuration() * 1000) : 0;
            fwrite(STDOUT, "\n" . self::MARKER . json_encode($this->result, JSON_UNESCAPED_SLASHES) . "\n");
        });

        // PHP warnings/notices raised in theme files (not plugins or core).
        set_error_handler(function (int $no, string $message, string $file, int $line): bool {
            if (str_starts_with($file, Paths::base() . '/') && !str_contains($file, '/vendor/')) {
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
        $this->as_this_theme();
        WordPress::prepare($options['url']);
    }

    /**
     * After wp-load.php: Gaffer booted (also when this theme isn't the active one), strict
     * variables, timing, and the page captured instead of printed.
     */
    public function loaded(): void
    {
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
     * loads (the session and cart are set up while it does). Writes nothing: no persistent cart
     * (the user's saved cart is neither read nor changed: the cart is exactly --cart) and no
     * WooCommerce session row.
     *
     * @param list<string> $cart "ID" or "ID:quantity"
     * @throws \InvalidArgumentException on a malformed value
     */
    private function as_visitor(?string $user, array $cart): void
    {
        if ($user !== null) {
            $id = ctype_digit($user) ? (int) $user : 0;
            if ($id <= 0) {
                throw new \InvalidArgumentException("--user takes a user ID, not \"{$user}\"");
            }
            $GLOBALS['wp_filter']['determine_current_user'][PHP_INT_MAX][] = ['function' => static fn(): int => $id, 'accepted_args' => 1];
        }
        if ($user === null && $cart === []) {
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
     * Renders the theme whose `gaffer` started this, also when that's a copy next to the
     * active one (StagedTheme): WordPress loads the copy instead, with the active theme's
     * theme mods (menu locations, customizer settings are stored per theme). Run from the
     * active theme itself, nothing changes.
     */
    private function as_this_theme(): void
    {
        $slug = basename(Paths::base());
        $active = null;
        $swap = static function (string $current) use ($slug, &$active): string {
            if ($current === $slug || dirname(Paths::base()) !== realpath(\get_theme_root())) {
                return $current;
            }
            $active ??= $current;
            return $slug;
        };
        foreach (['stylesheet', 'template'] as $hook) {
            $GLOBALS['wp_filter'][$hook][PHP_INT_MAX][] = ['function' => $swap, 'accepted_args' => 1];
        }
        $GLOBALS['wp_filter']["pre_option_theme_mods_{$slug}"][10][] = [
            'function' => static fn(mixed $value): mixed => $active !== null ? \get_option("theme_mods_{$active}") : $value,
            'accepted_args' => 1,
        ];
    }

    private function fail(string $message, string $file, int $line): void
    {
        $this->result['status'] = 'error';
        $this->result['error'] = $message;
        $this->result['file'] = $file;
        $this->result['line'] = $line;
    }
}
