<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\AjaxAction;
use Gaffer\Console\Report;
use Gaffer\Config;
use Gaffer\Paths;
use Throwable;

/**
 * Each ajax/{Name}/{Name}.php defines {ajax_namespace}\{Name}\{Name} extending AjaxAction,
 * with a GET or POST method. Loads the files the same way the dispatcher does.
 */
final class AjaxCheck implements Check
{
    #[\Override]
    public function run(Report $report): void
    {
        $dir = Paths::ajax();
        $namespace = (string) (Config::get('theme.ajax_namespace') ?? 'Theme\\Ajax');
        $files = glob("{$dir}/*/*.php") ?: [];

        // Support files (traits, helpers) first, like Ajax::handle().
        usort($files, static fn(string $a, string $b): int => self::is_action($a) <=> self::is_action($b));

        foreach ($files as $file) {
            try {
                require_once $file;
            } catch (Throwable $e) {
                $report->error('ajax', 'Fails to load: ' . $e->getMessage(), $file, $e->getLine());
                continue;
            }

            if (!self::is_action($file)) {
                continue;
            }

            $action = basename($file, '.php');
            $class = "{$namespace}\\{$action}\\{$action}";

            if (!is_subclass_of($class, AjaxAction::class)) {
                $report->error('ajax', "{$class} is not defined here or doesn't extend " . AjaxAction::class, $file);
                continue;
            }

            $method = new $class()->method;
            if (!in_array($method, ['GET', 'POST'], true)) {
                $report->error('ajax', "\$method is \"{$method}\"; the dispatcher only accepts GET or POST", $file);
            }
        }
    }

    private static function is_action(string $file): bool
    {
        return basename($file, '.php') === basename(dirname($file));
    }
}
