<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Console\ChildProcess;

final class ChildProcessTest extends TestCase
{
    public function test_reads_both_pipes_so_a_full_stderr_does_not_block(): void
    {
        $code = 'fwrite(STDERR, str_repeat("e", 1 << 20)); echo str_repeat("o", 1 << 20); exit(3);';

        $result = ChildProcess::run([PHP_BINARY, '-r', $code], null, 30);

        self::assertNotNull($result);
        self::assertSame(1 << 20, strlen($result['stdout']));
        self::assertSame(1 << 20, strlen($result['stderr']));
        self::assertSame(3, $result['code']);
        self::assertFalse($result['timed_out']);
    }

    public function test_stops_a_child_at_the_time_limit(): void
    {
        $started = microtime(true);

        $result = ChildProcess::run([PHP_BINARY, '-r', 'echo "started\n"; sleep(30);'], null, 1);

        self::assertNotNull($result);
        self::assertTrue($result['timed_out']);
        self::assertSame("started\n", $result['stdout']);
        self::assertLessThan(5, microtime(true) - $started);
    }
}
