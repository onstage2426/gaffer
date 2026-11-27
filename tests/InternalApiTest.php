<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Console\Checks\InternalApiCheck;
use Gaffer\Console\GafferReferences;
use Gaffer\Console\PublicApi;

final class InternalApiTest extends TestCase
{
    public function test_resolves_imports_aliases_and_qualified_names(): void
    {
        $code = <<<'PHP'
            <?php
            namespace Theme;

            use Gaffer\View;
            use Gaffer\Paths as P;
            use Gaffer\Types\Post;
            use DateTimeImmutable;

            final class Offer extends Post implements \Gaffer\Nope, Other
            {
                use SomeTrait;

                public function x(): void
                {
                    View::render('a.twig');
                    $dir = P::storage();
                    \Gaffer\Config::get('x');
                    $f = function () use ($dir) { return Search::shop_url(); };
                    $this->title();
                    new \Gaffer\Console\Report();
                    self::thing();
                    $c = Post::class;
                }
            }
            PHP;
        $refs = array_map(static fn(array $r): string => $r['class'] . ($r['member'] !== null ? '::' . $r['member'] : '') . '@' . $r['line'], GafferReferences::in($code));

        self::assertSame([
            'Gaffer\View@4', 'Gaffer\Paths@5', 'Gaffer\Types\Post@6',
            'Gaffer\Types\Post@9', 'Gaffer\Nope@9',
            'Gaffer\View::render@15', 'Gaffer\Paths::storage@16', 'Gaffer\Config::get@17',
            'Gaffer\Console\Report@20', 'Gaffer\Types\Post@22',
        ], $refs);
    }

    public function test_status_of_public_internal_and_missing_code(): void
    {
        self::assertSame('public', PublicApi::status('Gaffer\View', 'render'));
        self::assertSame('public', PublicApi::status('Gaffer\Storage', 'private_dir'));
        self::assertSame('internal', PublicApi::status('Gaffer\View', 'env'));
        self::assertSame('internal', PublicApi::status('Gaffer\Paths', 'storage'));
        self::assertSame('internal', PublicApi::status('Gaffer\BlockFields'));
        self::assertSame('internal', PublicApi::status('Gaffer\Console\Report'));
        self::assertSame('public', PublicApi::status('Gaffer\Console\Console', 'boot'));
        self::assertSame('public', PublicApi::status('Gaffer\Forms\FormAction', 'METHOD')); // inherited from AjaxAction
        self::assertSame('missing', PublicApi::status('Gaffer\Search\Search', 'is_archive'));
        self::assertSame('missing', PublicApi::status('Gaffer\Nope'));
    }

    public function test_reports_each_internal_or_missing_symbol_once(): void
    {
        $code = <<<'PHP'
            <?php
            use Gaffer\Paths;
            use Gaffer\View;
            use Gaffer\BlockFields;
            Paths::base('x');
            Paths::storage();
            Paths::storage();
            View::env();
            BlockFields::group('x');
            \Gaffer\Search\Search::is_archive();
            PHP;
        $findings = array_map(static fn(array $f): string => $f['message'] . '@' . $f['line'], InternalApiCheck::findings($code));

        self::assertSame([
            "Uses Gaffer's internal Gaffer\BlockFields@4",
            "Uses Gaffer's internal Gaffer\Paths::storage@6",
            "Uses Gaffer's internal Gaffer\View::env@8",
            "Uses Gaffer\Search\Search::is_archive, which this Gaffer doesn't have@10",
        ], $findings);
        self::assertStringStartsWith('Use Gaffer\Storage::private_dir()', InternalApiCheck::findings("<?php \\Gaffer\\Paths::storage();")[0]['hint']);
    }

    public function test_experimental_api_is_an_info_not_a_warning(): void
    {
        $findings = InternalApiCheck::findings("<?php\nuse Gaffer\\Search\\Search;\nSearch::archive();\n\\Gaffer\\Types\\Post::current();\n");

        self::assertCount(1, $findings);
        self::assertSame('Uses Gaffer\Search\Search, which is experimental', $findings[0]['message']);
        self::assertTrue($findings[0]['experimental']);
        self::assertTrue(PublicApi::experimental(\Gaffer\Search\Archive::class));
        self::assertFalse(PublicApi::experimental(\Gaffer\Types\Post::class, 'current'));
    }
}
