<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Search\Health;

final class SearchHealthTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    /**
     * @param array<string, mixed> $change
     * @return array{built: bool, building: bool, error: ?string, version: ?string, pending: ?int, pending_since: ?int, drift: ?int, drift_checked: ?int, served: ?list<string>}
     */
    private static function ok(array $change = []): array
    {
        return [...['built' => true, 'building' => false, 'error' => null, 'version' => 'v1', 'pending' => 0, 'pending_since' => null, 'drift' => 0, 'drift_checked' => self::NOW - 3600, 'served' => []], ...$change];
    }

    public function test_fine(): void
    {
        self::assertSame(['color' => 'green', 'reason' => '2 indexes built and current'], Health::of(['a' => self::ok(), 'b' => self::ok()], ['a' => 'v1', 'b' => 'v1'], self::NOW));
        self::assertSame('1 index built and current', Health::of(['a' => self::ok(['drift' => null, 'served' => null])], ['a' => 'v1'], self::NOW)['reason'], 'not checked yet is not a problem');
    }

    public function test_red_before_orange_and_the_first_reason(): void
    {
        $status = ['a' => self::ok(['version' => 'old']), 'b' => self::ok(['built' => false])];
        self::assertSame(['color' => 'red', 'reason' => 'b: not built'], Health::of($status, ['a' => 'v1', 'b' => 'v1'], self::NOW));
        self::assertSame('red', Health::of(['a' => self::ok(['error' => 'boom'])], ['a' => 'v1'], self::NOW)['color']);
        self::assertSame('red', Health::of(['a' => self::ok(['served' => ['uploads/gaffer-search']])], ['a' => 'v1'], self::NOW)['color']);
        self::assertSame(['color' => 'red', 'reason' => 'gone: not defined in the engine (its definition has problems)'], Health::of([], ['gone' => 'v1'], self::NOW));
    }

    public function test_orange(): void
    {
        $orange = static fn(array $state): string => Health::of(['a' => self::ok($state)], ['a' => 'v1'], self::NOW)['reason'];

        self::assertSame('a: building', $orange(['built' => false, 'building' => true]));
        self::assertSame('a: waits for a rebuild (built from an older definition)', $orange(['version' => 'old']));
        self::assertSame('a: changes wait since 10 min (WP-cron?)', $orange(['pending' => 3, 'pending_since' => self::NOW - 600]));
        self::assertSame('a: 2 document(s) changed without WordPress\'s hooks', $orange(['drift' => 2]));
        self::assertSame('a: the daily check last ran 3 days ago (WP-cron?)', $orange(['drift_checked' => self::NOW - 3 * 86400]), 'a quiet site whose cron stopped: nothing waits, but nothing runs');
        self::assertSame('green', Health::of(['a' => self::ok(['drift_checked' => self::NOW - 86400 - 3600])], ['a' => 'v1'], self::NOW)['color'], 'a day and an hour: the job runs late on a quiet site');
        self::assertSame('green', Health::of(['a' => self::ok(['pending' => 1, 'pending_since' => self::NOW - 60])], ['a' => 'v1'], self::NOW)['color'], 'a minute: the job runs soon');
    }
}
