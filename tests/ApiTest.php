<?php

declare(strict_types=1);

namespace Gaffer\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;

/**
 * The public API (API.md) as signatures, compared with the committed api.txt:
 * a change to the public API fails here until api.txt is updated on purpose
 * (`UPDATE_API=1 composer test`), so it shows up as a diff in review.
 * Public = classes outside Console\, Ai\ and Mcp\ (plus Console\Console, the CLI
 * entry point), minus everything marked @internal.
 */
final class ApiTest extends TestCase
{
    private const string SNAPSHOT = __DIR__ . '/../api.txt';

    public function test_public_api_matches_the_snapshot(): void
    {
        $api = self::api();

        if (getenv('UPDATE_API') === '1') {
            file_put_contents(self::SNAPSHOT, $api);
        }

        self::assertFileExists(self::SNAPSHOT, 'Create it with: UPDATE_API=1 composer test');
        self::assertSame(
            (string) file_get_contents(self::SNAPSHOT),
            $api,
            "The public API changed. If that's intended (see API.md: deprecate before removing), update the snapshot: UPDATE_API=1 composer test",
        );
    }

    public static function api(): string
    {
        $lines = [];
        foreach (self::classes() as $class) {
            $r = new ReflectionClass($class);
            if (self::internal($r->getDocComment())) {
                continue;
            }

            $kind = match (true) {
                $r->isInterface() => 'interface',
                $r->isTrait() => 'trait',
                default => ($r->isFinal() ? 'final ' : '') . ($r->isAbstract() ? 'abstract ' : '') . 'class',
            };
            $parent = $r->getParentClass();
            $lines[] = "{$kind} {$class}" . ($parent ? " extends {$parent->getName()}" : '');

            foreach ($r->getReflectionConstants() as $constant) {
                if ($constant->isPublic() && $constant->getDeclaringClass()->getName() === $class && !self::internal($constant->getDocComment())) {
                    $lines[] = "  const {$constant->getName()} = " . var_export($constant->getValue(), true);
                }
            }
            foreach ($r->getProperties() as $property) {
                if ($property->isPublic() && $property->getDeclaringClass()->getName() === $class && !self::internal($property->getDocComment())) {
                    $lines[] = '  ' . ($property->isReadOnly() ? 'readonly ' : '') . self::type($property->getType()) . " \${$property->getName()}";
                }
            }
            $methods = array_filter(
                $r->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn(ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === $class && !self::internal($m->getDocComment()),
            );
            usort($methods, static fn(ReflectionMethod $a, ReflectionMethod $b): int => $a->getName() <=> $b->getName());
            foreach ($methods as $m) {
                $lines[] = '  ' . ($m->isFinal() ? 'final ' : '') . ($m->isStatic() ? 'static ' : '') . $m->getName()
                    . '(' . implode(', ', array_map(self::parameter(...), $m->getParameters())) . ')'
                    . ($m->hasReturnType() ? ': ' . self::type($m->getReturnType()) : '');
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /** @return list<class-string> */
    private static function classes(): array
    {
        $src = dirname(__DIR__) . '/src';
        $classes = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $file) {
            $class = 'Gaffer\\' . str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($src) + 1));
            if (!preg_match('/^Gaffer\\\\(Console|Ai|Mcp)\\\\/', $class) || $class === 'Gaffer\\Console\\Console') {
                /** @var class-string $class */
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }

    private static function internal(string|false $doc): bool
    {
        return $doc !== false && str_contains($doc, '@internal');
    }

    private static function parameter(ReflectionParameter $p): string
    {
        return ($p->hasType() ? self::type($p->getType()) . ' ' : '') . ($p->isVariadic() ? '...' : '') . '$' . $p->getName()
            . ($p->isDefaultValueAvailable() ? ' = ' . str_replace(["\n", 'array (', ')'], ['', '[', ']'], var_export($p->getDefaultValue(), true)) : '');
    }

    private static function type(?ReflectionType $type): string
    {
        return $type === null ? '' : (string) $type;
    }
}
