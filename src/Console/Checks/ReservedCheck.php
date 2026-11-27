<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\Report;
use Gaffer\Console\ThemeFiles;

/**
 * Names in templates and scripts that are Gaffer's (API.md, "Reserved names"), so Gaffer can add
 * one in a minor release without colliding with a theme's: `data-gaffer-*` attributes Gaffer
 * doesn't define, form inputs named `gaffer_*`, `gaffer:*` and `gaffer-…:*` events a theme dispatches (listening
 * to them is what they're for) and `window.gaffer*` globals a theme sets.
 *
 * @internal
 */
final class ReservedCheck implements Check
{
    private const string HINT = 'Names starting with gaffer are reserved for Gaffer (API.md, "Reserved names"): give the theme\'s own another name.';

    #[\Override]
    public function run(Report $report): void
    {
        $known = self::gaffer_attributes();
        foreach (ThemeFiles::find(['twig', 'php', 'js', 'mjs', 'ts']) as $file) {
            foreach (self::findings((string) file_get_contents($file), $known, pathinfo($file, PATHINFO_EXTENSION)) as [$message, $line]) {
                $report->error('reserved', $message, $file, $line, self::HINT);
            }
        }
    }

    /**
     * @param list<string> $known the data-gaffer-* attributes Gaffer defines
     * @return list<array{string, int}> message and line
     */
    public static function findings(string $code, array $known, string $extension): array
    {
        $found = [];
        $line = static fn(int $offset): int => substr_count($code, "\n", 0, $offset) + 1;

        preg_match_all('/data-gaffer-[a-z0-9-]+/', $code, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [$attribute, $offset]) {
            if (!in_array($attribute, $known, true)) {
                $found[] = ["{$attribute} isn't one of Gaffer's attributes", $line($offset)];
            }
        }
        if ($extension === 'twig' || $extension === 'php') {
            preg_match_all('/\bname=["\'](gaffer_[A-Za-z0-9_]*)/', $code, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[1] as [$name, $offset]) {
                $found[] = ["The input name {$name} is a request key reserved for Gaffer", $line($offset)];
            }
        }
        // Dispatching Gaffer's events (listening is fine): new CustomEvent('gaffer:…'), $dispatch('gaffer:…').
        preg_match_all('/(?:new\s+CustomEvent|\$dispatch)\(\s*[\'"`](gaffer(?:-[a-z]+)?:[\w:-]*)/', $code, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[1] as [$event, $offset]) {
            $found[] = ["Dispatches {$event}, an event name reserved for Gaffer", $line($offset)];
        }
        if ($extension !== 'php') {
            preg_match_all('/\bwindow\.(gaffer\w*)\s*=(?!=)/', $code, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[1] as [$global, $offset]) {
                $found[] = ["Sets window.{$global}, a global reserved for Gaffer", $line($offset)];
            }
        }

        return $found;
    }

    /**
     * The data-gaffer-* attributes Gaffer writes or reads: whatever its own code names, so a new
     * one counts as soon as Gaffer uses it.
     *
     * @return list<string>
     */
    public static function gaffer_attributes(): array
    {
        $root = dirname(__DIR__, 3);
        $names = [];
        foreach (['src', 'resources/js', 'resources/views', 'resources/editor'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$dir}", \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (in_array($file->getExtension(), ['php', 'js', 'twig'], true)) {
                    preg_match_all('/data-gaffer-[a-z0-9-]+/', (string) file_get_contents($file->getPathname()), $m);
                    array_push($names, ...$m[0]);
                }
            }
        }

        return array_values(array_unique($names));
    }
}
