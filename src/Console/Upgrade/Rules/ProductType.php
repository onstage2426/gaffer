<?php

declare(strict_types=1);

namespace Gaffer\Console\Upgrade\Rules;

use Gaffer\Config;
use Gaffer\Console\Upgrade\Change;
use Gaffer\Console\Upgrade\Php;
use Gaffer\Console\Upgrade\Rule;
use Gaffer\Console\Upgrade\Theme;
use Gaffer\Types\Post;
use Gaffer\Types\Product;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * CHANGELOG 2026-11-23: the theme's `product` type extends Gaffer\Types\Product instead of Post.
 * Only when the theme's class declares none of Product's final methods: those (its own
 * product(), gallery(), ...) and `select_variation()` calls are by hand.
 *
 * @internal
 */
final class ProductType extends Rule
{
    #[\Override]
    public function id(): string
    {
        return '2026-11-23-product-type';
    }

    #[\Override]
    public function describe(): string
    {
        return 'the product type extends Gaffer\\Types\\Product instead of Post';
    }

    #[\Override]
    public function changes(Theme $theme): array
    {
        [$path, $class] = $this->found($theme) ?? [null, null];
        if ($path === null || $class === null || $this->clashes($class) !== []) {
            return [];
        }
        $code = (string) $theme->read($path);
        $short = substr($class, (int) strrpos($class, '\\') + 1);
        $after = Php::edit($code, new class ($short) extends NodeVisitorAbstract {
            public function __construct(private string $short) {}

            #[\Override]
            public function enterNode(Node $node): ?Node
            {
                if ($node instanceof Node\Stmt\Class_ && $node->name?->toString() === $this->short) {
                    $node->extends = new Node\Name\FullyQualified('Gaffer\\Types\\Product');
                }

                return null;
            }
        });

        return $after !== null && $after !== $code ? [new Change($path, $code, $after)] : [];
    }

    #[\Override]
    public function by_hand(Theme $theme): array
    {
        [$path, $class] = $this->found($theme) ?? [null, null];
        if ($path === null || $class === null) {
            return [];
        }
        $clashes = $this->clashes($class);

        return $clashes === [] ? [] : ["{$path}: {$class} declares " . implode('(), ', $clashes) . '(), which Gaffer\\Types\\Product has (final): remove or rename them, then extend \\Gaffer\\Types\\Product (CHANGELOG 2026-11-23).'];
    }

    /** @return array{string, class-string}|null the product type's file (relative) and class, while it extends Post */
    private function found(Theme $theme): ?array
    {
        $class = Config::get('theme.types.product');
        if (!is_string($class) || !class_exists($class) || get_parent_class($class) !== Post::class) {
            return null;
        }
        $file = (string) new \ReflectionClass($class)->getFileName();
        if (!str_starts_with($file, $theme->root . '/')) {
            return null;
        }

        return [substr($file, strlen($theme->root) + 1), $class];
    }

    /**
     * @param class-string $class
     * @return list<string> Product's final methods the class declares itself
     */
    private function clashes(string $class): array
    {
        $own = array_map(
            static fn(\ReflectionMethod $m): string => $m->getName(),
            array_filter(new \ReflectionClass($class)->getMethods(), static fn(\ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === $class),
        );
        $final = array_map(
            static fn(\ReflectionMethod $m): string => $m->getName(),
            array_filter(new \ReflectionClass(Product::class)->getMethods(), static fn(\ReflectionMethod $m): bool => $m->isFinal() && $m->getDeclaringClass()->getName() === Product::class),
        );

        return array_values(array_intersect($own, $final));
    }
}
