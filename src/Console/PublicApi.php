<?php

declare(strict_types=1);

namespace Gaffer\Console;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;

/**
 * Gaffer's public API (API.md), read from the code: classes outside Console\,
 * Ai\ and Mcp\ (plus Console\Console, the CLI entry point), minus everything
 * marked @internal. tests/ApiTest.php snapshots it; `doctor` warns about theme
 * code that uses what's deprecated in it.
 *
 * @internal
 */
final class PublicApi
{
    /** @return list<class-string> */
    public static function classes(): array
    {
        $src = dirname(__DIR__);
        $classes = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $file) {
            $class = 'Gaffer\\' . str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($src) + 1));
            if ((!preg_match('/^Gaffer\\\\(Console|Ai|Mcp)\\\\/', $class) || $class === Console::class)
                && !self::internal(new ReflectionClass($class)->getDocComment())) {
                /** @var class-string $class */
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }

    public static function internal(string|false $doc): bool
    {
        return $doc !== false && str_contains($doc, '@internal');
    }

    /**
     * The public methods and constants of these classes marked #[\Deprecated]
     * (declared there, not inherited).
     *
     * @param list<class-string> $classes
     * @return list<array{class: class-string, name: string, kind: 'method'|'constant', message: string}>
     */
    public static function deprecations(array $classes): array
    {
        $found = [];
        foreach ($classes as $class) {
            $r = new ReflectionClass($class);
            $members = [
                ...array_map(static fn(ReflectionMethod $m): array => ['method', $m], $r->getMethods(ReflectionMethod::IS_PUBLIC)),
                ...array_map(static fn(ReflectionClassConstant $c): array => ['constant', $c], $r->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC)),
            ];
            foreach ($members as [$kind, $member]) {
                $attribute = $member->getAttributes(\Deprecated::class)[0] ?? null;
                if ($attribute === null || $member->getDeclaringClass()->getName() !== $class || self::internal($member->getDocComment())) {
                    continue;
                }
                $deprecated = $attribute->newInstance();
                $found[] = [
                    'class' => $class,
                    'name' => $member->getName(),
                    'kind' => $kind,
                    'message' => $class . '::' . $member->getName() . ($kind === 'method' ? '()' : '') . ' is deprecated'
                        . ($deprecated->since !== null ? " since {$deprecated->since}" : '')
                        . ($deprecated->message !== null ? ": {$deprecated->message}" : ''),
                ];
            }
        }

        return $found;
    }
}
