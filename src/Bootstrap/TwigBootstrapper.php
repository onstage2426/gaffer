<?php

declare(strict_types=1);

namespace Gaffer\Bootstrap;

use Gaffer\Support\Facades\Config;
use Gaffer\Support\Facades\Twig;
use Gaffer\Twig\Extension;
use Gaffer\Twig\ThemeExtension;
use Twig\Environment;
use Twig\Extension\AttributeExtension;
use Twig\Extension\DebugExtension;
use Twig\Extra\String\StringExtension;
use Twig\Loader\FilesystemLoader;

class TwigBootstrapper implements Bootable
{
    public function boot(): void
    {
        $loader = new FilesystemLoader(Config::get("path.views"));

        $loader->addPath(Config::get("path.blocks"), "block");
        $loader->addPath(Config::get("path.ajax"), "ajax");

        $debug = Config::get("theme.debug");

        $twig = new Environment($loader, [
            "cache" => match(Config::get("theme.cache")) {
                true    => Config::get("path.cache") . "/views",
                default => false,
            },
            "debug"     => $debug,
            "use_yield" => true,
        ]);

        if ($debug) {
            $twig->addExtension(new DebugExtension());
        }

        $twig->addExtension(new AttributeExtension(Extension::class));
        $twig->addExtension(new AttributeExtension(ThemeExtension::class));
        $twig->addExtension(new StringExtension());

        Twig::set($twig);
    }
}
