<?php

declare(strict_types=1);

namespace Gaffer\Console;

use Gaffer\Paths;
use Gaffer\View;
use Twig\Error\Error as TwigError;
use Twig\Loader\FilesystemLoader;
use Twig\Node\EmbedNode;
use Twig\Node\Expression\ArrowFunctionExpression;
use Twig\Node\Expression\Binary\ConcatBinary;
use Twig\Node\Expression\Binary\NullCoalesceBinary;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\Filter\DefaultFilter;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\TestExpression;
use Twig\Node\Expression\Variable\AssignContextVariable;
use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\IncludeNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\SetNode;

/**
 * The theme's Twig templates, read through Twig's own parser: which templates
 * exist, what each includes and which variables it reads from its caller.
 */
final class Templates
{
    /** Twig's own variables, never passed in. */
    private const array BUILT_IN = ['loop', '_self', '_context', '_charset', '_key'];

    /**
     * Every template Twig can load, as Twig name => file.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $roots = [FilesystemLoader::MAIN_NAMESPACE => Paths::views(), ...Paths::view_namespaces()];
        $templates = [];

        foreach ($roots as $namespace => $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (ThemeFiles::find(['twig'], $dir) as $file) {
                $relative = substr($file, strlen($dir) + 1);
                $templates[$namespace === FilesystemLoader::MAIN_NAMESPACE ? $relative : "@{$namespace}/{$relative}"] = $file;
            }
        }

        return $templates;
    }

    /**
     * The parsed template, or null when it doesn't compile (twig:lint reports that).
     */
    public static function parse(string $name): ?ModuleNode
    {
        $env = View::env();
        try {
            return $env->parse($env->tokenize($env->getLoader()->getSourceContext($name)));
        } catch (TwigError) {
            return null;
        }
    }

    /**
     * A computed template name as a pattern: 'components/filters/widget-' ~ name ~ '.twig'
     * → components/filters/widget-*.twig. Null without fixed text at both ends.
     */
    private static function pattern(ConcatBinary $concat): ?string
    {
        $parts = [];
        $flatten = static function (Node $node) use (&$flatten, &$parts): void {
            if ($node instanceof ConcatBinary) {
                $flatten($node->getNode('left'));
                $flatten($node->getNode('right'));
            } else {
                $parts[] = $node instanceof ConstantExpression && is_string($node->getAttribute('value')) ? $node->getAttribute('value') : null;
            }
        };
        $flatten($concat);

        if (!is_string($parts[0] ?? null) || $parts[0] === '' || !is_string($parts[count($parts) - 1])) {
            return null; // without fixed text at both ends it could be any template
        }

        $pattern = implode('', array_map(
            static fn(?string $part): string => $part === null ? '*' : addcslashes($part, '*?[\\'),
            $parts,
        ));

        return (string) preg_replace('/\*+/', '*', $pattern);
    }

    /**
     * Templates and files this one loads: include()/source() calls and include/embed tags.
     * template is null when the name is computed; then pattern is the name with * for the
     * computed parts, when it starts and ends with fixed text. isolated means the include
     * passes only its own variables (with_context = false, or "only" on a tag).
     *
     * @return list<array{template: ?string, pattern: ?string, line: int, kind: string, isolated: bool}>
     */
    public static function references(Node $node): array
    {
        $found = [];

        if ($node instanceof FunctionExpression && in_array($node->getAttribute('name'), ['include', 'source'], true)) {
            $arguments = $node->getNode('arguments');
            $first = $arguments->hasNode('0') ? $arguments->getNode('0') : ($arguments->hasNode('template') ? $arguments->getNode('template') : ($arguments->hasNode('name') ? $arguments->getNode('name') : null));
            $context = $arguments->hasNode('with_context') ? $arguments->getNode('with_context') : ($arguments->hasNode('2') ? $arguments->getNode('2') : null);
            $found[] = [
                'template' => $first instanceof ConstantExpression && is_string($first->getAttribute('value')) ? $first->getAttribute('value') : null,
                'pattern' => $first instanceof ConcatBinary ? self::pattern($first) : null,
                'line' => $node->getTemplateLine(),
                'kind' => $node->getAttribute('name') . '()',
                'isolated' => $node->getAttribute('name') === 'source' || ($context instanceof ConstantExpression && $context->getAttribute('value') === false),
            ];
        } elseif ($node instanceof IncludeNode) {
            $expr = $node->getNode('expr');
            $found[] = [
                'template' => $node instanceof EmbedNode ? (string) $node->getAttribute('name') : ($expr instanceof ConstantExpression && is_string($expr->getAttribute('value')) ? $expr->getAttribute('value') : null),
                'pattern' => !$node instanceof EmbedNode && $expr instanceof ConcatBinary ? self::pattern($expr) : null,
                'line' => $node->getTemplateLine(),
                'kind' => $node instanceof EmbedNode ? '{% embed %}' : '{% include %}',
                'isolated' => (bool) $node->getAttribute('only'),
            ];
        }

        foreach ($node as $child) {
            array_push($found, ...self::references($child));
        }

        return $found;
    }

    /**
     * Calls of get_* methods (WordPress/WooCommerce/plugin objects; Gaffer's types
     * have none): [method, line].
     *
     * @return list<array{string, int}>
     */
    public static function getter_calls(Node $node): array
    {
        $found = [];
        if ($node instanceof GetAttrExpression) {
            $attribute = $node->getNode('attribute');
            if ($attribute instanceof ConstantExpression && is_string($attribute->getAttribute('value')) && str_starts_with($attribute->getAttribute('value'), 'get_')) {
                $found[] = [$attribute->getAttribute('value'), $node->getTemplateLine()];
            }
        }
        foreach ($node as $child) {
            array_push($found, ...self::getter_calls($child));
        }

        return $found;
    }

    /**
     * Variables the template reads that it doesn't set itself: name => optional (only
     * used behind ??, |default or "is defined").
     *
     * @param list<string> $ignore e.g. Twig globals and View::share() names
     * @return array<string, bool>
     */
    public static function variables(Node $node, array $ignore = []): array
    {
        $read = [];
        $assigned = [];
        self::collect($node, false, $read, $assigned);

        $variables = [];
        foreach ($read as $name => $optional) {
            if (!in_array($name, [...self::BUILT_IN, ...$ignore], true)) {
                $variables[$name] = $optional;
            }
        }
        ksort($variables);

        return $variables;
    }

    /**
     * @param array<string, bool> $read
     * @param array<string, true> $assigned
     */
    private static function collect(Node $node, bool $optional, array &$read, array &$assigned): void
    {
        if ($node instanceof AssignContextVariable) {
            $assigned[(string) $node->getAttribute('name')] = true;
            return;
        }
        if ($node instanceof ContextVariable) {
            $name = (string) $node->getAttribute('name');
            if (!isset($assigned[$name])) { // after its {% set %} it's the template's own
                $read[$name] = ($read[$name] ?? true) && $optional;
            }
            return;
        }
        if ($node instanceof ArrowFunctionExpression) {
            // Parameters (map(item => item.title)) are local to the body: assigned before it, gone after it.
            $outside = $assigned;
            self::collect($node->getNode('names'), $optional, $read, $assigned);
            self::collect($node->getNode('expr'), $optional, $read, $assigned);
            $assigned = $outside;
            return;
        }
        if ($node instanceof SetNode) {
            // The value is read before the names are assigned: {% set x = x|default(...) %} reads the caller's x.
            self::collect($node->getNode('values'), $optional, $read, $assigned);
            self::collect($node->getNode('names'), $optional, $read, $assigned);
            return;
        }

        $guarded = $node instanceof DefaultFilter
            || ($node instanceof TestExpression && $node->getAttribute('name') === 'defined');
        foreach ($node as $key => $child) {
            $child_optional = $optional || $guarded || ($node instanceof NullCoalesceBinary && in_array($key, ['left', 'test'], true));
            self::collect($child, $child_optional, $read, $assigned);
        }
    }
}
