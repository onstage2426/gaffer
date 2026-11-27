<?php

declare(strict_types=1);

namespace Gaffer\Console\Upgrade\Rules;

use Gaffer\Console\Upgrade\Change;
use Gaffer\Console\Upgrade\Php;
use Gaffer\Console\Upgrade\Rule;
use Gaffer\Console\Upgrade\Theme;
use PhpParser\Node;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * CHANGELOG 2026-11-27: the starter's cart badge moves onto Gaffer's gaffer_cart cookie and
 * `@gaffer/cart.js`. Only when the theme has the starter's badge (bs_cart_count read in x-data):
 * the badge, the Alpine registration, the drawer's count dispatch, the cookie hook, the
 * sync_count_cookie() calls and method, and the starter's CartCount action. A theme with its own
 * badge code is by hand.
 *
 * @internal
 */
final class CartBadge extends Rule
{
    /** The starter's badge attributes: x-data reading bs_cart_count, the CartCount fetch, the event, x-html. */
    private const string BADGE = '/x-data="\{ count: document\.cookie\.match\(\/\(\?:\^\|; \)bs_cart_count=\(\\\\d\+\)\/\)\?\.\[1\][^"]*"(\s*)x-init="if \(count === null\) count = await \(await fetch\(\'\{\{ ajax_url\(\'CartCount\'\) \}\}\'\)\)\.text\(\)"\s*x-on:cart-count\.window="count = \$event\.detail\.count"\s*x-html="count"/';

    private const string DISPATCH = '/^[ \t]*<div class="hidden" x-data x-init="\$dispatch\(\'cart-count\', \{ count: \{\{ cart_count \}\} \}\)"><\/div>[ \t]*\R/m';

    private const string HOOK = '/^(?:\/\*[^\n]*\*\/[ \t]*\R)?add_action\(\s*["\']woocommerce_set_cart_cookies["\'],\s*Cart::sync_count_cookie\(\.\.\.\)\s*\);[ \t]*\R(?:[ \t]*\R)?/m';

    private const string CALL = '/^[ \t]*Cart::sync_count_cookie\(\);[^\n]*\R/m';

    #[\Override]
    public function id(): string
    {
        return '2026-11-27-cart-badge';
    }

    #[\Override]
    public function describe(): string
    {
        return 'the starter\'s cart badge → Gaffer\'s gaffer_cart cookie and cartBadge (@gaffer/cart.js)';
    }

    #[\Override]
    public function changes(Theme $theme): array
    {
        $badges = array_values(array_filter($theme->files(['views'], ['twig']), static fn(string $p): bool => preg_match(self::BADGE, (string) $theme->read($p)) === 1));
        $alpine = $this->alpine_file($theme);
        if ($badges === [] || $alpine === null) {
            return [];
        }
        $changes = [];
        foreach ($badges as $path) {
            $code = (string) $theme->read($path);
            $changes[] = new Change($path, $code, (string) preg_replace(self::BADGE, 'x-data="cartBadge(\'{{ ajax_url(\'GafferCartCount\') }}\')"$1x-text="count"', $code, 1));
        }
        $code = (string) $theme->read($alpine);
        $after = (string) preg_replace('/^(import [^\n]+;\R)(?!import )/m', "\$1import { cartBadge } from \"@gaffer/cart.js\";\n", $code, 1);
        $after = (string) preg_replace('/^(Alpine\.data\([^\n]+\);\R)(?!Alpine\.data)/m', "\$1Alpine.data(\"cartBadge\", cartBadge);\n", $after, 1);
        $changes[] = new Change($alpine, $code, $after);

        foreach ($theme->files(['views'], ['twig']) as $path) {
            $code = (string) $theme->read($path);
            $after = (string) preg_replace(self::DISPATCH, '', $code);
            if ($after !== $code) {
                $changes[] = new Change($path, $code, $after);
            }
        }
        foreach ($theme->files(['app', 'inc'], ['php']) as $path) {
            $code = (string) $theme->read($path);
            $after = (string) preg_replace([self::HOOK, self::CALL], '', $code);
            if ($after !== $code) {
                $after = str_contains($after, 'Cart::') || str_contains($after, 'new Cart') ? $after : (string) preg_replace('/^use Theme\\\\Cart;\R/m', '', $after, 1);
                $changes[] = new Change($path, $code, $after);
            }
        }
        $cart = $theme->with($changes)->read('app/Cart.php');
        if ($cart !== null && str_contains($cart, 'function sync_count_cookie')) {
            $after = Php::edit($cart, new class extends NodeVisitorAbstract {
                #[\Override]
                public function leaveNode(Node $node): ?int
                {
                    return $node instanceof Node\Stmt\ClassMethod && $node->name->toString() === 'sync_count_cookie' ? NodeVisitor::REMOVE_NODE : null;
                }
            });
            if ($after !== null) {
                $changes[] = new Change('app/Cart.php', $cart, $after);
            }
        }
        $action = $theme->read('app/Ajax/CartCount/CartCount.php');
        if ($action !== null && str_contains($action, 'sync_count_cookie') && str_contains($action, 'get_cart_contents_count()') && substr_count($action, ';') <= 8) {
            $changes[] = new Change('app/Ajax/CartCount/CartCount.php', $action, null);
        }

        return $changes;
    }

    #[\Override]
    public function by_hand(Theme $theme): array
    {
        $todo = [];
        foreach ($theme->files(['app', 'inc', 'views', 'assets/js'], ['php', 'twig', 'js']) as $path) {
            $code = (string) $theme->read($path);
            if (preg_match('/\b(?:bs|bsm)?_?cart_count\b(?!")/', $code) === 1 && (str_contains($code, 'setcookie') || str_contains($code, 'document.cookie'))) {
                $todo[] = "{$path} keeps its own cart count cookie: Gaffer keeps gaffer_cart (items and lines), read with cartBadge from @gaffer/cart.js (CHANGELOG 2026-11-27).";
            }
        }

        return $todo;
    }

    private function alpine_file(Theme $theme): ?string
    {
        foreach ($theme->files(['assets/js'], ['js']) as $path) {
            if (preg_match('/^Alpine\.data\(/m', (string) $theme->read($path)) === 1) {
                return $path;
            }
        }

        return null;
    }
}
