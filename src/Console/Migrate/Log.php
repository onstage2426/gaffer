<?php

declare(strict_types=1);

namespace Gaffer\Console\Migrate;

use Gaffer\Gaffer;
use Gaffer\Paths;
use Gaffer\Storage;

/**
 * storage/logs/migrate.log: one JSON line per migrate:* --run, with its outcome,
 * so people and agents can trace what happened to the content (the backups hold
 * the content itself, and may be deleted; the log stays).
 */
final class Log
{
    public static function file(): string
    {
        return Paths::storage() . '/logs/migrate.log';
    }

    /**
     * Appends an entry: time, who, which code and site are added here.
     *
     * @param array<string, mixed> $entry command, outcome, reason, backup, undoes, locations
     */
    public static function write(array $entry): bool
    {
        $line = json_encode([
            'time' => gmdate('c'),
            ...$entry,
            'user' => self::user(),
            'theme_commit' => self::commit(),
            'gaffer' => Gaffer::version(),
            'site' => home_url(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        Storage::private_dir('logs');

        return is_string($line) && file_put_contents(self::file(), $line . "\n", FILE_APPEND | LOCK_EX) !== false;
    }

    /**
     * Every entry, oldest first (lines that don't parse are skipped).
     *
     * @return list<array<string, mixed>>
     */
    public static function entries(): array
    {
        $entries = [];
        foreach (is_file(self::file()) ? file(self::file(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] : [] as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private static function user(): string
    {
        $user = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null;

        return is_string($user) && $user !== '' ? $user : (getenv('USER') ?: get_current_user());
    }

    /**
     * The theme's git commit, "+changes" when the working tree differs from it; null without git.
     */
    private static function commit(): ?string
    {
        $dir = escapeshellarg(Paths::base());
        $commit = trim((string) shell_exec("git -C {$dir} rev-parse --short HEAD 2>/dev/null"));
        if ($commit === '') {
            return null;
        }

        return $commit . (trim((string) shell_exec("git -C {$dir} status --porcelain 2>/dev/null")) !== '' ? '+changes' : '');
    }
}
