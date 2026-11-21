<?php

declare(strict_types=1);

namespace Gaffer;

use Twig\Environment;
use WP_Admin_Bar;

/**
 * "Gaffer" in the admin bar (for administrators): a status dot, versions,
 * Twig state, "Clear Twig cache", and one line per Gaffer plugin the theme uses (its own dot;
 * the main dot shows the worst of all).
 *
 * @internal
 */
final class AdminBar
{
    private const string CLEAR_ACTION = 'gaffer_twig_clear';

    /** The plugins' lines, cached per site for a minute: building them reads every index's state. */
    private const string PLUGINS_CACHE = 'gaffer_admin_bar_plugins';

    /**
     * Each Gaffer plugin's line: name => a summary callable (null: the theme doesn't use it).
     * A plugin adds one entry.
     *
     * @return array<string, \Closure(): (array{color: 'red'|'orange'|'green', reason: string}|null)>
     */
    private static function plugins(): array
    {
        return [
            'gaffer-search' => \Gaffer\Search\Health::summary(...),
        ];
    }

    /**
     * The worst of these states (red over orange over green), with its reason.
     *
     * @param list<array{color: 'red'|'orange'|'green', reason: string}> $states
     * @return array{color: 'red'|'orange'|'green', reason: string}
     */
    public static function worst(array $states): array
    {
        $rank = ['green' => 0, 'orange' => 1, 'red' => 2];
        $worst = ['color' => 'green', 'reason' => 'OK'];
        foreach ($states as $state) {
            if ($rank[$state['color']] > $rank[$worst['color']]) {
                $worst = $state;
            }
        }

        return $worst;
    }

    public static function register(): void
    {
        add_action('admin_bar_menu', self::menu(...), 100);
        add_action('wp_head', self::style(...));
        add_action('admin_head', self::style(...));
        add_action('admin_post_' . self::CLEAR_ACTION, self::clear(...));
    }

    /**
     * Red: debug on in production/staging. Orange: the Twig cache off in production. Green:
     * otherwise, debug on a local or development site included (expected there: `php gaffer
     * health` stays quiet on a dev site).
     *
     * @return array{color: 'red'|'orange'|'green', reason: string}
     */
    public static function status(string $environment, bool $debug, bool $cache): array
    {
        return match (true) {
            $debug && in_array($environment, ['production', 'staging'], true) => ['color' => 'red', 'reason' => "Debug is on in {$environment}"],
            $debug => ['color' => 'green', 'reason' => "OK (debug on, {$environment})"],
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
        $own = self::own();
        $plugins = self::plugin_states();
        $status = self::worst([$own, ...array_map(
            static fn(string $name, array $state): array => ['color' => $state['color'], 'reason' => "{$name}: {$state['reason']}"],
            array_keys($plugins),
            $plugins,
        )]);

        $bar->add_node([
            'id' => 'gaffer',
            'title' => '<span class="gaffer-dot gaffer-dot--' . $status['color'] . '"></span>Gaffer',
            'meta' => ['title' => $status['reason']],
        ]);

        foreach (self::sections($twig, $environment, $own['reason']) as $section => $lines) {
            $bar->add_group(['parent' => 'gaffer', 'id' => "gaffer-{$section}"]);
            foreach ($lines as $id => $text) {
                $bar->add_node(['parent' => "gaffer-{$section}", 'id' => "gaffer-{$id}", 'title' => \esc_html($text)]);
            }
        }

        if ($plugins !== []) {
            $bar->add_group(['parent' => 'gaffer', 'id' => 'gaffer-plugins']);
            foreach ($plugins as $name => $state) {
                $bar->add_node([
                    'parent' => 'gaffer-plugins',
                    'id' => "gaffer-plugin-{$name}",
                    'title' => '<span class="gaffer-dot gaffer-dot--' . $state['color'] . '"></span>' . \esc_html("{$name}: {$state['reason']}"),
                ]);
            }
        }

        if (TwigCache::enabled()) {
            $bar->add_node([
                'parent' => 'gaffer-twig',
                'id' => 'gaffer-clear',
                'title' => 'Clear Twig cache',
                'href' => \wp_nonce_url(\admin_url('admin-post.php?action=' . self::CLEAR_ACTION), self::CLEAR_ACTION),
            ]);
        }
    }

    /**
     * The dropdown, in sections (each rendered as its own group with a divider).
     *
     * @return array<string, array<string, string>>
     */
    private static function sections(Environment $twig, string $environment, string $reason): array
    {
        $on = static fn(bool $value): string => $value ? 'on' : 'off';

        $cache = 'off';
        if (TwigCache::enabled()) {
            ['files' => $files, 'bytes' => $bytes] = TwigCache::stats();
            $cache = "on ({$files} files, " . \size_format($bytes) . ')';
        }

        return [
            'site' => [
                'status' => "Status: {$reason}",
                'environment' => "Environment: {$environment}",
            ],
            'versions' => [
                'version' => 'Gaffer ' . Gaffer::version(),
                'twig-version' => 'Twig ' . Environment::VERSION,
            ],
            'twig' => [
                'cache' => "Twig cache: {$cache}",
                'debug' => 'Debug: ' . $on($twig->isDebug()) . ', strict variables: ' . $on($twig->isStrictVariables()) . ', auto-reload: ' . $on($twig->isAutoReload()),
            ],
        ];
    }

    /**
     * The lines of the plugins the theme uses, from the cache when it's under a minute old.
     *
     * @return array<string, array{color: 'red'|'orange'|'green', reason: string}>
     */
    private static function plugin_states(): array
    {
        $cached = \get_transient(self::PLUGINS_CACHE);
        if (is_array($cached)) {
            /** @var array<string, array{color: 'red'|'orange'|'green', reason: string}> $cached */
            return $cached;
        }
        $states = self::fresh_plugin_states();
        \set_transient(self::PLUGINS_CACHE, $states, MINUTE_IN_SECONDS);

        return $states;
    }

    /**
     * Every line the menu shows, read now (`php gaffer health`): "gaffer" (debug, the Twig cache)
     * and each Gaffer plugin the theme uses.
     *
     * @return array<string, array{color: 'red'|'orange'|'green', reason: string}>
     */
    public static function states(): array
    {
        return ['gaffer' => self::own(), ...self::fresh_plugin_states()];
    }

    /** @return array{color: 'red'|'orange'|'green', reason: string} */
    private static function own(): array
    {
        return self::status(\wp_get_environment_type(), View::env()->isDebug(), TwigCache::enabled());
    }

    /** @return array<string, array{color: 'red'|'orange'|'green', reason: string}> */
    private static function fresh_plugin_states(): array
    {
        $states = [];
        foreach (self::plugins() as $name => $summary) {
            try {
                $state = $summary();
            } catch (\Throwable $e) {
                $state = ['color' => 'red', 'reason' => 'its status failed: ' . $e->getMessage()];
            }
            if ($state !== null) {
                $states[$name] = $state;
            }
        }

        return $states;
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
            #wp-admin-bar-gaffer .ab-submenu + .ab-submenu { border-top: 1px solid rgba(240, 246, 252, 0.15); }
        </style>
        HTML;
    }
}
