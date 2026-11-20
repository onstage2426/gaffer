<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\AcfValues;

final class AcfValuesTest extends TestCase
{
    public function test_undoes_kses_ampersands_once_and_nothing_else(): void
    {
        self::assertSame('Grind & Witgrind', AcfValues::decode('Grind &amp; Witgrind'));
        self::assertSame('Grind & Witgrind', AcfValues::decode('Grind & Witgrind')); // saved without kses
        self::assertSame('x &lt;script&gt; &amp;', AcfValues::decode('x &lt;script&gt; &amp;amp;'));
        self::assertSame(
            ['title' => 'Alle Grind & Witgrind', 'url' => '/opsluitbanden/?a=1&show=all', 'target' => ''],
            AcfValues::decode(['title' => 'Alle Grind &amp; Witgrind', 'url' => '/opsluitbanden/?a=1&amp;show=all', 'target' => '']),
        );
        self::assertSame(3, AcfValues::decode(3));
    }

    public function test_containers_and_wysiwyg_keep_their_values(): void
    {
        $rows = [['antwoord' => '<p>A &amp; B</p>']];
        self::assertSame($rows, AcfValues::loaded($rows, ['type' => 'repeater']));
        self::assertSame('<p>&amp;</p>', AcfValues::loaded('<p>&amp;</p>', ['type' => 'wysiwyg']));
        self::assertSame('A & B', AcfValues::loaded('A &amp; B', ['type' => 'text']));
    }
}
