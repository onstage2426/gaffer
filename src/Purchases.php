<?php

declare(strict_types=1);

namespace Gaffer;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Gaffer\Types\Product;
use ReflectionMethod;
use Throwable;
use WC_Order;
use WC_Order_Item_Product;
use WP_Error;

/**
 * Product::confirm_purchase() at every step where WooCommerce sells, so a theme can't miss one:
 * adding to a cart (woocommerce_add_cart_item_data runs for every way in, the Store API's too),
 * the cart and checkout (woocommerce_check_cart_items: the cart and checkout pages, the classic
 * checkout's validation, the Store API's), and paying an existing order (order-pay, classic and
 * Store API). The price a customer was last shown rides along in the cart item and the order
 * item; a different price now stops the step once, with the theme's notice.
 *
 * @internal
 */
final class Purchases
{
    private const string PRICE = 'gaffer_price';

    private const string ORDER_PRICE = '_gaffer_price';

    /** @var array<int, array{0: bool, 1: ?string}> per product (or variation) id: [asked, reason] for this request */
    private static array $verdicts = [];

    /** @var array<class-string, bool> */
    private static array $guarded = [];

    public static function register(): void
    {
        if (!class_exists('WooCommerce') || !function_exists('add_filter')) {
            return;
        }
        add_filter('woocommerce_add_cart_item_data', self::adding(...), 10, 3);
        add_action('woocommerce_check_cart_items', self::check_cart(...), 0); // before WooCommerce's own stock checks
        add_action('woocommerce_checkout_create_order_line_item', self::order_line(...), 10, 3);
        add_action('woocommerce_before_pay_action', self::paying(...));
        add_action('woocommerce_checkout_validate_order_before_payment', self::paying_store_api(...), 10, 2);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function adding(array $data, int|string $product_id, int|string $variation_id): array
    {
        $id = (int) $variation_id ?: (int) $product_id;
        $product = self::product((int) $product_id, (int) $variation_id);
        if ($product === null) {
            return $data;
        }
        [$asked, $reason] = self::verdict($id, $product);
        if (!$asked || $reason !== null) {
            $message = $reason ?? self::try_again();
            // WC_Cart::add_to_cart() turns any exception into an error notice; the Store API
            // answers a RouteException with its message (a plain one would be a 500).
            throw class_exists(RouteException::class) ? new RouteException('gaffer_purchase_refused', $message, 409) : new \Exception($message);
        }
        $data[self::PRICE] = self::price($id);

        return $data;
    }

    private static function check_cart(): void
    {
        $cart = \WC()->cart; // woocommerce_check_cart_items only runs with one
        $changed = false;
        foreach ($cart->get_cart() as $key => $item) {
            $id = (int) ($item['variation_id'] ?? 0) ?: (int) ($item['product_id'] ?? 0);
            $product = self::product((int) ($item['product_id'] ?? 0), (int) ($item['variation_id'] ?? 0));
            if ($product === null) {
                continue;
            }
            [$asked, $reason] = self::verdict($id, $product);
            if (!$asked) {
                \wc_add_notice(self::try_again(), 'error');
                continue;
            }
            if ($reason !== null) {
                $cart->remove_cart_item($key);
                \wc_add_notice($reason, 'error');
                continue;
            }
            $now = self::price($id);
            $was = isset($item[self::PRICE]) && is_string($item[self::PRICE]) ? $item[self::PRICE] : null;
            if ($now === $was) {
                continue;
            }
            $cart->cart_contents[$key][self::PRICE] = $now;
            $changed = true;
            if ($was !== null && $now !== null) { // null before: added before this check existed
                \wc_add_notice($product->price_changed_notice(\wc_price((float) $was), \wc_price((float) $now)), 'error');
            }
        }
        if ($changed) {
            $cart->set_session();
        }
    }

    /** @param array<string, mixed> $values the cart item */
    private static function order_line(WC_Order_Item_Product $item, string $key, array $values): void
    {
        if (isset($values[self::PRICE]) && is_string($values[self::PRICE])) {
            $item->add_meta_data(self::ORDER_PRICE, $values[self::PRICE], true);
        }
    }

    private static function paying(WC_Order $order): void
    {
        foreach (self::order_problems($order) as $problem) {
            \wc_add_notice($problem, 'error');
        }
    }

    private static function paying_store_api(WC_Order $order, WP_Error $errors): void
    {
        foreach (self::order_problems($order) as $problem) {
            $errors->add('gaffer_purchase_refused', $problem);
        }
    }

    /**
     * Why an order can't be paid now: a product the theme refuses, or whose price changed since
     * the order was placed (the order would charge the old one).
     *
     * @return list<string>
     */
    private static function order_problems(WC_Order $order): array
    {
        $problems = [];
        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $id = $item->get_variation_id() ?: $item->get_product_id();
            $product = self::product($item->get_product_id(), $item->get_variation_id());
            if ($product === null) {
                continue;
            }
            [$asked, $reason] = self::verdict($id, $product);
            if (!$asked || $reason !== null) {
                $problems[] = $reason ?? self::try_again();
                continue;
            }
            $was = $item->get_meta(self::ORDER_PRICE);
            $now = self::price($id);
            if (is_string($was) && $was !== '' && $now !== null && $was !== $now) {
                $problems[] = $product->price_changed_notice(\wc_price((float) $was), \wc_price((float) $now));
            }
        }

        return $problems;
    }

    /** The theme's product (with the variation selected), null when its type doesn't override confirm_purchase(). */
    private static function product(int $product_id, int $variation_id): ?Product
    {
        $product = Product::from($product_id);
        if ($product === null || !self::guarded($product)) {
            return null;
        }
        if ($variation_id > 0) {
            $variation = \wc_get_product($variation_id);
            $product = $product->with_variation($variation instanceof \WC_Product_Variation ? array_map('strval', $variation->get_variation_attributes()) : []);
        }

        return $product;
    }

    private static function guarded(Product $product): bool
    {
        return self::$guarded[$product::class] ??= (new ReflectionMethod($product, 'confirm_purchase'))->getDeclaringClass()->getName() !== Product::class;
    }

    /**
     * Asked once per product per request (a checkout validates the cart, then the order).
     *
     * @return array{0: bool, 1: ?string} [whether it answered, its reason]
     */
    private static function verdict(int $id, Product $product): array
    {
        if (!isset(self::$verdicts[$id])) {
            try {
                self::$verdicts[$id] = [true, $product->confirm_purchase()];
            } catch (Throwable $e) {
                error_log('Gaffer: ' . $product::class . "::confirm_purchase() for product {$id} failed, so it isn't sold now: " . $e->getMessage());
                self::$verdicts[$id] = [false, null];
            }
        }

        return self::$verdicts[$id];
    }

    /** The product's price now, as WooCommerce reads it (a fresh object: confirm_purchase() may have saved it). */
    private static function price(int $id): ?string
    {
        $product = \wc_get_product($id);
        $price = $product instanceof \WC_Product ? (string) $product->get_price() : '';

        return $price === '' ? null : (string) \wc_format_decimal($price, \wc_get_price_decimals());
    }

    private static function try_again(): string
    {
        return \__('We were unable to process your order, please try again.', 'woocommerce');
    }
}
