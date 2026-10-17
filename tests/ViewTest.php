<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Paths;
use Gaffer\Twig\Extension;
use Gaffer\View;
use LogicException;
use Twig\Environment;
use Twig\Extension\AttributeExtension;
use Twig\Loader\ArrayLoader;

final class ViewTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        self::reset(View::class, 'env', null);
        self::reset(View::class, 'shared', []);
        self::reset(View::class, 'resolved', []);
        self::reset(View::class, 'use_shared', true);
    }

    public function test_t_translates_in_the_themes_text_domain(): void
    {
        $theme = sys_get_temp_dir() . '/gaffer-t-' . bin2hex(random_bytes(4));
        mkdir($theme);
        Paths::set_base($theme);
        self::reset(Extension::class, 'text_domain', null);
        $twig = new Environment(new ArrayLoader(['t.twig' => "{{ 'Read more'|t }}"]));
        $twig->addExtension(new AttributeExtension(Extension::class));

        try {
            $twig->render('t.twig');
            self::fail('No Text Domain header');
        } catch (\Twig\Error\RuntimeError $e) {
            self::assertStringContainsString('Text Domain header', $e->getMessage());
        }

        file_put_contents("{$theme}/style.css", "/*\nTheme Name: X\nText Domain: a4hydrauliek\n*/");
        self::assertSame('a4hydrauliek:Read more', $twig->render('t.twig'));
        unlink("{$theme}/style.css");
        rmdir($theme);
    }

    public function test_env_throws_before_boot(): void
    {
        $this->expectException(LogicException::class);

        View::env();
    }

    public function test_fetch_merges_shared_and_template_data(): void
    {
        View::set_env(new Environment(new ArrayLoader(['t.twig' => '{{ a }}-{{ b }}'])));
        View::share('a', 'shared');
        View::share('b', 'shared-b');

        self::assertSame('shared-local', View::fetch('t.twig', ['b' => 'local']));
    }

    public function test_a_shared_closure_the_render_passes_itself_is_not_resolved(): void
    {
        View::set_env(new Environment(new ArrayLoader(['t.twig' => '{{ menu ?? "none" }}'])));
        $calls = 0;
        View::share('menu', function () use (&$calls): string {
            $calls++;
            return 'menu';
        });

        self::assertSame('none', View::fetch('t.twig', ['menu' => null]));
        self::assertSame(0, $calls);
        self::assertSame('menu', View::fetch('t.twig'));
        self::assertSame(1, $calls);
    }

    public function test_ajax_renders_without_shared_data(): void
    {
        View::set_env(new Environment(new ArrayLoader(['t.twig' => '{{ a ?? "none" }}'])));
        $calls = 0;
        View::share('a', function () use (&$calls): string {
            $calls++;
            return 'shared';
        });
        View::without_shared();

        self::assertSame('none', View::fetch('t.twig'));
        self::assertSame(0, $calls);
    }

    public function test_shared_closures_run_once_per_request(): void
    {
        View::set_env(new Environment(new ArrayLoader(['t.twig' => '{{ n }}'])));
        $calls = 0;
        View::share('n', function () use (&$calls): int {
            return ++$calls;
        });

        self::assertSame('1', View::fetch('t.twig'));
        self::assertSame('1', View::fetch('t.twig'));
        self::assertSame(1, $calls);
    }

    public function test_sharing_again_replaces_the_cached_value(): void
    {
        View::set_env(new Environment(new ArrayLoader(['t.twig' => '{{ v }}'])));
        View::share('v', fn(): string => 'first');
        View::fetch('t.twig');
        View::share('v', fn(): string => 'second');

        self::assertSame('second', View::fetch('t.twig'));
    }

    public function test_only_closures_are_called(): void
    {
        View::set_env(new Environment(new ArrayLoader(['t.twig' => '{{ v }}'])));
        View::share('v', 'phpinfo');

        self::assertSame('phpinfo', View::fetch('t.twig'));
    }

    public function test_render_echoes(): void
    {
        View::set_env(new Environment(new ArrayLoader(['t.twig' => 'hi'])));

        $this->expectOutputString('hi');
        View::render('t.twig');
    }
}
