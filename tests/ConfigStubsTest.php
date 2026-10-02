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

        self::assertContains('cache', $keys['theme']);
        self::assertNotContains('debug', $keys['theme']); // follows WP_DEBUG now
        self::assertContains('twig_extensions', $keys['theme']);
        self::assertArrayNotHasKey('path', $keys);
        self::assertContains('site_key', $keys['turnstile']);
        self::assertContains('raw_baseline', $keys['console']);
        self::assertNotContains('url', $keys['console']); // SITE_URL in .env now
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function typos(): iterable
    {
        yield 'substring' => ['extensions', 'twig_extensions'];
        yield 'one letter missing' => ['cche', 'cache'];
        yield 'swapped letters' => ['twig_extensoins', 'twig_extensions'];
        yield 'nothing close' => ['completely_unrelated', null];
    }

    #[DataProvider('typos')]
    public function test_did_you_mean(string $typo, ?string $expected): void
    {
        self::assertSame($expected, ConfigStubs::did_you_mean($typo, ConfigStubs::keys()['theme']));
    }

    public function test_theme_comments_above_top_level_keys(): void
    {
        $file = sys_get_temp_dir() . '/gaffer-config-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                // Company name, as shown in the footer
                "company" => "Blueprint",
                "zip" => "8263 CA",
                // Opening hours per day,
                // shown on the contact page
                "opening_hours" => [
                    // not a top-level key
                    "monday" => "08:00",
                ],
            ];
            PHP);

        self::assertSame([
            'company' => 'Company name, as shown in the footer',
            'opening_hours' => 'Opening hours per day, shown on the contact page',
        ], ConfigStubs::theme_comments($file));
        unlink($file);
    }
}
