<?php

declare(strict_types=1);

namespace Gaffer\Console\Upgrade\Rules;

use Gaffer\Console\Upgrade\Change;
use Gaffer\Console\Upgrade\Php;
use Gaffer\Console\Upgrade\Rule;
use Gaffer\Console\Upgrade\Theme;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeVisitorAbstract;

/**
 * CHANGELOG 2026-11-27: Post::content() and Term::description() return Markup (safe HTML), like
 * Gaffer's other HTML methods. In PHP an object isn't a string (`?:` never falls through,
 * wp_strip_all_tags() refuses it), so every `->content()`/`->description()` call there gets
 * `(string)`, which keeps what the code did. In Twig the `|raw` (and its trusted comment) after
 * `x.content()`/`x.description()` goes. A method of that name the theme declares itself is left
 * alone where it could be meant; a call followed by `??` is by hand (a string is never null).
 *
 * @internal
 */
final class ContentMarkup extends Rule
{
    private const array NAMES = ['content', 'description'];

    #[\Override]
    public function id(): string
    {
        return '2026-11-27-content-markup';
    }

    #[\Override]
    public function describe(): string
    {
        return 'Post::content() and Term::description() return Markup: (string) in PHP, no |raw in Twig';
    }

    #[\Override]
    public function changes(Theme $theme): array
    {
        $own = $this->own_methods($theme);
        $php = array_values(array_filter(self::NAMES, static fn(string $name): bool => ($own[$name] ?? 'string') === 'string'));
        $twig = array_values(array_diff(self::NAMES, array_keys($own)));
        $changes = [];
        if ($php !== []) {
            foreach ([...$theme->files(['app', 'inc', 'woocommerce', 'blocks'], ['php']), ...$theme->root_files(['php'])] as $path) {
                $code = (string) $theme->read($path);
                if (preg_match('/->(?:' . implode('|', $php) . ')\(\)/', $code) !== 1) {
                    continue;
                }
                $after = Php::edit($code, $this->caster($php));
                if ($after !== null && $after !== $code) {
                    $changes[] = new Change($path, $code, $after);
                }
            }
        }
        if ($twig !== []) {
            $pattern = '/(\.(?:' . implode('|', $twig) . ')\(\))\|raw(\s*-?\}\})(?:[ \t]*\{#-?\s*trusted:[^#]*#\})?/';
            foreach ($theme->files(['views', 'blocks'], ['twig']) as $path) {
                $code = (string) $theme->read($path);
                $after = (string) preg_replace($pattern, '$1$2', $code);
                if ($after !== $code) {
                    $changes[] = new Change($path, $code, $after);
                }
            }
        }

        return $changes;
    }

    #[\Override]
    public function by_hand(Theme $theme): array
    {
        $todo = [];
        foreach ([...$theme->files(['app', 'inc', 'woocommerce', 'blocks'], ['php']), ...$theme->root_files(['php'])] as $path) {
            if (preg_match('/->(?:content|description)\(\)\s*\?\?/', (string) $theme->read($path)) === 1) {
                $todo[] = "{$path} uses ->content() or ->description() with ??: they return Markup now, never null; write (string) … ?: … where a fallback is meant (CHANGELOG 2026-11-27).";
            }
        }

        return $todo;
    }

    /**
     * Instance methods named content() or description() the theme declares: name => 'string' when
     * they return a string (a cast is harmless), else their return type.
     *
     * @return array<string, string>
     */
    private function own_methods(Theme $theme): array
    {
        $own = [];
        foreach ($theme->files(['app'], ['php']) as $path) {
            preg_match_all('/public\s+(static\s+)?function\s+(content|description)\s*\([^)]*\)\s*(?::\s*(\??[\w\\\\|]+))?/', (string) $theme->read($path), $m, PREG_SET_ORDER);
            foreach ($m as $match) {
                if ($match[1] !== '') {
                    continue; // static: called with ::, never ->
                }
                $type = ltrim($match[3] ?? '', '?');
                $own[$match[2]] = in_array($type, ['string', ''], true) && ($own[$match[2]] ?? 'string') === 'string' ? 'string' : ($type ?: 'mixed');
            }
        }

        return $own;
    }

    /** @param list<string> $names */
    private function caster(array $names): NodeVisitorAbstract
    {
        return new class ($names) extends NodeVisitorAbstract {
            /** @param list<string> $names */
            public function __construct(private readonly array $names)
            {
            }

            #[\Override]
            public function enterNode(Node $node): null
            {
                if ($node instanceof Expr\Cast\String_) {
                    $node->expr->setAttribute('gaffer_keep', true);
                } elseif ($node instanceof Expr\BinaryOp\Coalesce) {
                    $node->left->setAttribute('gaffer_keep', true);
                }

                return null;
            }

            #[\Override]
            public function leaveNode(Node $node): ?Node
            {
                if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall)
                    && $node->name instanceof Node\Identifier && in_array($node->name->toString(), $this->names, true)
                    && $node->args === [] && $node->getAttribute('gaffer_keep') !== true) {
                    return new Expr\Cast\String_($node);
                }

                return null;
            }
        };
    }
}
