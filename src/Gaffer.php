<?php

declare(strict_types=1);

namespace Gaffer;

use Gaffer\Bootstrap\AcfBootstrapper;
use Gaffer\Bootstrap\BlocksBootstrapper;
use Gaffer\Bootstrap\TwigBootstrapper;
use Gaffer\Bootstrap\IncludesBootstrapper;
use Gaffer\Facades\Config;
use Twig\Loader\FilesystemLoader;

class Gaffer
{
    public static function boot(): void
    {
        $paths = [];

        if ($views = Config::get('path.views')) {
            $paths[FilesystemLoader::MAIN_NAMESPACE] = $views;
        }

        foreach (Config::get('path.namespaces') ?? [] as $namespace => $path) {
            $paths[$namespace] = $path;
        }

        new TwigBootstrapper($paths)->boot();

        $includes = Config::get('path.includes');
        if ($includes !== null) {
            new IncludesBootstrapper($includes)->boot();
        }

        $storage = Config::get('path.storage');
        if ($storage !== null) {
            new AcfBootstrapper("{$storage}/acf-json")->boot();
        }

        $blocks = Config::get('path.blocks');
        if ($blocks !== null) {
            new BlocksBootstrapper($blocks)->boot();
        }
    }
}
