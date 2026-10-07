<?php

declare(strict_types=1);

namespace Gaffer\Types;

/**
 * The factories' shared part for Post and Term: a WordPress object in its mapped
 * class, kept only when it's an instance of the class the factory was called on
 * (Product::from() is null for a page).
 *
 * @internal
 */
trait Wraps
{
    /** The object in its mapped class, null when it isn't this type's WordPress object. */
    abstract private static function wrapped(mixed $wp): ?self;

    private static function wrap_one(mixed $wp): ?static
    {
        $object = self::wrapped($wp);

        return $object instanceof static ? $object : null;
    }

    /**
     * @param array<mixed> $wp_objects
     * @return list<static>
     */
    private static function wrap_all(array $wp_objects): array
    {
        return array_values(array_filter(array_map(self::wrap_one(...), $wp_objects)));
    }
}
