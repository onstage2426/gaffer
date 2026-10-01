<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\ConfigStubs;
use Gaffer\Console\Report;
use Gaffer\Facades\Config;
use Gaffer\Facades\Paths;
use Gaffer\Types\Post;
use Gaffer\Types\Term;

/**
 * Unknown config keys, and classes in types/terms/twig_extensions that don't exist.
 */
final class ConfigCheck implements Check
{
    #[\Override]
    public function run(Report $report): void
    {
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
}
