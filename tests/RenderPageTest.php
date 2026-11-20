<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Console\Commands\RenderPage;
use RuntimeException;

final class RenderPageTest extends TestCase
{
    private const string RESULT = '{"v":1,"path":"/","status":"ok","error":null,"file":null,"line":null,"redirect":null,"http":200,"notices":[],"bytes":5}';

    public function test_reads_the_result_line_and_ignores_what_plugins_print_after_it(): void
    {
        $stdout = "\n" . RenderPage::MARKER . self::RESULT . "\n<!-- plugin: redis-cache\nmetrics: 1 -->\n";

        self::assertSame('ok', RenderPage::parse($stdout)['status']);
    }

    public function test_no_result_line_reports_the_last_output(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ended without a result line; its last output: Fatal error: x');

        RenderPage::parse('Fatal error: x');
    }

    public function test_an_unreadable_result_line_is_shown(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("isn't readable: {broken");

        RenderPage::parse(RenderPage::MARKER . "{broken\n");
    }

    public function test_reads_the_first_result_line_not_a_marker_in_later_output(): void
    {
        $stdout = "notice\n" . RenderPage::MARKER . self::RESULT . "\n" . RenderPage::MARKER . '{"v":1,"status":"error"}' . "\n";

        self::assertSame('ok', RenderPage::parse($stdout)['status']);
    }

    public function test_a_marker_inside_a_line_is_not_the_result(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ended without a result line');

        RenderPage::parse('<p>' . RenderPage::MARKER . self::RESULT . "</p>\n");
    }

    public function test_refuses_a_newer_protocol(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('protocol version 2');

        RenderPage::parse("\n" . RenderPage::MARKER . str_replace('"v":1', '"v":2', self::RESULT) . "\n");
    }

    public function test_refuses_a_result_without_a_version(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('protocol version unknown');

        RenderPage::parse("\n" . RenderPage::MARKER . str_replace('"v":1,', '', self::RESULT) . "\n");
    }

    public function test_admin_and_login_paths_are_not_rendered(): void
    {
        foreach (['/wp-admin/', '/wp-admin', '/wp-admin/admin.php?page=wc-orders&action=edit&id=929', '/sub/wp-admin/edit.php', '/wp-login.php', '/wp-login.php?action=lostpassword'] as $path) {
            self::assertTrue(RenderPage::is_admin_path($path), $path);
        }
        foreach (['/', '/wp-admin-tips/', '/blog/wp-login-help/', '/shop/?s=wp-admin', '/contact/'] as $path) {
            self::assertFalse(RenderPage::is_admin_path($path), $path);
        }
    }
}
