<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Console\Render\Snapshot;

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
}
