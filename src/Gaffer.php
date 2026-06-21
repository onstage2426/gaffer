<?php

declare(strict_types=1);

namespace Gaffer;

use Gaffer\Bootstrap\TwigBootstrapper;
use Gaffer\Bootstrap\IncludesBootstrapper;

class Gaffer
{
    public static function boot(string $includes, array $paths): void
    {
        new TwigBootstrapper($paths)->boot();
        new IncludesBootstrapper($includes)->boot();
    }
}
