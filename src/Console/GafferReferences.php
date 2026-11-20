<?php

declare(strict_types=1);

namespace Gaffer\Console;

use PhpToken;

/**
 * The Gaffer classes and static members a PHP file names, with `use` imports and
 * the file's namespace resolved: `use Gaffer\X;`, `X::method()`, `X::CONST`,
 * `new X`, `extends X`, `implements X`, `instanceof X`, and qualified names
 * anywhere. Instance calls (`$post->title()`) aren't resolved: the receiver's
 * class is only known at runtime. Pure: `doctor`'s internal-API warning and
 * `bin/dev/usage` read it.
 *
 * @internal
 */
final class GafferReferences
{
    /** @return list<array{class: string, member: ?string, line: int}> */
    public static function in(string $code): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($code), static fn(PhpToken $t): bool => !$t->isIgnorable()));
        [$namespace, $imports, $import_at] = self::imports($tokens);
        $found = [];

        foreach ($import_at as $i => $class) {
            if (!str_starts_with($class, 'Gaffer\\')) {
                continue;
            }
            $found[] = ['class' => $class, 'member' => null, 'line' => $tokens[$i]->line];
        }
        foreach ($tokens as $i => $token) {
            if (isset($import_at[$i]) || !$token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }
            $before = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1]->text ?? '';
            $named_class = $next === '::'
                || ($before !== null && $before->is([T_NEW, T_EXTENDS, T_IMPLEMENTS, T_INSTANCEOF]))
                || ($before !== null && $before->text === ',' && self::in_implements($tokens, $i))
                || ($token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && $next !== '(');
            if (!$named_class || ($before !== null && $before->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_NAMESPACE, T_USE]))) {
                continue;
            }
            $class = self::resolve($token, $namespace, $imports);
            if ($class === null || !str_starts_with($class, 'Gaffer\\')) {
                continue;
            }
            $member = $next === '::' && ($tokens[$i + 2] ?? null)?->is(T_STRING) ? $tokens[$i + 2]->text : null;
            $found[] = ['class' => $class, 'member' => $member === 'class' ? null : $member, 'line' => $token->line];
        }

        return $found;
    }

    /** @param list<PhpToken> $tokens */
    private static function in_implements(array $tokens, int $i): bool
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if ($tokens[$j]->is(T_IMPLEMENTS)) {
                return true;
            }
            if (!$tokens[$j]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && $tokens[$j]->text !== ',') {
                return false;
            }
        }

        return false;
    }

    /**
     * The file's namespace and top-level `use` class imports (lowercased alias => class).
     *
     * @param list<PhpToken> $tokens without ignorable tokens
     * @return array{string, array<string, string>}
     */
    public static function scope(array $tokens): array
    {
        [$namespace, $imports] = self::imports($tokens);

        return [$namespace, $imports];
    }

    /**
     * A class name token as a fully qualified name; null for self/static/parent.
     *
     * @param array<string, string> $imports lowercased alias => class
     */
    public static function resolve(PhpToken $token, string $namespace, array $imports): ?string
    {
        $name = $token->text;
        $first = explode('\\', $name)[0];

        return match (true) {
            $token->is(T_NAME_FULLY_QUALIFIED) => ltrim($name, '\\'),
            in_array(strtolower($name), ['self', 'static', 'parent'], true) => null,
            isset($imports[strtolower($first)]) => $imports[strtolower($first)] . substr($name, strlen($first)),
            default => ltrim("{$namespace}\\{$name}", '\\'),
        };
    }

    /**
     * The namespace, the top-level `use` class imports (lowercased alias => class), and
     * the token index of each import's name (=> class).
     *
     * @param list<PhpToken> $tokens
     * @return array{string, array<string, string>, array<int, string>}
     */
    private static function imports(array $tokens): array
    {
        $namespace = '';
        $imports = [];
        $at = [];
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
                continue; // use function/const, or a group use
            }
            $class = ltrim($name->text, '\\');
            $alias = ($tokens[$i + 2] ?? null)?->is(T_AS) ? $tokens[$i + 3]->text : substr((string) strrchr('\\' . $class, '\\'), 1);
            $imports[strtolower($alias)] = $class;
            $at[$i + 1] = $class;
        }

        return [$namespace, $imports, $at];
    }
}
