<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Ajax;
use Gaffer\AjaxAction;
use Gaffer\AjaxArguments;
use Gaffer\Console\Report;
use Gaffer\Paths;
use Throwable;

/**
 * Each ajax/{Name}/{Name}.php defines Theme\Ajax\{Name}\{Name} extending AjaxAction,
 * with a GET or POST METHOD and a run() Gaffer can fill, and warns about SHORTINIT
 * actions using things SHORTINIT doesn't load. Loads the files the same way the
 * dispatcher does.
 */
final class AjaxCheck implements Check
{
    #[\Override]
    public function run(Report $report): void
    {
        $dir = Paths::ajax();
        $namespace = Ajax::NAMESPACE;
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

            if (!in_array($class::METHOD, ['GET', 'POST'], true)) {
                $report->error('ajax', 'METHOD is "' . $class::METHOD . '"; the dispatcher only accepts GET or POST', $file);
            }

            // Pre-constants actions: these properties are ignored now.
            foreach (['method' => 'METHOD', 'shortinit' => 'SHORTINIT'] as $property => $constant) {
                if (property_exists($class, $property)) {
                    $report->error('ajax', "\${$property} is ignored; declare `public const {$constant} = ...` instead", $file);
                }
            }

            $instance = new $class();
            $problem = method_exists($instance, 'run')
                ? AjaxArguments::problem(AjaxArguments::run_method($instance))
                : "{$class} has no run() method";
            if ($problem !== null) {
                $report->error('ajax', $problem, $file, null,
                    'run() parameters are the request keys: typed string, int, float, bool, array or a Gaffer type (from an ID); no default = required.');
            }

            if (method_exists($instance, 'run')) {
                foreach (self::json_strings(AjaxArguments::run_method($instance), (string) file_get_contents($file)) as $name) {
                    $report->warning('ajax', "run() decodes JSON from the string parameter \${$name}", $file, null,
                        "Send it as form fields ({$name}[key]=value) and declare `array \${$name}`, or use a type parameter for an ID.");
                }
            }

            if ($class::SHORTINIT) {
                self::shortinit($report, $file);
            }
        }
    }

    /**
     * String parameters of run() that the action json_decode()s.
     *
     * @return list<string>
     */
    public static function json_strings(\ReflectionMethod $run, string $code): array
    {
        $names = [];
        foreach ($run->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === 'string'
                && preg_match('/\bjson_(?:decode|validate)\(\s*\$' . preg_quote($parameter->getName(), '/') . '\b/', $code)) {
                $names[] = $parameter->getName();
            }
        }

        return $names;
    }

    /**
     * SHORTINIT loads no plugins, no theme and no pluggable functions. Flag code
     * in the action that needs them (pattern-based, so warnings).
     */
    private static function shortinit(Report $report, string $file): void
    {
        $unavailable = [
            '/Gaffer\\\\Types\\\\|\b(?:Post|Term|Image|Attachment|Menu|Pagination)::/' => 'Gaffer types (no WordPress query layer)',
            '/\bWC\(|\bwc_[a-z_]+\(/' => 'WooCommerce (plugins are not loaded)',
            '/\bget_field\(|\bAcf::/' => 'ACF (plugins are not loaded)',
            '/\b(?:current_user_can|wp_verify_nonce|is_user_logged_in|wp_get_current_user)\(/' => 'users and nonces (pluggable functions are not loaded)',
            '/\bajax_url\(|\bget_template_directory/' => 'theme functions (the theme is not loaded)',
        ];

        foreach (file($file) ?: [] as $i => $line) {
            foreach ($unavailable as $pattern => $what) {
                if (preg_match($pattern, $line)) {
                    $report->warning('ajax', "SHORTINIT action uses {$what}", $file, $i + 1,
                        'SHORTINIT has $wpdb, get_option(), config and Twig only. Drop SHORTINIT or read the data with $wpdb.');
                }
            }
        }
    }

    private static function is_action(string $file): bool
    {
        return basename($file, '.php') === basename(dirname($file));
    }
}
