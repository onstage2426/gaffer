<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Console\Checks\DeprecationsCheck;
use Gaffer\Console\PublicApi;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Source;

final class DeprecationsTest extends TestCase
{
    public function test_deprecated_members_are_read_from_the_attribute(): void
    {
        self::assertSame([
            ['class' => DeprecatedFixture::class, 'name' => 'old', 'kind' => 'method', 'message' => DeprecatedFixture::class . '::old() is deprecated since 1.1: use fresh()'],
            ['class' => DeprecatedFixture::class, 'name' => 'old_static', 'kind' => 'method', 'message' => DeprecatedFixture::class . '::old_static() is deprecated'],
            ['class' => DeprecatedFixture::class, 'name' => 'OLD', 'kind' => 'constant', 'message' => DeprecatedFixture::class . '::OLD is deprecated since 1.2'],
        ], PublicApi::deprecations([DeprecatedFixture::class]));
    }

    public function test_gaffer_has_no_deprecations_in_its_public_api_yet(): void
    {
        // Remove this test with the first deprecation; the snapshot (api.txt) marks them.
        self::assertSame([], PublicApi::deprecations(PublicApi::classes()));
    }

    public function test_calling_a_deprecated_method_triggers_a_notice(): void
    {
        $messages = [];
        set_error_handler(static function (int $level, string $message) use (&$messages): bool {
            $messages[] = $message;
            return true;
        }, E_USER_DEPRECATED);
        try {
            new DeprecatedFixture()->old();
        } finally {
            restore_error_handler();
        }

        self::assertSame(['Method ' . DeprecatedFixture::class . '::old() is deprecated since 1.1, use fresh()'], $messages);
    }

    public function test_uses_in_php(): void
    {
        $code = <<<'PHP'
            <?php
            namespace Theme\Types;

            use Gaffer\Tests\DeprecatedFixture as Fixture;
            use Gaffer\Tests\TestCase;

            final class Thing extends Fixture
            {
                public const string OLD = 'theirs';

                public function old(): void {}

                public function run($post): void
                {
                    $post->old();
                    $post?->OLD_STATIC();
                    Fixture::old_static();
                    \Gaffer\Tests\DeprecatedFixture::OLD;
                    TestCase::old_static();
                    self::old_static();
                    $post->fresh();
                    $old = old_static();
                }
            }
            PHP;

        self::assertSame(
            [9, 11, 15, 16, 17, 18, 20],
            array_column(DeprecationsCheck::php_usages($code, PublicApi::deprecations([DeprecatedFixture::class])), 1),
        );
    }

    public function test_declarations_outside_a_subclass_are_not_overrides(): void
    {
        $code = "<?php\nclass Plain { public const OLD = 1; public function old(): void {} }\n";

        self::assertSame([], DeprecationsCheck::php_usages($code, PublicApi::deprecations([DeprecatedFixture::class])));
    }

    public function test_uses_in_twig(): void
    {
        $env = new Environment(new ArrayLoader());
        $module = $env->parse($env->tokenize(new Source("{{ post.title() }}\n{{ post.old() }}\n{{ post.old }}\n{{ old }}", 'x.twig')));

        self::assertSame([2, 3], array_column(DeprecationsCheck::twig_usages($module, PublicApi::deprecations([DeprecatedFixture::class])), 1));
    }
}

final class DeprecatedFixture
{
    #[\Deprecated(since: '1.2')]
    public const string OLD = 'old';

    #[\Deprecated(message: 'use fresh()', since: '1.1')]
    public function old(): void {}

    #[\Deprecated]
    public static function old_static(): void {}

    public function fresh(): void {}

    /** @internal */
    #[\Deprecated]
    public function hidden(): void {}
}
