<?php

declare(strict_types=1);

namespace Gaffer\Search;

use Twig\Markup;

/**
 * The search form's attributes Gaffer's search JavaScript reads (resources/js/search/form-search.js):
 * both ends Gaffer's, so they change together.
 *
 * @internal
 */
final class Attributes
{
    public static function form(bool $available, string $endpoint, string $token): Markup
    {
        if (!$available) {
            return new Markup('', 'UTF-8');
        }
        $attr = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return new Markup('data-gaffer-search-endpoint="' . $attr($endpoint) . '" data-gaffer-search-token="' . $attr($token) . '"', 'UTF-8');
    }
}
