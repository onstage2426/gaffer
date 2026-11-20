<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Console\Render\HtmlProblems;

final class HtmlProblemsTest extends TestCase
{
    public function test_alpine_directives_outside_x_data(): void
    {
        $html = '<html><body><div x-data="{ open: false }"><button @click="open = !open" :class="open && \'a\'">x</button></div>'
            . '<span x-show="$store.vat.incl">incl.</span><span x-show="$store.vat.incl">again</span><p x-init="go()" x-cloak></p>'
            . '<section x-data><i x-text="$store.vat.label"></i></section></body></html>';
        self::assertSame(['<span x-show="$store.vat.incl">'], HtmlProblems::alpine_outside_scope($html));
        self::assertSame([], HtmlProblems::alpine_outside_scope('<b @click="x">no Alpine on this page</b>'));
    }

    public function test_theme_compat_sidebar(): void
    {
        self::assertTrue(HtmlProblems::theme_compat_sidebar('<main></main><div id="sidebar" role="complementary"><ul>'));
        self::assertFalse(HtmlProblems::theme_compat_sidebar('<aside id="sidebar">'));
    }

    public function test_assets_under_the_public_url(): void
    {
        $html = '<link rel="stylesheet" href="https://x.test/wp-content/themes/t/public/assets/app-1.css?ver=2">'
            . '<script type="module" src="//x.test/wp-content/themes/t/public/assets/app-2.js"></script>'
            . '<link rel="modulepreload" href="https://x.test/wp-content/themes/t/public/assets/app-1.css">'
            . '<script src="https://x.test/wp-includes/js/jquery.js"></script>';
        self::assertSame(['assets/app-1.css', 'assets/app-2.js'], HtmlProblems::assets($html, 'https://x.test/wp-content/themes/t/public'));
    }
}
