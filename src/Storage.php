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
    /** The theme's storage/ folders tooling writes (theme_dir()): what can be made again, never the site's data. */
    private const array TOOLING = ['screenshots', 'sessions', 'snapshots', 'updates', 'upgrades'];

    private const string MARKER = "# Never commit: private files of this site.\n";

    /** Gaffer's own folder in uploads/gaffer/, and the prefix private_dir() refuses. */
    private const string OWN = 'gaffer';

    /** theme_dir()'s folders say so in their .gitignore, so any Gaffer version tells them from private ones. */
    private const string TOOLING_MARKER = "# Never commit: Gaffer's tooling, made again on demand.\n";

    /**
     * The private folder {relative} in uploads/gaffer/, created with its deny-all .htaccess and
     * .gitignore when missing; its path. Needs WordPress loaded (not SHORTINIT). A first folder
     * whose name starts with `gaffer` is Gaffer's own (its logs, migrate backups): refused.
     *
     * @throws InvalidArgumentException for a path outside uploads/gaffer/ or in Gaffer's folders
     */
    public static function private_dir(string $relative): string
    {
        $relative = self::relative($relative);
        if (str_starts_with(strtolower($relative), self::OWN)) {
            throw new InvalidArgumentException("Storage::private_dir('{$relative}'): folders starting with \"gaffer\" are Gaffer's own; name it otherwise.");
        }
        self::deny(Paths::data());

        return self::deny(Paths::data() . '/' . $relative);
    }

    /**
     * Gaffer's own private folder {relative} (logs, backups/migrate) in uploads/gaffer/gaffer/.
     *
     * @internal
     */
    public static function gaffer_dir(string $relative): string
    {
        $relative = self::relative($relative);
        self::deny(Paths::data());
        self::deny(Paths::data() . '/' . self::OWN);

        return self::deny(Paths::data() . '/' . self::OWN . '/' . $relative);
    }

    /**
     * Gaffer's files where it kept them before 2026-11-27 (uploads/gaffer/logs/spam.log,
     * migrate.log, backups/migrate/*.json): still read (migrate backups), doctor gives the mv.
     *
     * @internal
     * @return list<string> paths
     */
    public static function gaffer_before(): array
    {
        $data = Paths::data();

        return [
            ...array_values(array_filter(["{$data}/logs/spam.log", "{$data}/logs/migrate.log"], 'is_file')),
            ...(glob("{$data}/backups/migrate/*.json") ?: []),
        ];
    }

    /**
     * A private folder in the theme's storage/ for what can be made again (screenshots, ajax
     * sessions, render snapshots): tooling's, not the site's.
     *
     * @internal
     */
    public static function theme_dir(string $relative): string
    {
        $relative = self::relative($relative);
        if (!in_array(explode('/', $relative)[0], self::TOOLING, true)) {
            throw new \LogicException("storage/{$relative}: add its first folder to Storage::TOOLING (or doctor takes it for a private folder left behind).");
        }

        return self::deny(Paths::storage() . '/' . $relative, self::TOOLING_MARKER);
    }

    /**
     * Folders in the theme's storage/ that private_dir() made before 2026-11-23 (no longer used
     * since 2026-11-27; doctor errors until they're moved): name => path. Tooling's folders
     * (theme_dir()) aren't listed.
     *
     * @internal
     * @return array<string, string>
     */
    public static function before(): array
    {
        $found = [];
        foreach (glob(Paths::storage() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            if (in_array($name, ['cache', 'acf-json', ...self::TOOLING], true)) {
                continue;
            }
            // private_dir() put a deny file in each folder it made (storage/backups/migrate: in both);
            // a tooling folder's says it's tooling (one a newer Gaffer added counts too).
            $deny = (string) @file_get_contents("{$dir}/.gitignore");
            if (str_starts_with($deny, self::TOOLING_MARKER)) {
                continue;
            }
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

    private static function deny(string $dir, string $marker = self::MARKER): string
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}");
        }
        foreach (['.htaccess' => "Require all denied\n", '.gitignore' => $marker . "*\n"] as $file => $content) {
            if (!is_file("{$dir}/{$file}")) {
                file_put_contents("{$dir}/{$file}", $content);
            }
        }

        return $dir;
    }
}
