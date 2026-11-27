<?php

declare(strict_types=1);

namespace Gaffer\Console;

use Gaffer\Types\Attachment;
use Gaffer\Types\Image;
use Gaffer\Types\Post;
use Gaffer\Types\Product;
use Gaffer\Types\Term;

/**
 * Method names Gaffer will add to its types in a coming minor (API.md, "Theme subclasses"): a
 * theme subclass declaring one would collide (a fatal error for a final method, a changed
 * signature for another), so `doctor` warns for one minor before the method arrives. Add a name
 * here in the minor before the one that adds the method.
 *
 * @internal
 */
final class ReservedMethods
{
    /** @var array<class-string, list<string>> Gaffer's type => method names it will add */
    public const array LIST = [
        Post::class => [],
        Product::class => [],
        Term::class => [],
        Attachment::class => [],
        Image::class => [],
    ];

    /**
     * The methods a theme class declares itself that Gaffer reserves on one of its parents.
     *
     * @param array<class-string, list<string>> $list
     * @return list<string>
     */
    public static function clashes(string $class, array $list = self::LIST): array
    {
        if (!class_exists($class)) {
            return [];
        }
        $reserved = [];
        foreach ($list as $type => $names) {
            if (is_a($class, $type, true)) {
                array_push($reserved, ...$names);
            }
        }
        $clashes = [];
        foreach (new \ReflectionClass($class)->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === $class && in_array($method->getName(), $reserved, true)) {
                $clashes[] = $method->getName();
            }
        }

        return $clashes;
    }
}
