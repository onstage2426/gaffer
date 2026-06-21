<?php

declare(strict_types=1);

namespace Gaffer\Bootstrap;

use Gaffer\Facades\Config;
use Gaffer\Facades\Twig;
use Gaffer\Twig\Extension;
use Gaffer\Twig\ThemeExtension;
use Twig\Environment;
use Twig\Extension\AttributeExtension;
use Twig\Extension\DebugExtension;
use Twig\Extra\String\StringExtension;
use Twig\Loader\FilesystemLoader;

class TwigBootstrapper
{
    public function __construct(private readonly array $paths) {}

    public function boot(): void
    {
        $loader = new FilesystemLoader();

        foreach ($this->paths as $namespace => $path) {
            $loader->addPath($path, $namespace);
        }

        $debug = (bool) Config::get('theme.debug');

        $twig = new Environment($loader, [
            'cache'     => Config::get('theme.cache')
                ? Config::get('path.cache') . '/views'
                : false,
            'debug'     => $debug,
            'use_yield' => true,
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
