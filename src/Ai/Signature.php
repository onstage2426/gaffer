<?php

declare(strict_types=1);

namespace Gaffer\Ai;

use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;

/**
 * A readable signature for the generated reference: `name(int $id, ?string $x = null): ?Post`.
 */
final class Signature
{
    public static function of(ReflectionFunctionAbstract $function, ?string $name = null): string
    {
        $params = implode(', ', array_map(self::parameter(...), $function->getParameters()));
        $return = $function->getReturnType();

        return ($name ?? $function->getName()) . "({$params})" . ($return !== null ? ': ' . self::type($return) : '');
    }

    private static function parameter(ReflectionParameter $parameter): string
    {
        $type = $parameter->getType();
        $text = ($type !== null ? self::type($type) . ' ' : '') . ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName();

        if ($parameter->isDefaultValueAvailable()) {
            $text .= ' = ' . self::value($parameter->getDefaultValue());
        }

        return $text;
    }

    private static function type(ReflectionType $type): string
    {
        $text = (string) $type;

        // Short class names read better: ?Gaffer\Types\Image → ?Image
        return $type instanceof ReflectionNamedType && !$type->isBuiltin()
            ? ($type->allowsNull() ? '?' : '') . substr(strrchr('\\' . $type->getName(), '\\') ?: '', 1)
            : $text;
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => "'{$value}'",
            is_array($value) => $value === [] ? '[]' : '[...]',
            default => (string) json_encode($value),
        };
    }
}
