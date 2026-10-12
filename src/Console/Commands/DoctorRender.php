<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Command;
use Gaffer\Console\WordPress;
use Gaffer\Paths;
use Gaffer\View;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Twig\Error\Error as TwigError;
use Twig\Extension\ProfilerExtension;
use Twig\Profiler\Profile;

/**
 * Renders one URL the way a web request would, with Twig strict_variables on,
 * and prints the result as one JSON line. Used by `doctor --wp`, one process per
 * URL because WordPress can only handle one request per process. With --html
 * the result also holds the page (the `gaffer/render` MCP tool).
 */
#[AsCommand('doctor:render', 'Render one URL with strict variables (used by doctor --wp)', hidden: true)]
final class DoctorRender extends Command
{
    public const string MARKER = 'GAFFER_RENDER_RESULT ';

    /** @var array{path: string, status: string, error: ?string, file: ?string, line: ?int, redirect: ?string, http: int, notices: list<string>, bytes: int, time_ms?: int, queries?: int, twig_ms?: int, html?: string} */
    private array $result;

    /** Output buffer level below the page's buffer. */
    private ?int $buffer_level = null;

    /** Twig's render time, once the environment exists. */
    private ?Profile $profile = null;

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'URL path, e.g. /contact/');
        $this->addOption('html', null, InputOption::VALUE_NONE, 'Include the rendered page in the result');
        $this->addOption('user', null, InputOption::VALUE_REQUIRED, 'Render as this user (ID), e.g. the account pages or role prices');
        $this->addOption('cart', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'WooCommerce: put this product in the cart first, ID or ID:quantity (a variation by its own ID; repeatable)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) $input->getArgument('path');
        $started = hrtime(true);
        $this->result = ['path' => $path, 'status' => 'ok', 'error' => null, 'file' => null, 'line' => null, 'redirect' => null, 'http' => 200, 'notices' => [], 'bytes' => 0, 'time_ms' => 0, 'queries' => 0, 'twig_ms' => 0];
        if ($input->getOption('html')) {
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
        try {
            $this->as_visitor(is_string($user = $input->getOption('user')) ? $user : null, array_values(array_map('strval', (array) $input->getOption('cart'))));
        } catch (\InvalidArgumentException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return self::FAILURE;
        }
        $this->as_this_theme();
        WordPress::load($input->getOption('url'));
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

        // Keep the page out of stdout, only count it (and keep it with --html). Flushed in the shutdown function above.
        $this->buffer_level = ob_get_level();
        ob_start(function (string $buffer): string {
            $this->result['bytes'] += strlen($buffer);
            if (isset($this->result['html'])) {
                $this->result['html'] .= $buffer;
            }
            return '';
        });

        try {
            wp();
            require ABSPATH . 'wp-includes/template-loader.php';
        } catch (Throwable $e) {
            $source = $e instanceof TwigError ? $e->getSourceContext()?->getPath() : null;
            $this->fail(
                $e::class . ': ' . ($e instanceof TwigError ? $e->getRawMessage() : $e->getMessage()),
                $source ?: $e->getFile(),
                $e instanceof TwigError && $e->getTemplateLine() > 0 ? $e->getTemplateLine() : $e->getLine(),
            );
        }

        return self::SUCCESS;
    }

    /**
     * The result line from this command's output. Reads that one line only: plugins
     * print after it on shutdown (Redis Object Cache's HTML comment). Throws with what
     * it couldn't read when the process died first or the line isn't a result.
     *
     * @return array{path: string, status: string, error: ?string, file: ?string, line: ?int, redirect: ?string, http: int, notices: list<string>, bytes: int, time_ms?: int, queries?: int, twig_ms?: int, html?: string}
     */
    public static function parse(string $stdout): array
    {
        $at = strrpos($stdout, self::MARKER);
        if ($at === false) {
            $tail = trim(mb_substr($stdout, -500));
            throw new RuntimeException('the render process ended without a result line' . ($tail !== '' ? "; its last output: {$tail}" : ' and without output'));
        }

        $line = strtok(substr($stdout, $at + strlen(self::MARKER)), "\n");
        $result = json_decode((string) $line, true);
        if (!is_array($result) || !is_string($result['status'] ?? null)) {
            throw new RuntimeException('the render result line isn\'t readable: ' . mb_substr((string) $line, 0, 300));
        }

        return $result;
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
