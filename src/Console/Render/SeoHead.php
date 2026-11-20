<?php

declare(strict_types=1);

namespace Gaffer\Console\Render;

use Dom\HTMLDocument;

/**
 * What a rendered page tells search engines (pure, unit-tested): the title, meta description,
 * canonical and robots in its head. Read from the HTML, not from an SEO plugin's PHP API: that
 * answers differently outside a real request (Yoast's titles from WP-CLI), the page is what
 * crawlers get.
 *
 * @internal
 */
final class SeoHead
{
    /**
     * Null for a tag the page doesn't have; robots joins every robots meta (crawlers combine them).
     *
     * @return array{title: ?string, description: ?string, canonical: ?string, robots: ?string}
     */
    public static function read(string $html): array
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $title = $document->querySelector('head title')?->textContent;
        $robots = array_map(
            static fn(\Dom\Element $meta): string => trim((string) $meta->getAttribute('content')),
            iterator_to_array($document->querySelectorAll('head meta[name="robots" i][content]'), false),
        );

        return [
            'title' => $title !== null ? trim(preg_replace('/\s+/', ' ', $title) ?? $title) : null,
            'description' => $document->querySelector('head meta[name="description" i][content]')?->getAttribute('content'),
            'canonical' => $document->querySelector('head link[rel="canonical" i][href]')?->getAttribute('href'),
            'robots' => $robots !== [] ? implode(', ', $robots) : null,
        ];
    }
}
