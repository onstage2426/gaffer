<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Gaffer\Console\PublicApi;
use Gaffer\Console\Report;
use Gaffer\Console\Templates;
use Gaffer\Console\ThemeFiles;
use PhpToken;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Node;

/**
 * Theme code using Gaffer API marked #[\Deprecated] (Twig functions: Twig's
 * deprecation info), so a site can move on before the next major removes it.
 * Instance methods are matched by name: Gaffer's types are the usual receiver.
 */
final class DeprecationsCheck implements Check
{
    /** Prefix of trigger_deprecation() messages for Gaffer's own Twig functions. */
    private const string TWIG_PACKAGE = 'Since onstage2426/gaffer ';

    private const string HINT = 'Move to the replacement before the next major Gaffer release removes it.';

    #[\Override]
    public function run(Report $report): void
    {
        $deprecations = PublicApi::deprecations(PublicApi::classes());

        if ($deprecations !== []) {
            foreach (ThemeFiles::find(['php']) as $file) {
                foreach (self::php_usages((string) file_get_contents($file), $deprecations) as [$message, $line]) {
                    $report->warning('deprecated', $message, $file, $line, self::HINT);
                }
            }
        }

        foreach (Templates::all() as $name => $file) {
            [$module, $twig] = self::parse_collecting_deprecations($name);
            foreach ($twig as [$message, $line]) {
                $report->warning('deprecated', $message, $file, $line, self::HINT);
            }
            foreach ($module !== null ? self::twig_usages($module, $deprecations) : [] as [$message, $line]) {
                $report->warning('deprecated', $message, $file, $line, self::HINT);
            }
        }
    }

    /**
     * Uses of deprecated members in PHP: static calls and constants
     * (`Post::old()`, `self::OLD`), instance calls (`->old()`), and a class
     * declaring a deprecated method or constant (an override).
     *
     * @param list<array{class: class-string, name: string, kind: 'method'|'constant', message: string}> $deprecations
     * @return list<array{string, int}> message and line
     */
    public static function php_usages(string $code, array $deprecations): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($code), static fn(PhpToken $t): bool => !$t->isIgnorable()));
        [$namespace, $imports] = self::imports($tokens);
        $extends = array_any($tokens, static fn(PhpToken $t): bool => $t->is(T_EXTENDS));
        $found = [];

        foreach ($tokens as $i => $token) {
            if (!$token->is(T_STRING)) {
                continue;
            }
            $before = $tokens[$i - 1] ?? null;
            $call = ($tokens[$i + 1]->text ?? '') === '(';

            $matches = match (true) {
                $before === null => [],
                $before->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) && $call
                    => self::named($deprecations, $token->text, 'method'),
                $before->is(T_DOUBLE_COLON) => array_filter(
                    self::named($deprecations, $token->text, $call ? 'method' : 'constant'),
                    static fn(array $d): bool => self::receiver_matches($tokens[$i - 2] ?? null, $d['class'], $namespace, $imports),
                ),
                $extends && $before->is(T_FUNCTION) => self::named($deprecations, $token->text, 'method'),
                // const NAME = or, typed, const bool NAME =
                $extends && ($tokens[$i + 1]->text ?? '') === '=' && ($before->is(T_CONST) || ($tokens[$i - 2] ?? null)?->is(T_CONST))
                    => self::named($deprecations, $token->text, 'constant'),
                default => [],
            };
            foreach ($matches as $d) {
                $found[] = [$d['message'], $token->line];
            }
        }

        return $found;
    }

    /**
     * Calls of deprecated methods in a template (`post.old()`, `post.old`).
     *
     * @param list<array{class: class-string, name: string, kind: 'method'|'constant', message: string}> $deprecations
     * @return list<array{string, int}> message and line
     */
    public static function twig_usages(Node $node, array $deprecations): array
    {
        $found = [];
        if ($node instanceof GetAttrExpression) {
            $attribute = $node->getNode('attribute');
            $name = $attribute instanceof ConstantExpression ? $attribute->getAttribute('value') : null;
            foreach (is_string($name) ? self::named($deprecations, $name, 'method') : [] as $d) {
                $found[] = [$d['message'], $node->getTemplateLine()];
            }
        }
        foreach ($node as $child) {
            array_push($found, ...self::twig_usages($child, $deprecations));
        }

        return $found;
    }

    /**
     * Parse a template, collecting the deprecations Twig triggers for Gaffer's
     * own Twig functions and filters while it does.
     *
     * @return array{?\Twig\Node\ModuleNode, list<array{string, ?int}>} the module, and each message with its line
     */
    private static function parse_collecting_deprecations(string $name): array
    {
        $messages = [];
        set_error_handler(static function (int $level, string $message) use (&$messages): bool {
            if (!str_starts_with($message, self::TWIG_PACKAGE)) {
                return false; // someone else's deprecation: the normal handler
            }
            // "Since onstage2426/gaffer 1.1: Twig Function "x" is deprecated; use "y" instead in /path/file.twig at line 4."
            preg_match('/^(\S+): (.*?)(?: in \S+ at line (\d+))?\.$/s', substr($message, strlen(self::TWIG_PACKAGE)), $m);
            $messages[] = isset($m[2]) ? ["{$m[2]} (since {$m[1]})", isset($m[3]) ? (int) $m[3] : null] : [$message, null];
            return true;
        }, E_USER_DEPRECATED);

        try {
            $module = Templates::parse($name);
        } finally {
            restore_error_handler();
        }

        return [$module, array_values(array_unique($messages, SORT_REGULAR))];
    }

    /**
     * @param list<array{class: class-string, name: string, kind: 'method'|'constant', message: string}> $deprecations
     * @return list<array{class: class-string, name: string, kind: 'method'|'constant', message: string}>
     */
    private static function named(array $deprecations, string $name, string $kind): array
    {
        return array_values(array_filter(
            $deprecations,
            static fn(array $d): bool => $d['kind'] === $kind && ($kind === 'constant' ? $d['name'] === $name : strcasecmp($d['name'], $name) === 0),
        ));
    }

    /**
     * Whether `{receiver}::` can be the deprecated member's class: a Gaffer class
     * must be (a subclass of) it; anything else (self, a theme class) can be.
     *
     * @param array<string, string> $imports alias => class
     */
    private static function receiver_matches(?PhpToken $receiver, string $class, string $namespace, array $imports): bool
    {
        if ($receiver === null || !$receiver->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            return true;
        }
        $name = $receiver->text;
        $first = explode('\\', $name)[0];
        $resolved = match (true) {
            $receiver->is(T_NAME_FULLY_QUALIFIED) => ltrim($name, '\\'),
            in_array(strtolower($name), ['self', 'static', 'parent'], true) => null,
            isset($imports[strtolower($first)]) => $imports[strtolower($first)] . substr($name, strlen($first)),
            default => ltrim("{$namespace}\\{$name}", '\\'),
        };

        return $resolved === null || !str_starts_with($resolved, 'Gaffer\\') || !class_exists($resolved) || is_a($resolved, $class, true);
    }

    /**
     * The file's namespace and its top-level `use` imports (lowercased alias => class).
     *
     * @param list<PhpToken> $tokens
     * @return array{string, array<string, string>}
     */
    private static function imports(array $tokens): array
    {
        $namespace = '';
        $imports = [];
        $depth = 0;
        foreach ($tokens as $i => $token) {
            $depth += match ($token->text) { '{' => 1, '}' => -1, default => 0 };
            if ($token->is(T_NAMESPACE) && ($tokens[$i + 1] ?? null)?->is([T_STRING, T_NAME_QUALIFIED])) {
                $namespace = $tokens[$i + 1]->text;
            }
            if (!$token->is(T_USE) || $depth > 0) {
                continue;
            }
            $name = $tokens[$i + 1] ?? null;
            if ($name === null || !$name->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                continue; // use function/const, or a group use: not class imports Gaffer has
            }
            $class = ltrim($name->text, '\\');
            $alias = ($tokens[$i + 2] ?? null)?->is(T_AS) ? $tokens[$i + 3]->text : substr((string) strrchr('\\' . $class, '\\'), 1);
            $imports[strtolower($alias)] = $class;
        }

        return [$namespace, $imports];
    }
}
