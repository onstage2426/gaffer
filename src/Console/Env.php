<?php

declare(strict_types=1);

namespace Gaffer\Console;

use Gaffer\Paths;

/**
 * The theme's .env (not committed): settings per checkout, like SITE_URL for
 * the CLI. The same file Vite reads for its dev server.
 */
final class Env
{
    /** @var array<string, string>|null */
    private static ?array $values = null;

    public static function get(string $key): ?string
    {
        if (self::$values === null) {
            self::$values = [];
            $file = Paths::base('.env');
            foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) ?: [] : [] as $line) {
                if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/', $line, $m)) {
                    self::$values[$m[1]] = trim($m[2], '"\'');
                }
            }
        }

        $value = self::$values[$key] ?? null;

        return $value !== null && $value !== '' ? $value : null;
    }
}
