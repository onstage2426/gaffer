<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\ConfigStubs;
use Gaffer\Console\Report;
use Gaffer\Console\ThemeFiles;
use Gaffer\Config;
use Gaffer\Paths;
use Gaffer\Types\Post;
use Gaffer\Types\Term;

/**
 * Unknown config keys, literal keys the theme reads that config/ doesn't have, classes in
 * types/terms/twig_extensions that don't exist,
 * composer.json autoloading app/ as the Theme\ namespace, and running ai:update after
 * `composer update` in a theme that uses the AI files.
 */
final class ConfigCheck implements Check
{
    #[\Override]
    public function run(Report $report): void
    {
        $this->autoload($report);
        $stubs = ConfigStubs::keys();

        foreach (Config::all() as $file => $values) {
            if (!isset($stubs[$file])) {
                continue; // the theme's own config file
            }
            foreach (array_keys($values) as $key) {
                if (!in_array($key, $stubs[$file], true)) {
                    $hint = ConfigStubs::did_you_mean($key, $stubs[$file]);
                    $report->error('config', "Unknown key {$file}.{$key} (Gaffer ignores it)", Paths::base("config/{$file}.php"), null,
                        $hint !== null ? "Did you mean {$file}.{$hint}?" : null);
                }
            }
        }

        $maps = ['theme.types' => Post::class, 'theme.terms' => Term::class];
        foreach ($maps as $key => $base) {
            foreach ((array) (Config::get($key) ?? []) as $type => $class) {
                if (!class_exists($class)) {
                    $report->error('config', "{$key}.{$type}: class {$class} doesn't exist", Paths::base('config/theme.php'));
                } elseif (!is_a($class, $base, true)) {
                    $report->error('config', "{$key}.{$type}: {$class} doesn't extend {$base}", Paths::base('config/theme.php'));
                }
            }
        }

        foreach ((array) (Config::get('theme.twig_extensions') ?? []) as $class) {
            if (!class_exists($class)) {
                $report->error('config', "theme.twig_extensions: class {$class} doesn't exist", Paths::base('config/theme.php'));
            }
        }

        $this->reads($report, $stubs);
    }

    /**
     * Literal keys the theme reads (`Config::get('a.b')`, Twig's `config('a.b')`) that config/
     * doesn't have: a typo reads null without a word.
     *
     * @param array<string, list<string>> $stubs
     */
    private function reads(Report $report, array $stubs): void
    {
        foreach (ThemeFiles::find(['php', 'twig']) as $file) {
            if (str_starts_with($file, Paths::base('config') . '/')) {
                continue;
            }
            $code = (string) file_get_contents($file);
            preg_match_all(self::READ, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $m) {
                $key = $m[2][0];
                $missing = self::missing($key, Config::all(), $stubs);
                if ($missing === null) {
                    continue;
                }
                [$where, $known] = $missing;
                $hint = ConfigStubs::did_you_mean($key, $known);
                $report->error('config', "Reads the config key {$key}, which {$where} doesn't have (it reads null)", $file, substr_count($code, "\n", 0, $m[0][1]) + 1,
                    $hint !== null ? "Did you mean {$hint}?" : 'Add it to the config file (null when it may be unset), or fix the name.');
            }
        }
    }

    /** `Config::get('a.b')` in PHP, `config('a.b')` in Twig: literal keys only. */
    private const string READ = '/(?:\bConfig::get|(?<![\w>:$.])config)\(\s*([\'"])([A-Za-z0-9_.-]+)\1\s*[,)]/';

    /**
     * Null when the dotted key is in the config (a key set to null counts), or a Gaffer config
     * key the theme leaves at its default; else where it's missing and the keys known there.
     *
     * @param array<string, array<string, mixed>> $config
     * @param array<string, list<string>> $stubs Gaffer's config files and their keys
     * @return array{string, list<string>}|null
     */
    public static function missing(string $key, array $config, array $stubs): ?array
    {
        $parts = explode('.', $key);
        $file = array_shift($parts);
        if (!isset($config[$file])) {
            if (isset($stubs[$file]) && ($parts === [] || in_array($parts[0], $stubs[$file], true))) {
                return null;
            }
            $files = array_values(array_unique([...array_keys($config), ...array_keys($stubs)]));
            return ["config/{$file}.php", array_map('strval', $files)];
        }
        $value = $config[$file];
        $path = $file;
        foreach ($parts as $i => $part) {
            if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
                $path .= ".{$part}";
                continue;
            }
            if ($i === 0 && isset($stubs[$file]) && in_array($part, $stubs[$file], true)) {
                return null; // Gaffer's default
            }
            if (!is_array($value)) {
                return null; // deeper than the config goes: a value read as a list or map elsewhere
            }
            $known = array_map(static fn(int|string $k): string => "{$path}.{$k}", array_keys($value));
            if ($i === 0 && isset($stubs[$file])) {
                $known = array_values(array_unique([...$known, ...array_map(static fn(string $k): string => "{$file}.{$k}", $stubs[$file])]));
            }
            return [$path === $file ? "config/{$file}.php" : "config/{$file}.php's {$path}", $known];
        }

        return null;
    }

    /** A composer.json PSR-4 entry for Theme\ that maps app/: "app/" or a list of directories with it (Composer allows both). */
    public static function autoloads_app(mixed $paths): bool
    {
        return array_any(
            is_array($paths) ? $paths : [$paths],
            static fn(mixed $path): bool => is_string($path) && in_array(rtrim($path, '/'), ['app', './app'], true),
        );
    }

    /**
     * app/ is one namespace (Theme\Types, Theme\Twig, Theme\Fields, ...), so composer.json maps it as a whole.
     */
    private function autoload(Report $report): void
    {
        $file = Paths::base('composer.json');
        $composer = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($composer)) {
            return;
        }

        if (!self::autoloads_app($composer['autoload']['psr-4']['Theme\\'] ?? null)) {
            $report->error('config', 'composer.json doesn\'t autoload "Theme\\\\": "app/"', $file, null,
                'Replace the Theme\\Twig\\ / Theme\\Types\\ entries with "Theme\\\\": "app/" and run `composer dump-autoload`.');
        }

        // The guidelines tell agents that an update brings the new version's guidelines along.
        $scripts = implode(' ', array_filter((array) ($composer['scripts']['post-update-cmd'] ?? []), is_string(...)));
        if (is_file(Paths::base('config/ai.php')) && !str_contains($scripts, 'gaffer ai:update')) {
            $report->warning('config', 'composer.json doesn\'t run ai:update after `composer update`: the AI guidelines stay the old version\'s', $file, null,
                'Add "scripts": { "post-update-cmd": ["@php gaffer ai:update --ansi"] }, and run `php gaffer ai:update` now.');
        }
    }
}
