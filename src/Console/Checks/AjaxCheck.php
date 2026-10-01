<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\AjaxAction;
use Gaffer\AjaxArguments;
use Gaffer\Console\Report;
use Gaffer\Config;
use Gaffer\Paths;
use Throwable;

/**
 * Each ajax/{Name}/{Name}.php defines {ajax_namespace}\{Name}\{Name} extending AjaxAction,
 * with a GET or POST method and a run() Gaffer can fill. Loads the files the same
 * way the dispatcher does.
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

            $instance = new $class();
            if (!in_array($instance->method, ['GET', 'POST'], true)) {
                $report->error('ajax', "\$method is \"{$instance->method}\"; the dispatcher only accepts GET or POST", $file);
            }

            $problem = method_exists($instance, 'run')
                ? AjaxArguments::problem(AjaxArguments::run_method($instance))
                : "{$class} has no run() method";
            if ($problem !== null) {
                $report->error('ajax', $problem, $file, null,
                    'run() parameters are the request keys: typed string, int, float, bool or array; no default = required.');
            }
        }
    }

    private static function is_action(string $file): bool
    {
        return basename($file, '.php') === basename(dirname($file));
    }
}
