<?php

declare(strict_types=1);

namespace Gaffer;

use Twig\Environment;
use WP_Admin_Bar;

/**
 * "Gaffer" in the admin bar (for administrators): a status dot, versions,
 * Twig/Vite state, and "Clear Twig cache".
 */
final class AdminBar
{
    private const string CLEAR_ACTION = 'gaffer_twig_clear';

    public static function register(): void
    {
        add_action('admin_bar_menu', self::menu(...), 100);
        add_action('wp_head', self::style(...));
        add_action('admin_head', self::style(...));
        add_action('admin_post_' . self::CLEAR_ACTION, self::clear(...));
    }

    /**
     * Red: debug on in production/staging. Orange: debug on elsewhere, or the
     * Twig cache off in production. Green: otherwise.
     *
     * @return array{color: 'red'|'orange'|'green', reason: string}
     */
    public static function status(string $environment, bool $debug, bool $cache): array
    {
        return match (true) {
            $debug && in_array($environment, ['production', 'staging'], true) => ['color' => 'red', 'reason' => "Debug is on in {$environment}"],
            $debug => ['color' => 'orange', 'reason' => 'Debug is on'],
            !$cache && $environment === 'production' => ['color' => 'orange', 'reason' => 'Twig cache is off in production'],
            default => ['color' => 'green', 'reason' => 'OK'],
        };
    }

    private static function menu(WP_Admin_Bar $bar): void
    {
        if (!\current_user_can('manage_options')) {
            return;
        }

        $twig = View::env();
        $environment = \wp_get_environment_type();
        $status = self::status($environment, $twig->isDebug(), TwigCache::enabled());

        $bar->add_node([
            'id' => 'gaffer',
            'title' => '<span class="gaffer-dot gaffer-dot--' . $status['color'] . '"></span>Gaffer',
            'meta' => ['title' => $status['reason']],
        ]);

        foreach (self::lines($twig, $environment, $status['reason']) as $id => $text) {
            $bar->add_node(['parent' => 'gaffer', 'id' => "gaffer-{$id}", 'title' => \esc_html($text)]);
        }

        if (TwigCache::enabled()) {
            $bar->add_node([
                'parent' => 'gaffer',
                'id' => 'gaffer-clear',
                'title' => 'Clear Twig cache',
                'href' => \wp_nonce_url(\admin_url('admin-post.php?action=' . self::CLEAR_ACTION), self::CLEAR_ACTION),
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function lines(Environment $twig, string $environment, string $reason): array
    {
        $on = static fn(bool $value): string => $value ? 'on' : 'off';

        $cache = 'off';
        if (TwigCache::enabled()) {
            ['files' => $files, 'bytes' => $bytes] = TwigCache::stats();
            $cache = "on ({$files} files, " . \size_format($bytes) . ')';
        }

        return [
            'status' => "Status: {$reason}",
            'version' => 'Gaffer ' . Gaffer::version(),
            'twig' => 'Twig ' . Environment::VERSION,
            'environment' => "Environment: {$environment}",
            'cache' => "Twig cache: {$cache}",
            'debug' => 'Debug: ' . $on($twig->isDebug()) . ', strict variables: ' . $on($twig->isStrictVariables()) . ', auto-reload: ' . $on($twig->isAutoReload()),
            'vite' => 'Vite: ' . (Vite::is_dev_mode() ? 'dev server' : 'built assets'),
        ];
    }

    private static function clear(): void
    {
        if (!\current_user_can('manage_options')) {
            \wp_die('Not allowed.', 403);
        }
        \check_admin_referer(self::CLEAR_ACTION);

        TwigCache::clear();

        \wp_safe_redirect(\wp_get_referer() ?: \admin_url());
        exit;
    }

    private static function style(): void
    {
        if (!\is_admin_bar_showing() || !\current_user_can('manage_options')) {
            return;
        }

        echo <<<'HTML'
        <style>
            #wp-admin-bar-gaffer .gaffer-dot { display: inline-block; width: 8px; height: 8px; margin-right: 6px; border-radius: 50%; vertical-align: middle; }
            #wp-admin-bar-gaffer .gaffer-dot--green { background: #00a32a; }
            #wp-admin-bar-gaffer .gaffer-dot--orange { background: #dba617; }
            #wp-admin-bar-gaffer .gaffer-dot--red { background: #d63638; }
        </style>
        HTML;
    }
}
