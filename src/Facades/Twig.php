<?php

declare(strict_types=1);

namespace Gaffer\Facades;

use Twig\Environment;

class Twig
{
    private static Environment $instance;

    public static function set(Environment $env): void
    {
        self::$instance = $env;
    }

    public static function env(): Environment
    {
        return self::$instance;
    }
}
