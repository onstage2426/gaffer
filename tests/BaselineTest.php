<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Console\Baseline;

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
}
