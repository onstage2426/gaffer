<?php

declare(strict_types=1);

namespace Gaffer;

use Gaffer\Mcp\Mcp;
use Gaffer\Twig\Extension;
use Twig\Environment;
use Twig\Extension\AttributeExtension;
use Twig\Extension\DebugExtension;
use Twig\Extra\String\StringExtension;
use Twig\Loader\FilesystemLoader;

class Gaffer
{
    private static bool $configured = false;
    private static bool $twig = false;
    private static bool $booted = false;

    /**
     * Theme root + config only. Safe before WordPress is loaded (ajax.php, CLI).
     */
    public static function configure(string $dir): void
    {
        if (self::$configured) {
            return;
        }
        self::$configured = true;

        Paths::set_base($dir);
        Config::load(Paths::base('config'));
    }

    /**
     * Full boot, called from the theme's functions.php once WordPress is loaded.
     */
    public static function boot(string $dir): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        self::configure($dir);
        self::twig();
        self::includes();
        self::acf();
        self::blocks();
        AdminBar::register();
        Mcp::register();
    }

    /**
     * The Twig environment only (also used for SHORTINIT ajax, where functions.php never runs).
     */
    public static function twig(): void
    {
        if (self::$twig) {
            return;
        }
        self::$twig = true;

        $loader = new FilesystemLoader();
        foreach ([FilesystemLoader::MAIN_NAMESPACE => Paths::views(), ...Paths::view_namespaces()] as $namespace => $path) {
            if (is_dir($path)) {
                $loader->addPath($path, $namespace);
            }
        }

        $debug = self::debug();

        $twig = new Environment($loader, [
            'cache' => Config::get('theme.cache') ? Paths::twig_cache() : false,
            'debug' => $debug,
            'strict_variables' => $debug,
            'use_yield' => true,
        ]);

        if ($debug) {
            $twig->addExtension(new DebugExtension());
        }
        $twig->addExtension(new AttributeExtension(Extension::class));
        $twig->addExtension(new StringExtension());
        foreach (Config::get('theme.twig_extensions') ?? [] as $class) {
            $twig->addExtension(new AttributeExtension($class));
        }

        View::set_env($twig);
    }

    /**
     * Twig debug mode (DebugExtension, strict_variables): on when WordPress's
     * WP_DEBUG is, so it's set per server in wp-config.php, never in theme config.
     */
    public static function debug(): bool
    {
        return defined('WP_DEBUG') && WP_DEBUG;
    }

    /**
     * Installed Gaffer version, e.g. "0.x-dev @ 1a2b3c4", or "dev" when Gaffer
     * isn't installed as a Composer dependency (its own repository).
     *
     * Reads the theme's vendor/composer/installed.php directly: plugins bundle
     * their own copy of Composer\InstalledVersions, and whichever loads first
     * may not know the theme's packages.
     */
    public static function version(): string
    {
        $installed = dirname(__DIR__, 3) . '/composer/installed.php';
        $package = is_file($installed) ? ((require $installed)['versions']['onstage2426/gaffer'] ?? null) : null;

        if (!is_array($package)) {
            return 'dev';
        }

        $reference = (string) ($package['reference'] ?? '');

        return ($package['pretty_version'] ?? 'unknown') . ($reference !== '' ? ' @ ' . substr($reference, 0, 7) : '');
    }

    /**
     * inc/*.php, then inc/*\/*.php.
     */
    private static function includes(): void
    {
        foreach ([...glob(Paths::includes() . '/*.php') ?: [], ...glob(Paths::includes() . '/*/*.php') ?: []] as $file) {
            include_once $file;
        }
    }

    /**
     * ACF local JSON is saved to and loaded from storage/acf-json.
     */
    private static function acf(): void
    {
        $dir = Paths::storage() . '/acf-json';

        add_filter('acf/settings/save_json', fn(): string => $dir);
        add_filter('acf/settings/load_json', function (array $paths) use ($dir): array {
            $paths[] = $dir;
            return $paths;
        });
    }

    /**
     * Every blocks/*\/block.json, and their fields.php as ACF field groups.
     */
    private static function blocks(): void
    {
        foreach (glob(Paths::blocks() . '/*/block.json') ?: [] as $block) {
            register_block_type($block);
        }
        add_action('acf/include_fields', BlockFields::register(...));
    }
}
