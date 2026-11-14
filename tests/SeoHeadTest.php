<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Console\Render\SeoHead;

final class SeoHeadTest extends TestCase
{
    public function test_the_head_tags_search_engines_read(): void
    {
        $html = "<!doctype html><html><head><title>\n  Shoes &amp; boots - Shop  </title>"
            . '<meta name="description" content="All our shoes">'
            . '<link rel="canonical" href="https://x.test/shoes/">'
            . '<meta name="robots" content="index, follow"><meta name="Robots" content="max-image-preview:large">'
            . '</head><body><title>not this</title><meta name="description" content="nor this"></body></html>';

        self::assertSame([
            'title' => 'Shoes & boots - Shop',
            'description' => 'All our shoes',
            'canonical' => 'https://x.test/shoes/',
            'robots' => 'index, follow, max-image-preview:large',
        ], SeoHead::read($html));
    }

    public function test_missing_tags_are_null(): void
    {
        self::assertSame(['title' => null, 'description' => null, 'canonical' => null, 'robots' => null], SeoHead::read('<p>fragment</p>'));
    }
}
