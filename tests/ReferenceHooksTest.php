<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Ai\Reference;
use ReflectionMethod;

final class ReferenceHooksTest extends TestCase
{
    public function test_hooks_are_grouped_under_their_comment(): void
    {
        $code = <<<'PHP'
            <?php
            add_action("init", fn() => null);

            /* Frontend - remove styles */
            remove_action("wp_head", "x");
            add_filter("body_class", "__return_empty_array");

            // Sitemap
            add_shortcode('bs_sitemap', fn(): string => '');
            $name = 'dynamic';
            add_action($name, fn() => null);
            PHP;

        $groups = new ReflectionMethod(Reference::class, 'file_hooks')->invoke(null, $code);

        self::assertSame([
            '' => ['`init`'],
            'Frontend - remove styles' => ['removes `wp_head`', '`body_class`'],
            'Sitemap' => ['shortcode `bs_sitemap`'],
        ], $groups);
    }
}
