<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Console\Baseline;
use RuntimeException;

final class BaselineTest extends TestCase
{
    /** @return array{level: string, check: string, message: string, file: ?string, line: ?int, hint: ?string} */
    private static function finding(string $level, string $message, ?int $line = 1): array
    {
        return ['level' => $level, 'check' => 'views', 'message' => $message, 'file' => 'a.php', 'line' => $line, 'hint' => null];
    }

    public function test_hides_known_warnings_by_message_not_line_and_counts_them(): void
    {
        $baseline = Baseline::from([self::finding('warning', 'old'), self::finding('warning', 'old', 9), self::finding('error', 'broken')], false);
        self::assertSame(2, $baseline->count());

        $result = $baseline->apply([self::finding('warning', 'old', 20), self::finding('warning', 'old', 30), self::finding('warning', 'old', 40), self::finding('warning', 'new'), self::finding('error', 'broken')]);

        self::assertSame(2, $result['known']);
        self::assertSame(0, $result['gone']);
        self::assertSame(['old', 'new', 'broken'], array_column($result['findings'], 'message')); // a third "old" is new
    }

    public function test_counts_known_warnings_that_are_gone(): void
    {
        $baseline = Baseline::from([self::finding('warning', 'old'), self::finding('warning', 'fixed')], true);

        $result = $baseline->apply([self::finding('warning', 'old')]);

        self::assertSame(1, $result['known']);
        self::assertSame(1, $result['gone']);
        self::assertSame([], $result['findings']);
    }

    public function test_prune_drops_what_no_longer_occurs_and_adds_nothing(): void
    {
        $baseline = Baseline::from([self::finding('warning', 'old'), self::finding('warning', 'old'), self::finding('warning', 'fixed')], false);

        $pruned = $baseline->prune([self::finding('warning', 'old'), self::finding('warning', 'caused just now')]);

        self::assertSame([['check' => 'views', 'file' => 'a.php', 'message' => 'old', 'count' => 1]], $pruned->entries);
    }

    public function test_a_file_without_version_is_version_one_and_is_written_with_it(): void
    {
        $old = Baseline::parse('{"wp":true,"warnings":[{"check":"views","file":"a.php","message":"old","count":2}]}');

        self::assertTrue($old->wp);
        self::assertSame(2, $old->count());
        $written = json_decode($old->encode(), true);
        self::assertSame(1, $written['version']);
        self::assertEquals($old, Baseline::parse($old->encode()));
    }

    public function test_refuses_a_newer_version(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is version 2, written by a newer Gaffer');

        Baseline::parse('{"version":2,"wp":false,"warnings":[]}');
    }
}
