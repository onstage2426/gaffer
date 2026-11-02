<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\ConfigStubs;
use Gaffer\Console\Report;
use Gaffer\Config;
use Gaffer\Paths;
use Gaffer\Types\Post;
use Gaffer\Types\Term;

/**
 * Unknown config keys, classes in types/terms/twig_extensions that don't exist,
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

        if (rtrim((string) ($composer['autoload']['psr-4']['Theme\\'] ?? ''), '/') !== 'app') {
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
