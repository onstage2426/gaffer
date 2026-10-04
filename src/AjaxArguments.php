<?php

declare(strict_types=1);

namespace Gaffer;

use InvalidArgumentException;
use LogicException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Gaffer\Types\Post;
use Gaffer\Types\Term;

/**
 * Maps request input onto an ajax action's run() parameters: parameter name =
 * request key, no default = required, values cast to the declared type.
 *
 * Supported types: string, int, float, bool, array, and Gaffer types (Post,
 * Term and their subclasses, resolved from an ID), all optionally nullable.
 * Bad or missing input throws InvalidArgumentException (a 400), an ID that
 * doesn't resolve throws AjaxNotFound (a 404), and a run() signature Gaffer
 * can't fill throws LogicException (a bug in the action).
 *
 * @internal
 */
final class AjaxArguments
{
    private const array TYPES = ['string', 'int', 'float', 'bool', 'array'];

    /**
     * @param array<array-key, mixed> $input
     * @return array<string, mixed> named arguments for run()
     */
    public static function resolve(AjaxAction $action, array $input): array
    {
        $run = self::run_method($action);
        $problem = self::problem($run);
        if ($problem !== null) {
            throw new LogicException($problem);
        }

        $args = [];
        foreach ($run->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (!array_key_exists($name, $input)) {
                if ($parameter->isDefaultValueAvailable()) {
                    continue;
                }
                throw new InvalidArgumentException("Missing required argument \"{$name}\".");
            }

            $args[$name] = self::cast($input[$name], $parameter);
        }

        return $args;
    }

    /**
     * Why Gaffer can't call this run() with request input, or null when it can.
     * Shared with `doctor`.
     */
    public static function problem(ReflectionMethod $run): ?string
    {
        /** @var class-string<AjaxAction> $class */
        $class = $run->class;

        foreach ($run->getParameters() as $parameter) {
            $type = $parameter->getType();
            $where = "{$class}::run() parameter \${$parameter->getName()}";

            if ($parameter->isVariadic()) {
                return "{$where} is variadic; ajax arguments must be named.";
            }
            if (!$type instanceof ReflectionNamedType) {
                return "{$where} must have a single type (string, int, float, bool, array or a Gaffer type).";
            }
            if (self::is_model($type->getName())) {
                if ($class::SHORTINIT) {
                    return "{$where} is a Gaffer type, which needs a full WordPress load; SHORTINIT actions can only take scalars and arrays.";
                }
                continue;
            }
            if (!in_array($type->getName(), self::TYPES, true)) {
                return "{$where} must be typed as string, int, float, bool, array or a Gaffer type (optionally nullable).";
            }
        }

        return null;
    }

    /**
     * Post, Term and their subclasses (Image, Attachment, a theme's Product, ...).
     */
    private static function is_model(string $type): bool
    {
        return is_a($type, Post::class, true) || is_a($type, Term::class, true);
    }

    public static function run_method(AjaxAction $action): ReflectionMethod
    {
        if (!method_exists($action, 'run')) {
            throw new LogicException($action::class . ' has no run() method.');
        }

        return new ReflectionMethod($action, 'run');
    }

    private static function cast(mixed $value, ReflectionParameter $parameter): mixed
    {
        /** @var ReflectionNamedType $type checked by problem() */
        $type = $parameter->getType();
        $name = $parameter->getName();

        // An empty field means "no value" for nullable non-string parameters.
        if ($value === '' && $type->allowsNull() && $type->getName() !== 'string') {
            return null;
        }

        if (self::is_model($type->getName())) {
            $id = is_int($value) ? $value : filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
            if ($id === null) {
                throw new InvalidArgumentException("Argument \"{$name}\" must be an ID.");
            }

            /** @var class-string<Post>|class-string<Term> $class */
            $class = $type->getName();

            return $class::from($id) ?? throw new AjaxNotFound("No {$class} with ID {$id} for \"{$name}\".");
        }

        $cast = match ($type->getName()) {
            'string' => is_scalar($value) ? (string) $value : null,
            'int' => is_int($value) ? $value : filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
            'float' => is_float($value) ? $value : filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE),
            'bool' => is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            'array' => is_array($value) ? $value : null,
            default => throw new LogicException("Unsupported type {$type->getName()} for \"{$name}\"."),
        };

        if ($cast === null) {
            throw new InvalidArgumentException("Argument \"{$name}\" must be {$type->getName()}.");
        }

        return $cast;
    }
}
