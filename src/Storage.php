<?php

declare(strict_types=1);

namespace Gaffer;

use InvalidArgumentException;
use RuntimeException;

/**
 * Folders in storage/ for files that are neither public nor committed (logs,
 * backups, documents visitors upload, files the site generates): each gets a
 * .htaccess that denies web access (Apache; other servers need their own rule)
 * and a .gitignore that ignores everything in it. Serve such a file through
 * code that checks who may see it (an ajax action, an admin screen).
 */
final class Storage
{
    /**
     * The folder storage/{relative}, created with its deny-all .htaccess and
     * .gitignore when missing; its path.
     */
    public static function private_dir(string $relative): string
    {
        $relative = trim($relative, '/');
        if ($relative === '' || in_array('..', explode('/', $relative), true)) {
            throw new InvalidArgumentException("Not a folder inside storage/: \"{$relative}\"");
        }
        $dir = Paths::storage() . '/' . $relative;

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}");
        }
        foreach (['.htaccess' => "Require all denied\n", '.gitignore' => "# Never commit: private files of this site.\n*\n"] as $file => $content) {
            if (!is_file("{$dir}/{$file}")) {
                file_put_contents("{$dir}/{$file}", $content);
            }
        }

        return $dir;
    }
}
