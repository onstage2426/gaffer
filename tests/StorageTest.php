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

    public function test_a_folder_from_before_stays_in_storage_until_it_is_moved(): void
    {
        $theme = sys_get_temp_dir() . '/gaffer-storage-' . bin2hex(random_bytes(4));
        mkdir("{$theme}/storage/offers/12", 0775, true);
        file_put_contents("{$theme}/storage/offers/.gitignore", "*\n");
        mkdir("{$theme}/storage/screenshots", 0775, true);
        file_put_contents("{$theme}/storage/screenshots/.gitignore", "*\n");
        Paths::set_base($theme);

        self::assertSame("{$theme}/storage/offers/12", Storage::private_dir('offers/12'), 'the visitor\'s files are where they were');
        self::assertSame("{$theme}/uploads/gaffer/logs", Storage::private_dir('logs'), 'a new folder is in uploads/gaffer/');
        self::assertSame(['offers' => "{$theme}/storage/offers"], Storage::before(), 'tooling\'s folders stay in storage/');
        self::assertSame("{$theme}/storage/screenshots", Storage::theme_dir('screenshots'));

        rename("{$theme}/storage/offers", "{$theme}/uploads/gaffer/offers");
        self::assertSame("{$theme}/uploads/gaffer/offers/12", Storage::private_dir('offers/12'));
        self::assertSame([], Storage::before());

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
