<?php

declare(strict_types=1);

namespace Gaffer;

use Gaffer\Bootstrap\AcfBootstrapper;
use Gaffer\Bootstrap\BlocksBootstrapper;
use Gaffer\Bootstrap\IncludesBootstrapper;
use Gaffer\Bootstrap\TwigBootstrapper;
use Gaffer\Facades\Config;
use Gaffer\Facades\Paths;
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
        new IncludesBootstrapper(Paths::includes())->boot();
        new AcfBootstrapper(Paths::storage() . '/acf-json')->boot();
        new BlocksBootstrapper(Paths::blocks())->boot();
    }

    /**
     * Twig environment only (also used for SHORTINIT ajax, where functions.php never runs).
     */
    public static function twig(): void
    {
        if (self::$twig) {
            return;
        }
        self::$twig = true;

        new TwigBootstrapper([
            FilesystemLoader::MAIN_NAMESPACE => Paths::views(),
            ...Paths::view_namespaces(),
        ])->boot();
    }
}
