<?php

declare(strict_types=1);

namespace Gaffer\Ai;

use Gaffer\Ajax;
use Gaffer\AjaxAction;
use Gaffer\AjaxArguments;
use Gaffer\BlockFields;
use Gaffer\Config;
use Gaffer\Console\Checks\BlocksCheck;
use Gaffer\Console\ConfigStubs;
use Gaffer\Console\Templates;
use Gaffer\Console\ThemeFiles;
use Gaffer\Paths;
use Gaffer\Twig\Extension;
use Gaffer\View;
use Closure;
use PhpToken;
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
            self::views(),
            self::hooks(),
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
     * Each template in views/ with the variables it reads from its caller, so a
     * partial can be called without opening it.
     */
    private static function views(): string
    {
        $shared = View::shared_keys();
        $ignore = [...$shared, ...array_keys(View::env()->getGlobals())];
        $lines = ["### Views\n"];
        if ($shared !== []) {
            $lines[] = 'Shared with every template (`View::share()`): `' . implode('`, `', $shared) . '`.';
            $lines[] = '';
        }

        foreach (Templates::all() as $name => $file) {
            $module = str_starts_with($name, '@') ? null : Templates::parse($name);
            if ($module === null) {
                continue; // block and ajax templates are covered by their own sections
            }
            $variables = array_map(
                static fn(string $variable, bool $optional): string => "`{$variable}`" . ($optional ? ' (optional)' : ''),
                array_keys(Templates::variables($module, $ignore)),
                Templates::variables($module, $ignore),
            );
            $lines[] = "- `{$name}`" . ($variables !== [] ? ': ' . implode(', ', $variables) : ': no variables');
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    /**
     * Each inc/ file's hooks and shortcodes, grouped under the comment above them.
     */
    private static function hooks(): string
    {
        $lines = ["### Hooks (`inc/`)\n"];

        foreach (is_dir(Paths::includes()) ? ThemeFiles::find(['php'], Paths::includes()) : [] as $file) {
            $groups = self::file_hooks((string) file_get_contents($file));
            if ($groups === []) {
                continue;
            }
            $lines[] = '- `' . substr($file, strlen(Paths::base()) + 1) . '`';
            foreach ($groups as $comment => $hooks) {
                $lines[] = '  - ' . ($comment !== '' ? "{$comment}: " : '') . implode(', ', $hooks);
            }
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    /**
     * Hook registrations by the comment above them: "comment" => ["`hook`", "removes `hook`"].
     *
     * @return array<string, list<string>>
     */
    private static function file_hooks(string $code): array
    {
        $calls = ['add_action' => '', 'add_filter' => '', 'add_shortcode' => 'shortcode ', 'remove_action' => 'removes ', 'remove_filter' => 'removes '];
        $tokens = array_values(array_filter(PhpToken::tokenize($code), static fn(PhpToken $t): bool => !$t->is([T_WHITESPACE, T_OPEN_TAG])));
        $groups = [];
        $comment = '';

        foreach ($tokens as $i => $token) {
            if ($token->is([T_COMMENT, T_DOC_COMMENT])) {
                $comment = trim((string) preg_replace('#^/\*+|\*+/$|^//|^\s*\*\s?#m', '', $token->text));
                $comment = (string) preg_replace('/\s+/', ' ', $comment);
                continue;
            }
            $name = $tokens[$i + 2] ?? null;
            if ($token->is(T_STRING) && isset($calls[$token->text]) && ($tokens[$i + 1] ?? null)?->text === '('
                && $name !== null && $name->is(T_CONSTANT_ENCAPSED_STRING)) {
                $groups[$comment][] = $calls[$token->text] . '`' . trim($name->text, '\'"') . '`';
            }
        }

        return $groups;
    }

    /**
     * ACF fields per block name: blocks/*\/fields.php, plus local JSON field groups
     * that target a block (themes that haven't moved those fields to code).
     *
     * @return array<string, list<string>>
     */
    private static function block_fields(): array
    {
        $groups = [];
        foreach (glob(Paths::blocks() . '/*/fields.php') ?: [] as $file) {
            try {
                $groups[] = BlockFields::group(basename(dirname($file)));
            } catch (\Throwable) {
                continue; // doctor reports it
            }
        }
        foreach (glob(Paths::storage() . '/acf-json/group_*.json') ?: [] as $file) {
            $group = json_decode((string) file_get_contents($file), true);
            if (is_array($group)) {
                $groups[] = $group;
            }
        }

        $fields = [];
        foreach ($groups as $group) {
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
