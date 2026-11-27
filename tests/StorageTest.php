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

        self::assertSame("{$theme}/uploads/gaffer/backups/migrate", $dir);
        self::assertSame("Require all denied\n", file_get_contents("{$theme}/uploads/gaffer/.htaccess"));
        self::assertSame("Require all denied\n", file_get_contents("{$dir}/.htaccess"));
        self::assertSame("custom\n", file_get_contents("{$dir}/.gitignore"));
        self::assertStringEndsWith("*\n", (string) file_get_contents(Storage::private_dir('logs') . '/.gitignore'));

        exec('rm -rf ' . escapeshellarg($theme));
    }

    public function test_gaffers_own_folders_are_reserved(): void
    {
        $theme = sys_get_temp_dir() . '/gaffer-own-' . bin2hex(random_bytes(4));
        mkdir("{$theme}/uploads/gaffer/logs", 0775, true);
        mkdir("{$theme}/uploads/gaffer/backups/migrate", 0775, true);
        file_put_contents("{$theme}/uploads/gaffer/logs/spam.log", "x\n");
        file_put_contents("{$theme}/uploads/gaffer/logs/theirs.log", "x\n");
        file_put_contents("{$theme}/uploads/gaffer/backups/migrate/20261101-120000-migrate-set.json", '{}');
        Paths::set_base($theme);

        self::assertSame("{$theme}/uploads/gaffer/gaffer/logs", Storage::gaffer_dir('logs'));
        self::assertSame("Require all denied\n", file_get_contents("{$theme}/uploads/gaffer/gaffer/.htaccess"));
        self::assertSame(["{$theme}/uploads/gaffer/logs/spam.log", "{$theme}/uploads/gaffer/backups/migrate/20261101-120000-migrate-set.json"], Storage::gaffer_before(), 'only Gaffer\'s own files');
        self::assertSame("{$theme}/uploads/gaffer/logs", Storage::private_dir('logs'), 'a theme may still name its own folder logs');
        foreach (['gaffer', 'gaffer/logs', 'Gaffer-x'] as $name) {
            try {
                Storage::private_dir($name);
                self::fail("{$name} is Gaffer's");
            } catch (\InvalidArgumentException) {
            }
        }
        file_put_contents(Storage::gaffer_dir('backups/migrate') . '/20261127-090000-migrate-set.json', '{}');
        self::assertSame(['20261127-090000-migrate-set.json', '20261101-120000-migrate-set.json'], array_map('basename', \Gaffer\Console\Migrate\Backup::all()), 'rollback finds both, newest first');

        exec('rm -rf ' . escapeshellarg($theme));
    }

    public function test_a_folder_from_before_is_listed_but_no_longer_used(): void
    {
        $theme = sys_get_temp_dir() . '/gaffer-storage-' . bin2hex(random_bytes(4));
        mkdir("{$theme}/storage/offers/12", 0775, true);
        file_put_contents("{$theme}/storage/offers/.gitignore", "*\n");
        mkdir("{$theme}/storage/screenshots", 0775, true);
        file_put_contents("{$theme}/storage/screenshots/.gitignore", "*\n");
        Paths::set_base($theme);

        self::assertSame("{$theme}/uploads/gaffer/offers/12", Storage::private_dir('offers/12'), 'only uploads/gaffer/ (doctor errors on the old one)');
        self::assertSame(['offers' => "{$theme}/storage/offers"], Storage::before(), 'tooling\'s folders stay in storage/');
        self::assertSame("{$theme}/storage/screenshots", Storage::theme_dir('screenshots'));

        exec('rm -rf ' . escapeshellarg($theme));
    }

    public function test_refuses_folders_outside_storage(): void
    {
        $refused = [];
        foreach (['', '/', '../uploads', 'offers/../../x'] as $relative) {
            try {
                Storage::private_dir($relative);
            } catch (\InvalidArgumentException) {
                $refused[] = $relative;
            }
        }
        self::assertSame(['', '/', '../uploads', 'offers/../../x'], $refused);
    }

    public function test_tooling_folders_are_never_taken_for_private_ones(): void
    {
        $theme = sys_get_temp_dir() . '/gaffer-tooling-' . bin2hex(random_bytes(4));
        mkdir("{$theme}/storage/offers", 0755, true);
        \Gaffer\Paths::set_base($theme);
        file_put_contents("{$theme}/storage/offers/.gitignore", "# Never commit: private files of this site.\n*\n");
        Storage::theme_dir('updates');
        // A tooling folder a newer Gaffer added, which this one doesn't know by name.
        mkdir("{$theme}/storage/later-tool");
        file_put_contents("{$theme}/storage/later-tool/.gitignore", "# Never commit: Gaffer's tooling, made again on demand.\n*\n");

        self::assertSame(['offers'], array_keys(Storage::before()));
        exec('rm -rf ' . escapeshellarg($theme));
    }
}
