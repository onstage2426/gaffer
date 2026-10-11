<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Console\Render\HtmlDiff;
use InvalidArgumentException;

final class HtmlDiffTest extends TestCase
{
    public function test_markup_that_means_the_same_is_equal(): void
    {
        $a = HtmlDiff::lines("<p>Caf&eacute;  &amp; bar</p>\n\n<div>\n  <span>x</span></div>");
        $b = HtmlDiff::lines('<p>Café &amp; bar</p><div><span>x</span>  </div>');

        self::assertSame(['<p>', 'Café & bar', '</p>', '<div>', '<span>', 'x', '</span>', '</div>'], $a);
        self::assertSame([], HtmlDiff::diff($a, $b));
    }

    public function test_shows_changes_with_context(): void
    {
        $old = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'];
        $new = ['a', 'b', 'c', 'D', 'e', 'f', 'g', 'h', 'i', 'j'];

        self::assertSame(['  b', '  c', '- d', '+ D', '  e', '  f', '…', '  h', '  i', '+ j'], HtmlDiff::diff($old, $new));
    }

    public function test_long_diffs_are_cut(): void
    {
        $diff = HtmlDiff::diff(['x'], array_map('strval', range(1, 50)), 2, 10);

        self::assertCount(11, $diff);
        self::assertSame('… 41 more lines', $diff[10]);
    }

    public function test_ignore_and_select(): void
    {
        self::assertSame("<div data-delay=''></div>", HtmlDiff::without("<div data-delay='300'></div>", ["/(?<=data-delay=')\\d+/"]));
        self::assertSame(['<p class="faq">Hi</p>'], HtmlDiff::select_all('<main><p class="faq">Hi</p><p>No</p></main>', '.faq'));

        $this->expectException(InvalidArgumentException::class);
        HtmlDiff::without('', ['no delimiters']);
    }

    public function test_a_long_page_still_lines_up(): void
    {
        $menu = static function (array $changes): array {
            $lines = [];
            for ($i = 0; $i < 3000; $i++) {
                array_push($lines, '<li>', "<a href=\"/c/{$i}/\">", $changes[$i] ?? "Item {$i}", '</a>', '</li>');
            }
            return $lines;
        };
        $old = $menu([]);
        $new = $menu([1500 => 'Renamed']);
        array_splice($new, 2500 * 5, 0, ['<li>', '<a href="/new/">', 'New', '</a>', '</li>']);

        $start = microtime(true);
        $diff = HtmlDiff::diff($old, $new, 1, 100);

        self::assertLessThan(2.0, microtime(true) - $start);
        self::assertContains('- Item 1500', $diff);
        self::assertContains('+ Renamed', $diff);
        self::assertContains('+ <a href="/new/">', $diff);
        self::assertLessThan(20, count($diff));
    }

    public function test_hunks_split_at_gaps(): void
    {
        self::assertSame([['  b', '- c', '+ C'], ['+ j']], HtmlDiff::hunks(['  b', '- c', '+ C', '…', '+ j', '…']));
    }
}
