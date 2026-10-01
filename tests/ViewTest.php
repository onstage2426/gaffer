<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\View;
use LogicException;
use Twig\Environment;
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
