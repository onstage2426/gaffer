<?php

declare(strict_types=1);

namespace Gaffer\Twig;

use Gaffer\Ajax;
use Gaffer\Config;
use Gaffer\Paths;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Gaffer's Twig functions and filters. Presentation helpers only: templates get their
 * content from PHP, not by looking it up.
 *
 * @internal
 */
final class Extension
{
    private static ?string $text_domain = null;

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

    /**
     * A theme string through gettext, in the theme's text domain (style.css's
     * Text Domain header): {{ 'Read more'|t }}. WPML/Polylang translate it.
     */
    #[AsTwigFilter("t")]
    public static function t(string $text): string
    {
        return \__($text, self::text_domain());
    }

    private static function text_domain(): string
    {
        if (self::$text_domain === null) {
            $file = Paths::base('style.css');
            $header = is_file($file) ? (string) file_get_contents($file, length: 8192) : '';
            if (!preg_match('/^[ \t\/*#@]*Text Domain:(.*)$/mi', $header, $m) || trim($m[1]) === '') {
                throw new \LogicException("The |t filter needs a Text Domain header in the theme's style.css.");
            }
            self::$text_domain = trim($m[1]);
        }

        return self::$text_domain;
    }
}
