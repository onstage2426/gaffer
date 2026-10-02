<?php

declare(strict_types=1);

namespace Gaffer\Ai;

use Gaffer\Ajax;
use Gaffer\AjaxAction;
use Gaffer\AjaxArguments;
use Gaffer\Config;
use Gaffer\Console\Checks\BlocksCheck;
use Gaffer\Console\ConfigStubs;
use Gaffer\Paths;
use Gaffer\Twig\Extension;
use Gaffer\View;
use Closure;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use Throwable;
use Twig\TwigFilter;

/**
 * The generated part of the guidelines: what this theme actually has, read
 * from its code and config, so it can't drift from the code.
 */
final class Reference
{
    public static function build(): string
    {
        return implode("\n\n", array_filter([
            "## Reference (generated from this theme's code)",
            self::config(),
            self::twig(),
            self::types(),
            self::ajax(),
            self::blocks(),
        ])) . "\n";
    }

    private static function config(): string
    {
        $descriptions = ConfigStubs::descriptions();
        $lines = ["### Config\n"];

        foreach (Config::all() as $file => $values) {
            $lines[] = "- `config/{$file}.php`" . (isset($descriptions[$file]) ? '' : ' (theme-specific)');
            foreach (array_keys($values) as $key) {
                $about = $descriptions[$file][$key] ?? null;
                $lines[] = "  - `{$file}.{$key}`" . ($about !== null ? " — {$about}" : '');
            }
        }

        return implode("\n", $lines);
    }

    private static function twig(): string
    {
        $env = View::env();
        $ours = [Extension::class, ...(Config::get('theme.twig_extensions') ?? [])];
        $lines = ["### Twig functions and filters\n", 'Besides Twig\'s built-ins (and `dump()` in debug mode):', ''];

        foreach ([...$env->getFunctions(), ...$env->getFilters()] as $name => $callable) {
            $reflection = self::reflect($callable->getCallable());
            if ($reflection === null || !in_array(self::owner($reflection), $ours, true)) {
                continue;
            }
            $kind = $callable instanceof TwigFilter ? 'filter' : 'function';
            $lines[] = "- {$kind} `" . Signature::of($reflection, (string) $name) . '` (' . self::short(self::owner($reflection)) . ')' . self::summary($reflection);
        }

        return implode("\n", $lines);
    }

    private static function types(): string
    {
        $maps = ['post type' => Config::get('theme.types') ?? [], 'taxonomy' => Config::get('theme.terms') ?? []];
        $lines = ["### Theme types\n"];

        foreach ($maps as $kind => $map) {
            foreach ((array) $map as $object => $class) {
                if (!class_exists($class)) {
                    continue;
                }
                $methods = array_filter(
                    new ReflectionClass($class)->getMethods(ReflectionMethod::IS_PUBLIC),
                    static fn(ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === $class && !$m->isConstructor(),
                );
                $lines[] = "- `{$class}` ({$kind} `{$object}`, extends `" . self::short((string) get_parent_class($class)) . '`):';
                foreach ($methods as $method) {
                    $lines[] = '  - `' . Signature::of($method) . '`' . self::summary($method);
                }
            }
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    private static function ajax(): string
    {
        $dir = Paths::ajax();
        $files = glob("{$dir}/*/*.php") ?: [];
        $is_action = static fn(string $f): bool => basename($f, '.php') === basename(dirname($f));
        usort($files, static fn(string $a, string $b): int => $is_action($a) <=> $is_action($b));

        $lines = ["### Ajax actions\n"];
        foreach ($files as $file) {
            try {
                require_once $file;
            } catch (Throwable) {
                continue;
            }
            if (!$is_action($file)) {
                continue;
            }

            $name = basename($file, '.php');
            $class = Ajax::NAMESPACE . "\\{$name}\\{$name}";
            if (!is_subclass_of($class, AjaxAction::class) || !method_exists($class, 'run')) {
                continue;
            }

            $run = AjaxArguments::run_method(new $class());
            $lines[] = "- `{$name}`: " . $class::METHOD . ($class::SHORTINIT ? ', SHORTINIT' : '') . ', `' . Signature::of($run) . '`';
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    private static function blocks(): string
    {
        $fields = self::block_fields();
        $lines = ["### Blocks\n"];

        foreach (BlocksCheck::names() as $dir => $name) {
            $meta = json_decode((string) file_get_contents(Paths::blocks() . "/{$dir}/block.json"), true) ?: [];
            $lines[] = "- `{$name}` (`blocks/{$dir}/`): " . ($meta['title'] ?? $dir) . ' — ' . ($meta['description'] ?? '');
            if (isset($fields[$name])) {
                $lines[] = '  - fields: ' . implode(', ', $fields[$name]);
            }
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    /**
     * ACF fields per block name, from the local JSON field groups.
     *
     * @return array<string, list<string>>
     */
    private static function block_fields(): array
    {
        $fields = [];

        foreach (glob(Paths::storage() . '/acf-json/group_*.json') ?: [] as $file) {
            $group = json_decode((string) file_get_contents($file), true);
            if (!is_array($group)) {
                continue;
            }
            foreach ($group['location'] ?? [] as $or) {
                foreach ($or as $rule) {
                    if (($rule['param'] ?? null) === 'block' && ($rule['operator'] ?? '==') === '==') {
                        foreach ($group['fields'] ?? [] as $field) {
                            if (($field['name'] ?? '') !== '') { // tabs and messages are layout, not data
                                $fields[$rule['value']][] = self::field($field);
                            }
                        }
                    }
                }
            }
        }

        return $fields;
    }

    /** @param array<string, mixed> $field */
    private static function field(array $field): string
    {
        $text = "`{$field['name']}` ({$field['type']}";
        if (!empty($field['return_format'])) {
            $text .= ", returns {$field['return_format']}";
        }
        if (!empty($field['sub_fields'])) {
            $text .= ': ' . implode(', ', array_map(static fn(array $f): string => "`{$f['name']}`", $field['sub_fields']));
        }

        return "{$text})";
    }

    private static function reflect(mixed $callable): ?ReflectionFunctionAbstract
    {
        return match (true) {
            is_array($callable) && count($callable) === 2 => new ReflectionMethod($callable[0], $callable[1]),
            is_string($callable) && str_contains($callable, '::') => new ReflectionMethod($callable),
            $callable instanceof Closure, is_string($callable) => new ReflectionFunction($callable),
            default => null,
        };
    }

    /**
     * " — " plus the docblock's first paragraph, if there is one.
     */
    private static function summary(ReflectionFunctionAbstract $function): string
    {
        $words = [];
        foreach (explode("\n", (string) $function->getDocComment()) as $line) {
            $line = trim($line, " \t/*");
            if (str_starts_with($line, '@') || ($line === '' && $words !== [])) {
                break;
            }
            if ($line !== '') {
                $words[] = $line;
            }
        }

        return $words !== [] ? ' — ' . implode(' ', $words) : '';
    }

    private static function owner(ReflectionFunctionAbstract $function): string
    {
        return $function instanceof ReflectionMethod ? $function->getDeclaringClass()->getName() : '';
    }

    private static function short(string $class): string
    {
        return substr(strrchr('\\' . $class, '\\') ?: '', 1);
    }
}
