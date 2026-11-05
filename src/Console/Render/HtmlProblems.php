<?php

declare(strict_types=1);

namespace Gaffer\Console\Render;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Mistakes only the rendered page shows (pure, unit-tested): what doctor --wp looks for in
 * the pages it renders, and in the front page a visitor gets.
 *
 * @internal
 */
final class HtmlProblems
{
    /** Alpine directives that need an x-data scope (x-init and x-cloak work without one). */
    private const string DIRECTIVE = '/^(?:x-(?:show|text|html|bind|on|model|modelable|for|if|ref|effect|transition|intersect|trap|collapse|anchor|teleport|id)\b|[:@])/';

    /**
     * Elements with Alpine directives and no x-data on them or above them: Alpine never
     * initializes them, so they do nothing, without an error. Each as a short description
     * (`<span x-show="$store.vat.incl">`), once. Empty for a page without any x-data (no Alpine).
     *
     * @return list<string>
     */
    public static function alpine_outside_scope(string $html): array
    {
        if (!str_contains($html, 'x-data')) {
            return [];
        }
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $found = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            $directive = self::directive($element);
            if ($directive === null || self::in_scope($element)) {
                continue;
            }
            $value = (string) $element->getAttribute($directive);
            $found['<' . strtolower($element->localName) . " {$directive}=\"" . (strlen($value) > 40 ? substr($value, 0, 40) . '…' : $value) . '">'] = true;
        }

        return array_keys($found);
    }

    /**
     * WordPress's deprecated theme-compat sidebar.php is on the page (get_sidebar() in a theme
     * without sidebar.php, e.g. from WooCommerce's woocommerce_sidebar hook): a search form, a
     * list of every page (checkout and account too) and empty archive headings.
     */
    public static function theme_compat_sidebar(string $html): bool
    {
        return preg_match('/<div id="sidebar" role="complementary">/', $html) === 1;
    }

    /**
     * The stylesheets, scripts and preloads the page loads from under $prefix (the theme's
     * public/ URL), as paths relative to it, without a query string.
     *
     * @return list<string>
     */
    public static function assets(string $html, string $prefix): array
    {
        $prefix = (string) preg_replace('#^https?:#', '', rtrim($prefix, '/') . '/');
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $found = [];
        foreach ([['link', 'href'], ['script', 'src']] as [$tag, $attribute]) {
            foreach ($document->getElementsByTagName($tag) as $element) {
                $url = (string) preg_replace('#^https?:#', '', (string) $element->getAttribute($attribute));
                if (str_starts_with($url, $prefix)) {
                    $found[strtok(substr($url, strlen($prefix)), '?#') ?: ''] = true;
                }
            }
        }
        unset($found['']);

        return array_map(strval(...), array_keys($found));
    }

    private static function directive(Element $element): ?string
    {
        foreach ($element->getAttributeNames() as $name) {
            if (preg_match(self::DIRECTIVE, $name)) {
                return $name;
            }
        }

        return null;
    }

    private static function in_scope(Element $element): bool
    {
        for ($node = $element; $node !== null; $node = $node->parentElement) {
            if ($node->hasAttribute('x-data')) {
                return true;
            }
        }

        return false;
    }
}
