<?php

declare(strict_types=1);

namespace Gaffer\Console;

/**
 * The config keys Gaffer knows, read from its own config/*.php stubs.
 */
final class ConfigStubs
{
    /** @var array<string, list<string>>|null */
    private static ?array $keys = null;

    /**
     * Top-level keys per config file. Files Gaffer has no stub for (e.g. a
     * theme's own customer.php) aren't listed.
     *
     * @return array<string, list<string>>
     */
    public static function keys(): array
    {
        if (self::$keys !== null) {
            return self::$keys;
        }

        self::$keys = [];
        foreach (glob(dirname(__DIR__, 2) . '/config/*.php') ?: [] as $file) {
            preg_match_all("/^    (?:\/\/ )?'(\w+)'\s*=>/m", (string) file_get_contents($file), $m);
            self::$keys[basename($file, '.php')] = $m[1];
        }

        return self::$keys;
    }

    /**
     * The title and first sentence of each stub key's comment block.
     *
     * @return array<string, array<string, string>> file => key => "Title: first sentence."
     */
    public static function descriptions(): array
    {
        $descriptions = [];

        foreach (glob(dirname(__DIR__, 2) . '/config/*.php') ?: [] as $file) {
            preg_match_all(
                "/\\|-+\\n\\s*\\|\\s*([^\\n]+?)\\s*\\n\\s*\\|-+(.*?)\\*\\/\\s*(?:\\/\\/ )?'(\\w+)'\\s*=>/s",
                (string) file_get_contents($file),
                $blocks,
                PREG_SET_ORDER,
            );

            foreach ($blocks as [, $title, $body, $key]) {
                $text = trim((string) preg_replace('/\\s+/', ' ', (string) preg_replace('/^\\s*\\|\\s?/m', '', $body)));
                $sentence = preg_match('/^.+?\\.(?=\\s|$)/', $text, $m) ? $m[0] : $text;
                $descriptions[basename($file, '.php')][$key] = $sentence !== '' ? "{$title}: {$sentence}" : $title;
            }
        }

        return $descriptions;
    }

    /** @param list<string> $known */
    public static function did_you_mean(string $name, array $known): ?string
    {
        $best = null;
        $distance = 4;

        foreach ($known as $candidate) {
            // "extensions" → "twig_extensions": a substring counts as a close match
            $d = str_contains($candidate, $name) || str_contains($name, $candidate)
                ? 1
                : levenshtein($name, $candidate);
            if ($d < $distance) {
                [$best, $distance] = [$candidate, $d];
            }
        }

        return $best;
    }
}
