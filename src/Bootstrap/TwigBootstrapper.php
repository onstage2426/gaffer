<?php

declare(strict_types=1);

namespace Gaffer\Bootstrap;

use Gaffer\Config;
use Gaffer\Paths;
use Gaffer\View;
use Gaffer\Twig\Extension;
use Twig\Environment;
use Twig\Extension\AttributeExtension;
use Twig\Extension\DebugExtension;
use Twig\Extra\String\StringExtension;
use Twig\Loader\FilesystemLoader;

class TwigBootstrapper
{
    /** @param array<string, string> $paths Twig namespace => directory */
    public function __construct(private readonly array $paths) {}

    public function boot(): void
    {
        $loader = new FilesystemLoader();

        foreach ($this->paths as $namespace => $path) {
            if (is_dir($path)) {
                $loader->addPath($path, $namespace);
            }
        }

        $debug = (bool) Config::get('theme.debug');

        $twig = new Environment($loader, [
            'cache'     => Config::get('theme.cache') ? Paths::twig_cache() : false,
            'debug'     => $debug,
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
}
