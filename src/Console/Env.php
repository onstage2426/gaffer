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

    /**
     * Sets a key in .env, keeping its other lines. A missing .env starts as a copy
     * of .env.example.
     */
    public static function set(string $key, string $value): void
    {
        $file = Paths::base('.env');
        $source = is_file($file) ? $file : Paths::base('.env.example');
        $lines = is_file($source) ? file($source, FILE_IGNORE_NEW_LINES) ?: [] : [];

        $pattern = '/^\s*' . preg_quote($key, '/') . '\s*=/';
        $found = false;
        foreach ($lines as $i => $line) {
            if (preg_match($pattern, $line)) {
                $lines[$i] = "{$key}={$value}";
                $found = true;
            }
        }
        if (!$found) {
            $lines[] = "{$key}={$value}";
        }

        if (file_put_contents($file, implode("\n", $lines) . "\n") === false) {
            throw new \RuntimeException("Could not write {$file}.");
        }
        self::$values = null;
    }
}
