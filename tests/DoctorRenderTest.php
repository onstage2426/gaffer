<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Console\Commands\DoctorRender;
use RuntimeException;

final class DoctorRenderTest extends TestCase
{
    private const string RESULT = '{"path":"/","status":"ok","error":null,"file":null,"line":null,"redirect":null,"http":200,"notices":[],"bytes":5}';

    public function test_reads_the_result_line_and_ignores_what_plugins_print_after_it(): void
    {
        $stdout = "\n" . DoctorRender::MARKER . self::RESULT . "\n<!-- plugin: redis-cache\nmetrics: 1 -->\n";

        self::assertSame('ok', DoctorRender::parse($stdout)['status']);
    }

    public function test_no_result_line_reports_the_last_output(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ended without a result line; its last output: Fatal error: x');

        DoctorRender::parse('Fatal error: x');
    }

    public function test_an_unreadable_result_line_is_shown(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("isn't readable: {broken");

        DoctorRender::parse(DoctorRender::MARKER . "{broken\n");
    }
}
