<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Console\ConfigStubs;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConfigStubsTest extends TestCase
{
    public function test_reads_keys_from_gaffers_own_stubs(): void
    {
        $keys = ConfigStubs::keys();

        self::assertContains('debug', $keys['theme']);
        self::assertContains('twig_extensions', $keys['theme']);
        self::assertArrayNotHasKey('path', $keys);
        self::assertContains('site_key', $keys['turnstile']);
        self::assertContains('url', $keys['console']);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function typos(): iterable
    {
        yield 'substring' => ['extensions', 'twig_extensions'];
        yield 'one letter missing' => ['debg', 'debug'];
        yield 'swapped letters' => ['twig_extensoins', 'twig_extensions'];
        yield 'nothing close' => ['completely_unrelated', null];
    }

    #[DataProvider('typos')]
    public function test_did_you_mean(string $typo, ?string $expected): void
    {
        self::assertSame($expected, ConfigStubs::did_you_mean($typo, ConfigStubs::keys()['theme']));
    }
}
