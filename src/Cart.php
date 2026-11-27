<?php

declare(strict_types=1);

namespace Gaffer;

/**
 * The `gaffer_cart` cookie: how many items and lines the visitor's cart has (`items=3&lines=2`), for a
 * header badge on cached pages (`@gaffer/cart.js` reads it). Kept equal to the cart with WooCommerce's
 * own cart cookies and on every change of the lines, before the answer is sent (WooCommerce sets its
 * cookies at shutdown, after an ajax action has already printed), only when it changed, and deleted
 * with WooCommerce's cookies.
 *
 * @internal
 */
final class Cart
{
    public const string COOKIE = 'gaffer_cart';

    public static function register(): void
    {
        if (!class_exists('WooCommerce') || !function_exists('add_action')) {
            return;
        }
        add_action('woocommerce_set_cart_cookies', static function (bool $set): void {
            $set ? self::sync() : self::clear();
        });
        foreach (['woocommerce_add_to_cart', 'woocommerce_cart_item_removed', 'woocommerce_cart_item_restored', 'woocommerce_after_cart_item_quantity_update', 'woocommerce_cart_emptied'] as $hook) {
            add_action($hook, self::sync(...), 100, 0);
        }
    }

    /**
     * The counts as the cookie holds them: items (quantities, as WooCommerce counts) and lines.
     *
     * @return array{items: int|float, lines: int}
     */
    public static function counts(): array
    {
        $cart = self::cart();
        if ($cart === null) {
            return ['items' => 0, 'lines' => 0];
        }
        // The lines as they are: get_cart() would load the session, also from within woocommerce_cart_emptied.
        $lines = array_filter($cart->get_cart_contents());
        $items = array_sum(array_map(static fn(array $line): float => (float) ($line['quantity'] ?? 0), $lines));

        return ['items' => fmod($items, 1.0) === 0.0 ? (int) $items : round($items, 2), 'lines' => count($lines)];
    }

    public static function sync(): void
    {
        if (self::cart() === null || headers_sent()) {
            return;
        }
        $value = http_build_query(self::counts());
        if (($_COOKIE[self::COOKIE] ?? null) !== $value) {
            \wc_setcookie(self::COOKIE, $value, 0, \is_ssl());
            $_COOKIE[self::COOKIE] = $value;
        }
    }

    /** WooCommerce's cart, null before WooCommerce loads one (the CLI, an early hook): its stubs type it as always there. */
    public static function cart(): ?\WC_Cart
    {
        $wc = function_exists('WC') ? \WC() : null;
        $cart = $wc !== null ? get_object_vars($wc)['cart'] ?? null : null;

        return $cart instanceof \WC_Cart ? $cart : null;
    }

    public static function clear(): void
    {
        if (isset($_COOKIE[self::COOKIE]) && !headers_sent()) {
            \wc_setcookie(self::COOKIE, '', time() - HOUR_IN_SECONDS, \is_ssl());
            unset($_COOKIE[self::COOKIE]);
        }
    }
}
