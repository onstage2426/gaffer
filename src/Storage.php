<?php

declare(strict_types=1);

namespace Gaffer;

use InvalidArgumentException;
use RuntimeException;

/**
 * Private folders for the site's own files (logs, migration backups, documents visitors upload,
 * files the site generates): in WordPress's uploads folder, `uploads/gaffer/` (per site on a
 * multisite: `uploads/sites/{id}/gaffer/`), so they survive a deploy that replaces the theme,
 * stay apart per network site and are in the host's backups of uploads. Each gets a .htaccess
 * that denies web access (Apache; other servers need their own rule: `doctor --wp` fetches a probe
 * file) and a .gitignore that ignores everything in it. Serve such a file through code that checks
 * who may see it (an ajax action, an admin screen).
 */
final class Storage
{
    private const string MARKER = "# Never commit: private files of this site.\n";

    /**
     * The private folder {relative} in uploads/gaffer/, created with its deny-all .htaccess and
     * .gitignore when missing; its path. Needs WordPress loaded (not SHORTINIT). Until a site moves
     * them (`doctor --wp` says how), folders made in the theme's storage/ before 2026-11-23 are
     * still used where they are.
     */
    public static function private_dir(string $relative): string
    {
        $relative = self::relative($relative);
        $before = Paths::storage() . '/' . $relative;
        $dir = Paths::data() . '/' . $relative;
        if (!is_dir($dir) && is_dir($before)) {
            return $before;
        }
        self::deny(Paths::data());

        return self::deny($dir);
    }

    /**
     * A private folder in the theme's storage/ for what can be made again (screenshots, ajax
     * sessions, render snapshots): tooling's, not the site's.
     *
     * @internal
     */
    public static function theme_dir(string $relative): string
    {
        return self::deny(Paths::storage() . '/' . self::relative($relative));
    }

    /**
     * Folders in the theme's storage/ that private_dir() made before 2026-11-23 and still uses:
     * name => path. Tooling's folders (theme_dir()) aren't listed.
     *
     * @internal
     * @return array<string, string>
     */
    public static function before(): array
    {
        $found = [];
        foreach (glob(Paths::storage() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            if (in_array($name, ['cache', 'acf-json', 'screenshots', 'sessions', 'snapshots'], true)) {
                continue;
            }
            // private_dir() put a deny file in each folder it made (storage/backups/migrate: in both).
            if (is_file("{$dir}/.gitignore") || glob("{$dir}/*/.gitignore") !== []) {
                $found[$name] = $dir;
            }
        }

        return $found;
    }

    private static function relative(string $relative): string
    {
        $relative = trim($relative, '/');
        if ($relative === '' || in_array('..', explode('/', $relative), true)) {
            throw new InvalidArgumentException("Not a folder inside Gaffer's private folders: \"{$relative}\"");
        }

        return $relative;
    }

    private static function deny(string $dir): string
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}");
        }
        foreach (['.htaccess' => "Require all denied\n", '.gitignore' => self::MARKER . "*\n"] as $file => $content) {
            if (!is_file("{$dir}/{$file}")) {
                file_put_contents("{$dir}/{$file}", $content);
            }
        }

        return $dir;
    }
}
