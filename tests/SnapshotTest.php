<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Console\Render\Snapshot;
use Gaffer\Console\Render\Visitor;
use RuntimeException;

final class SnapshotTest extends TestCase
{
    public function test_most_pages_redirecting_to_one_url_is_a_plugin_answering_instead_of_the_site(): void
    {
        $login = static fn(string $path): array => ['http' => 302, 'redirect' => "https://shop.test/?password-protected=login&redirect_to={$path}", 'error' => null, 'html' => ''];
        $page = ['http' => 200, 'redirect' => null, 'error' => null, 'html' => '<p>x</p>'];

        self::assertSame('https://shop.test/', Snapshot::redirected(['/' => $login('/'), '/a/' => $login('/a/'), '/b/' => $login('/b/'), '/c/' => $page]));
        self::assertNull(Snapshot::redirected(['/' => $page, '/a/' => $page, '/page/2/' => ['http' => 301, 'redirect' => 'https://shop.test/?offset=24', 'error' => null, 'html' => '']]), 'a redirect among pages');
        self::assertNull(Snapshot::redirected(['/' => $login('/'), '/a/' => $login('/a/')]), 'too few pages to tell');
        self::assertNull(Snapshot::redirected(['/' => $login('/'), '/a/' => $login('/a/'), '/b/' => $page, '/c/' => $page]), 'half is not most');
    }

    public function test_reads_its_format_and_the_visitor_as_data(): void
    {
        $snapshot = Snapshot::read('t', '{"format":1,"source":"rendered","created":"x","visitor":{"user":3,"cart":["65:2","bad"],"session":null},"pages":{"/":{"http":200,"redirect":null,"error":null,"html":"<p>"}}}');

        self::assertSame(['user' => 3, 'cart' => ['65:2'], 'session' => null], $snapshot->visitor->to_array());
        self::assertSame('--user=3 --cart=65:2', $snapshot->visitor->label());
        self::assertSame(['--user=3', '--cart=65:2'], $snapshot->visitor->arguments());
        self::assertSame(200, $snapshot->pages['/']['http']);
    }

    public function test_refuses_a_snapshot_without_a_format(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('take it again with php gaffer render:snapshot old');

        Snapshot::read('old', '{"source":"rendered","created":"x","visitor":["--user=3"],"pages":{}}');
    }

    public function test_refuses_a_newer_format(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('format 2, from a newer Gaffer');

        Snapshot::read('new', '{"format":2,"pages":{}}');
    }

    public function test_visitor_from_options(): void
    {
        self::assertTrue(Visitor::from(null)->is_anonymous());
        self::assertSame(['user' => 7, 'cart' => ['12', '13:2'], 'session' => null], Visitor::from('7', ['12', '13:2'])->to_array());
        foreach ([['0', []], ['me', []], [null, ['12x']]] as [$user, $cart]) {
            try {
                Visitor::from($user, $cart);
                self::fail('accepted a malformed visitor');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
