<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use Gaffer\Paths;
use Gaffer\Storage;

final class StorageTest extends TestCase
{
    public function test_private_dirs_deny_web_access_and_are_never_committed(): void
    {
        $theme = sys_get_temp_dir() . '/gaffer-storage-' . bin2hex(random_bytes(4));
        mkdir($theme);
        Paths::set_base($theme);

        $dir = Storage::private_dir('backups/migrate');
        file_put_contents("{$dir}/.gitignore", "custom\n"); // existing files are left alone
        Storage::private_dir('backups/migrate');

        self::assertSame("{$theme}/storage/backups/migrate", $dir);
        self::assertSame("Require all denied\n", file_get_contents("{$dir}/.htaccess"));
        self::assertSame("custom\n", file_get_contents("{$dir}/.gitignore"));
        self::assertStringEndsWith("*\n", (string) file_get_contents(Storage::private_dir('logs') . '/.gitignore'));

        exec('rm -rf ' . escapeshellarg($theme));
    }
}
