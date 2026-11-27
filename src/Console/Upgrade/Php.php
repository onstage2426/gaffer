<?php

declare(strict_types=1);

namespace Gaffer\Console\Upgrade;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * Edits PHP code through its syntax tree and prints it back with the formatting it had
 * (nikic/php-parser's format-preserving printer): only the nodes a rule changes look different.
 *
 * @internal
 */
final class Php
{
    private static ?Parser $parser = null;

    /**
     * The code with the visitors' changes, or null when it doesn't parse. Names are resolved
     * first (`resolvedName`/`namespacedName` attributes) without being replaced.
     */
    public static function edit(string $code, NodeVisitor ...$visitors): ?string
    {
        $parser = self::$parser ??= new ParserFactory()->createForNewestSupportedVersion();
        try {
            $old = $parser->parse($code);
        } catch (\PhpParser\Error) {
            return null;
        }
        if ($old === null) {
            return null;
        }
        $tokens = $parser->getTokens();
        $new = new NodeTraverser(new CloningVisitor())->traverse($old);
        $new = new NodeTraverser(new NameResolver(null, ['preserveOriginalNames' => true, 'replaceNodes' => false]))->traverse($new);
        foreach ($visitors as $visitor) {
            $new = new NodeTraverser($visitor)->traverse($new);
        }

        return new Standard()->printFormatPreserving($new, $old, $tokens);
    }

    /**
     * The syntax tree with names resolved, for rules that only look.
     *
     * @return list<Node>|null
     */
    public static function parse(string $code): ?array
    {
        $parser = self::$parser ??= new ParserFactory()->createForNewestSupportedVersion();
        try {
            $stmts = $parser->parse($code);
        } catch (\PhpParser\Error) {
            return null;
        }

        return $stmts === null ? null : array_values(new NodeTraverser(new NameResolver(null, ['preserveOriginalNames' => true, 'replaceNodes' => false]))->traverse($stmts));
    }
}
