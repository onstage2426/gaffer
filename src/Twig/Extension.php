<?php

declare(strict_types=1);

namespace Gaffer\Twig;

use Gaffer\Ajax;
use Gaffer\Config;
use Twig\Attribute\AsTwigFunction;

/**
 * Gaffer's Twig functions. Presentation helpers only: templates get their
 * content from PHP, not by looking it up.
 *
 * @internal
 */
final class Extension
{
    #[AsTwigFunction("config")]
    public static function config(string $key): mixed
    {
        return Config::get($key);
    }

    #[AsTwigFunction("ajax_url")]
    public static function ajax_url(string $action): string
    {
        return Ajax::url($action);
    }
}
