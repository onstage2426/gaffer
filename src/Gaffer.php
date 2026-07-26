<?php

declare(strict_types=1);

namespace Gaffer;

use Gaffer\Bootstrap\AcfBootstrapper;
use Gaffer\Bootstrap\BlocksBootstrapper;
use Gaffer\Bootstrap\TwigBootstrapper;
use Gaffer\Bootstrap\IncludesBootstrapper;
use Gaffer\Facades\Paths;
use Twig\Loader\FilesystemLoader;

class Gaffer
{
    public static function boot(): void
    {
        $paths = [FilesystemLoader::MAIN_NAMESPACE => Paths::views()];

        foreach (Paths::namespaces() as $namespace => $path) {
            $paths[$namespace] = $path;
        }

        new TwigBootstrapper($paths)->boot();
        new IncludesBootstrapper(Paths::includes())->boot();
        new AcfBootstrapper(Paths::storage() . '/acf-json')->boot();
        new BlocksBootstrapper(Paths::blocks())->boot();
    }
}
