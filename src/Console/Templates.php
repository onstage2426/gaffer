<?php

declare(strict_types=1);

namespace Gaffer\Console;

use Gaffer\Paths;
use Gaffer\View;
use Twig\Environment;
use Twig\Error\Error as TwigError;
use Twig\Loader\FilesystemLoader;
use Twig\Node\EmbedNode;
use Twig\Node\Expression\ArrowFunctionExpression;
use Twig\Node\Expression\Binary\ConcatBinary;
use Twig\Node\Expression\Binary\NullCoalesceBinary;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\Filter\DefaultFilter;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\TestExpression;
use Twig\Node\Expression\Variable\AssignContextVariable;
use Twig\Node\Expression\Variable\AssignTemplateVariable;
use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\ImportNode;
use Twig\Node\IncludeNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\SetNode;
use Twig\Node\TextNode;

/**
 * The theme's Twig templates, read through Twig's own parser: which templates
 * exist, what each includes and which variables it reads from its caller.
 */
final class Templates
{
    /** Twig's own variables, never passed in. */
    /** View::render('name') / View::fetch('name') in PHP: the name is group 2. */
    public const string PHP_RENDER = '/View::(?:render|fetch)\(\s*([\'"])([^\'"]+)\1\s*[,)]/';

    private const array BUILT_IN = ['loop', '_self', '_context', '_charset', '_key'];

    /**
     * The templates PHP code renders (PHP_RENDER), with the offset of each name. A double-quoted
     * name with variables in it ("cards/{$type}.twig") is computed: template null, pattern the
     * name with * for each variable (null when it's nothing but variables).
     *
     * @return list<array{template: ?string, pattern: ?string, offset: int}>
     */
    public static function php_renders(string $code): array
    {
        preg_match_all(self::PHP_RENDER, $code, $m, PREG_OFFSET_CAPTURE);
        $found = [];
        foreach ($m[2] as $i => [$name, $offset]) {
            if ($m[1][$i][0] !== '"' || !str_contains($name, '$')) {
                $found[] = ['template' => $name, 'pattern' => null, 'offset' => $offset];
                continue;
            }
            // {$x}, ${x}, $x, $x->y, $x['y'] (PHP's interpolation forms) each become one *.
            $parts = preg_split('/\{\$[^}]*\}|\$\{[^}]*\}|\$[A-Za-z_]\w*(?:->[A-Za-z_]\w*|\[[^\]]*\])?/', $name) ?: [$name];
            $pattern = implode('*', array_map(static fn(string $part): string => addcslashes($part, '*?[\\'), $parts));
            $found[] = ['template' => null, 'pattern' => trim($pattern, '*') === '' ? null : (string) preg_replace('/\*+/', '*', $pattern), 'offset' => $offset];
        }

        return $found;
    }

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
     * The parsed template, or null when it doesn't compile (twig:lint reports that). $env:
     * another environment to parse with (parsing initializes an environment's extensions).
     */
    public static function parse(string $name, ?Environment $env = null): ?ModuleNode
    {
        $env ??= View::env();
        try {
            return $env->parse($env->tokenize($env->getLoader()->getSourceContext($name)));
        } catch (TwigError) {
            return null;
        }
    }

    /**
     * The template's source without its Twig comments ({# … #}): its markup and its Twig, for
     * checks that look for text in it (a comment that mentions a <form> isn't one). Null when
     * Twig can't load it.
     */
    public static function code(string $name, ?Environment $env = null): ?string
    {
        $env ??= View::env();
        try {
            $code = $env->getLoader()->getSourceContext($name)->getCode();
        } catch (TwigError) {
            return null;
        }

        return (string) preg_replace('/\{#.*?#\}/s', '', $code);
    }

    /**
     * $name and every template rendering it brings in by fixed name (include(), the include,
     * embed, extends, use, import and from tags; not source(), which only reads a file), each
     * once, depth first in the order they appear.
     *
     * @return list<string>
     */
    public static function brought_in(string $name, ?Environment $env = null): array
    {
        $found = [];
        $visit = static function (string $name) use (&$visit, &$found, $env): void {
            if (in_array($name, $found, true)) {
                return; // already listed, or an include cycle
            }
            $found[] = $name;
            $module = self::parse($name, $env);
            foreach ($module !== null ? self::references($module) : [] as $ref) {
                if ($ref['template'] !== null && $ref['kind'] !== 'source()') {
                    $visit($ref['template']);
                }
            }
        };
        $visit($name);

        return $found;
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
     * Templates and files this one loads: include()/source() calls, include/embed tags and
     * the tags that load another template's blocks or macros (extends, use, import, from).
     * template is null when the name is computed; then pattern is the name with * for the
     * computed parts, when it starts and ends with fixed text. isolated means the template
     * gets only its own variables (with_context = false, "only" on a tag; use, import and
     * from pass none).
     *
     * @return list<array{template: ?string, pattern: ?string, line: int, kind: string, isolated: bool}>
     */
    public static function references(Node $node): array
    {
        return self::walk($node, []);
    }

    /**
     * @param array<int, ModuleNode> $embedded the current module's {% embed %} bodies by index
     * @return list<array{template: ?string, pattern: ?string, line: int, kind: string, isolated: bool}>
     */
    private static function walk(Node $node, array $embedded): array
    {
        $found = [];

        if ($node instanceof ModuleNode) {
            // An embed's body is a module of its own, kept in an attribute (not a child node).
            $embedded = self::embedded($node);
            if ($node->hasNode('parent') && $node->getAttribute('index') === null) { // an embedded module's parent is its embed tag's
                $found[] = self::reference($node->getNode('parent'), $node->getNode('parent')->getTemplateLine(), '{% extends %}', false);
            }
            foreach ($node->getNode('traits') as $trait) {
                $found[] = self::reference($trait->getNode('template'), $trait->getNode('template')->getTemplateLine(), '{% use %}', true);
            }
        } elseif ($node instanceof FunctionExpression && in_array($node->getAttribute('name'), ['include', 'source'], true)) {
            $arguments = $node->getNode('arguments');
            $first = $arguments->hasNode('0') ? $arguments->getNode('0') : ($arguments->hasNode('template') ? $arguments->getNode('template') : ($arguments->hasNode('name') ? $arguments->getNode('name') : null));
            $context = $arguments->hasNode('with_context') ? $arguments->getNode('with_context') : ($arguments->hasNode('2') ? $arguments->getNode('2') : null);
            $found[] = self::reference($first, $node->getTemplateLine(), $node->getAttribute('name') . '()',
                $node->getAttribute('name') === 'source' || ($context instanceof ConstantExpression && $context->getAttribute('value') === false));
        } elseif ($node instanceof EmbedNode) {
            $module = $embedded[(int) $node->getAttribute('index')] ?? null;
            $found[] = self::reference($module?->hasNode('parent') ? $module->getNode('parent') : null, $node->getTemplateLine(), '{% embed %}', (bool) $node->getAttribute('only'));
            if ($module !== null) {
                array_push($found, ...self::walk($module, $embedded)); // what the embed's blocks load
            }
        } elseif ($node instanceof IncludeNode) {
            $found[] = self::reference($node->getNode('expr'), $node->getTemplateLine(), '{% include %}', (bool) $node->getAttribute('only'));
        } elseif ($node instanceof ImportNode) {
            // {% from %} imports into a template variable without a name.
            $var = $node->getNode('var');
            $from = $var instanceof AssignTemplateVariable && $var->getNode('var')->getAttribute('name') === null;
            $found[] = self::reference($node->getNode('expr'), $node->getTemplateLine(), $from ? '{% from %}' : '{% import %}', true);
        }

        foreach ($node as $child) {
            array_push($found, ...self::walk($child, $embedded));
        }

        return $found;
    }

    /** @return array<int, ModuleNode> by index */
    private static function embedded(ModuleNode $module): array
    {
        $embedded = [];
        $templates = $module->getAttribute('embedded_templates');
        foreach ($templates instanceof Node ? $templates : [] as $template) {
            if ($template instanceof ModuleNode) {
                $embedded[(int) $template->getAttribute('index')] = $template;
            }
        }

        return $embedded;
    }

    /** @return array{template: ?string, pattern: ?string, line: int, kind: string, isolated: bool} */
    private static function reference(?Node $name, int $line, string $kind, bool $isolated): array
    {
        return [
            'template' => $name instanceof ConstantExpression && is_string($name->getAttribute('value')) ? $name->getAttribute('value') : null,
            'pattern' => $name instanceof ConcatBinary ? self::pattern($name) : null,
            'line' => $line,
            'kind' => $kind,
            'isolated' => $isolated,
        ];
    }

    /**
     * Calls of methods whose name matches $pattern: [method, line]. Used for get_* (WordPress,
     * WooCommerce and plugin objects; Gaffer's types have none) and meta() (a field name in
     * the template).
     *
     * @return list<array{string, int}>
     */
    public static function method_calls(Node $node, string $pattern): array
    {
        $found = [];
        if ($node instanceof GetAttrExpression) {
            $attribute = $node->getNode('attribute');
            if ($attribute instanceof ConstantExpression && is_string($attribute->getAttribute('value')) && preg_match($pattern, $attribute->getAttribute('value'))) {
                $found[] = [$attribute->getAttribute('value'), $node->getTemplateLine()];
            }
        }
        foreach ($node as $child) {
            array_push($found, ...self::method_calls($child, $pattern));
        }

        return $found;
    }

    /**
     * What this template includes between a <form> and its </form> in its own HTML (in
     * source order; a form opened in one branch of an if counts for the rest): the include()
     * calls and tags, as references() describes them.
     *
     * @return list<array{template: ?string, pattern: ?string, line: int, kind: string, isolated: bool}>
     */
    public static function includes_in_form(Node $node): array
    {
        $depth = 0;
        $embedded = $node instanceof ModuleNode ? self::embedded($node) : [];
        $found = [];
        $walk = static function (Node $node) use (&$walk, &$depth, &$found, $embedded): void {
            if ($node instanceof TextNode) {
                $text = (string) $node->getAttribute('data');
                $depth = max(0, $depth + (int) preg_match_all('/<form\b/i', $text) - (int) preg_match_all('/<\/form\s*>/i', $text));
                return;
            }
            if (($node instanceof FunctionExpression && $node->getAttribute('name') === 'include') || $node instanceof IncludeNode) {
                if ($depth > 0) {
                    array_push($found, ...self::walk($node, $embedded));
                }
                return;
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($node);

        return $found;
    }

    /**
     * Lines where |t gets something other than a literal string (`(a ? 'x' : 'y')|t`,
     * a variable): it translates at runtime, but string scanners (WPML, Poedit) only
     * find literals, so translators never see those strings.
     *
     * @return list<int>
     */
    public static function non_literal_translations(Node $node): array
    {
        $found = [];
        if ($node instanceof FilterExpression && $node->getAttribute('name') === 't') {
            $subject = $node->getNode('node');
            if (!$subject instanceof ConstantExpression || !is_string($subject->getAttribute('value'))) {
                $found[] = $node->getTemplateLine();
            }
        }
        foreach ($node as $child) {
            array_push($found, ...self::non_literal_translations($child));
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
