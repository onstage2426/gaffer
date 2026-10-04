<?php

declare(strict_types=1);

namespace Gaffer;

use RuntimeException;

/**
 * Folders in storage/ for files that are neither public nor committed (logs,
 * backups): each gets a .htaccess that denies web access (Apache; other servers
 * need their own rule) and a .gitignore that ignores everything in it.
 *
 * @internal
 */
final class Storage
{
    public static function private_dir(string $relative): string
    {
        $dir = Paths::storage() . '/' . trim($relative, '/');

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}");
        }
        foreach (['.htaccess' => "Require all denied\n", '.gitignore' => "# Never commit: logs and backups of site content.\n*\n"] as $file => $content) {
            if (!is_file("{$dir}/{$file}")) {
                file_put_contents("{$dir}/{$file}", $content);
            }
        }

        return $dir;
    }
}
