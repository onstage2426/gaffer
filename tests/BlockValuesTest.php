<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\BlockValues;

final class BlockValuesTest extends TestCase
{
    public function test_undoes_kses_ampersands_once_and_nothing_else(): void
    {
        self::assertSame('Grind & Witgrind', BlockValues::decode('Grind &amp; Witgrind'));
        self::assertSame('Grind & Witgrind', BlockValues::decode('Grind & Witgrind')); // saved without kses
        self::assertSame('x &lt;script&gt; &amp;', BlockValues::decode('x &lt;script&gt; &amp;amp;'));
        self::assertSame(
            ['title' => 'Alle Grind & Witgrind', 'url' => '/opsluitbanden/?a=1&show=all', 'target' => ''],
            BlockValues::decode(['title' => 'Alle Grind &amp; Witgrind', 'url' => '/opsluitbanden/?a=1&amp;show=all', 'target' => '']),
        );
        self::assertSame(3, BlockValues::decode(3));
    }
}
