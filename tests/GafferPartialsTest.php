<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Paths;
use Gaffer\Twig\Extension;
use PHPUnit\Framework\TestCase as BaseTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Twig\Environment;
use Twig\Extension\AttributeExtension;
use Twig\Extra\String\StringExtension;
use Twig\Loader\FilesystemLoader;
use Twig\Source;

/**
 * Gaffer's own partials (@gaffer/…) use only Twig's built-ins and what Gaffer registers: a
 * theme's Twig extension may name a filter like Gaffer's later ones would, and a partial using a
 * name the theme also has would get the theme's (API.md, "Reserved names").
 */
final class GafferPartialsTest extends BaseTestCase
{
    public function test_partials_compile_with_only_gaffers_twig_names(): void
    {
        $twig = new Environment(new FilesystemLoader([Paths::gaffer_views()]));
        $twig->addExtension(new AttributeExtension(Extension::class));
        $twig->addExtension(new StringExtension());

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(Paths::gaffer_views(), RecursiveDirectoryIterator::SKIP_DOTS));
        $count = 0;
        foreach ($files as $file) {
            if ($file->getExtension() !== 'twig') {
                continue;
            }
            $twig->compileSource(new Source((string) file_get_contents($file->getPathname()), substr($file->getPathname(), strlen(Paths::gaffer_views()) + 1)));
            $count++;
        }

        self::assertGreaterThan(0, $count);
    }
}
