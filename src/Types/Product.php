<?php

declare(strict_types=1);

namespace Gaffer\Types;

use LogicException;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * A WooCommerce product: what every shop's product type needs, asked of WooCommerce. A theme maps
 * `product` to its own subclass in `theme.types` (`class Product extends \Gaffer\Types\Product`)
 * and adds its site's methods there: prices, labels, attribute tables.
 *
 * A selected variation is a copy: with_variation() returns a new object, so a selection made
 * for one partial (a card, the product page) never leaks into another. Every method is final
 * but thumbnail(), which a theme may override (a placeholder, images from elsewhere), and the
 * three a shop overrides whose stock and prices live elsewhere: confirm_purchase(),
 * price_changed_notice() and order_price_changed_notice().
 */
class Product extends Post
{
    private ?WC_Product $product = null;

    private ?WC_Product_Variation $variation = null;

    /** @var array<string, string> */
    private array $selected = [];

    /** WooCommerce's product. A LogicException when the post has none (WooCommerce inactive). */
    final public function product(): WC_Product
    {
        return $this->product ??= (\function_exists('wc_get_product') ? \wc_get_product($this->wp->ID) : null)
            ?: throw new LogicException("Post {$this->wp->ID} has no WooCommerce product.");
    }

    /** Sold in variations (WC_Product_Variable and its subclasses, such as subscriptions). */
    final public function is_variable(): bool
    {
        return $this->product() instanceof WC_Product_Variable;
    }

    /**
     * A copy of this product with the variation these attribute_* values select (the add-to-cart
     * form's or the URL's `attribute_pa_kleur` => `rood`), as a new object of the same class:
     * nothing the theme's subclass worked out for this one carries over. Attributes a variation
     * leaves open ("Any …") match any value; every other attribute must be given. Only variations
     * WooCommerce offers count (get_available_variations()). No match, or a
     * product that isn't variable: the copy has the values in selected() and no variation().
     *
     * @param array<string, string> $attributes
     */
    final public function with_variation(array $attributes): static
    {
        $copy = new static($this->wp);
        $copy->product = $this->product;
        $copy->selected = $attributes;
        $copy->variation = $attributes !== [] ? $this->matching_variation($attributes) : null;

        return $copy;
    }

    /** The variation with_variation() selected, null without one. */
    final public function variation(): ?WC_Product_Variation
    {
        return $this->variation;
    }

    /**
     * The attribute_* values given to with_variation() (a form sends them along), [] without.
     *
     * @return array<string, string>
     */
    final public function selected(): array
    {
        return $this->selected;
    }

    /**
     * Puts this in the visitor's cart the way WooCommerce's own add-to-cart does (its ajax one):
     * the `woocommerce_add_to_cart_product_id` filter, the quantity through `wc_stock_amount()`,
     * only a published product, the `woocommerce_add_to_cart_validation` filter, `add_to_cart()`
     * with the selected variation and attributes (`with_variation()`), and the
     * `woocommerce_ajax_added_to_cart` action that tracking plugins listen to. Returns the cart
     * item key, or null when it was refused: WooCommerce's notices say why (show them in the
     * answer). Call it in an ajax action before output (WooCommerce sets its cookies itself).
     *
     * @throws LogicException without a WooCommerce cart (outside a front-end request)
     */
    final public function add_to_cart(float $quantity = 1): ?string
    {
        $cart = \function_exists('WC') ? \WC()->cart : null;
        if ($cart === null) {
            throw new LogicException('Product::add_to_cart() needs WooCommerce\'s cart: call it in a front-end request or an ajax action.');
        }
        $product_id = (int) \apply_filters('woocommerce_add_to_cart_product_id', $this->wp->ID);
        $quantity = \wc_stock_amount($quantity);
        $variation_id = $this->variation?->get_id() ?? 0;
        $attributes = $variation_id > 0 ? $this->selected : [];
        if (\get_post_status($product_id) !== 'publish'
            || !\apply_filters('woocommerce_add_to_cart_validation', true, $product_id, $quantity, $variation_id, $attributes)) {
            return null;
        }
        $key = $cart->add_to_cart($product_id, $quantity, $variation_id, $attributes);
        if (!is_string($key)) {
            return null;
        }
        \do_action('woocommerce_ajax_added_to_cart', $product_id);

        return $key;
    }

    /**
     * Whether this can go in the cart as it is: a product that isn't variable, or the selected
     * variation, that WooCommerce calls purchasable and in stock. A site's own rule (who may buy
     * what) belongs in WooCommerce's `woocommerce_is_purchasable` filter, so the cart agrees.
     */
    final public function can_add_to_cart(): bool
    {
        $buyable = $this->variation ?? ($this->is_variable() ? null : $this->product());

        return $buyable !== null && $buyable->is_purchasable() && $buyable->is_in_stock();
    }

    /** WooCommerce's answer for the selected variation, else for the product. */
    final public function is_on_sale(): bool
    {
        return ($this->variation ?? $this->product())->is_on_sale();
    }

    /**
     * The selected variation's image, else the product's; null without one (no placeholder: a
     * theme that wants one overrides this and falls back to it).
     */
    #[\Override]
    public function thumbnail(): ?Image
    {
        return Image::from((int) $this->variation?->get_image_id())
            ?? Image::from((int) $this->product()->get_image_id());
    }

    /**
     * Whether this can be bought right now, asked of the system that owns its stock and price (an
     * ERP, a dealer system) when WordPress only holds a copy. Override it and Gaffer asks it at
     * every step that sells: when it goes into a cart (any way: a form, `?add-to-cart=`, the
     * Store API, a theme's own `WC()->cart->add_to_cart()`), on the cart and checkout pages, when
     * the order is placed and before an existing order is paid. Return null when it can be
     * bought, or the reason it can't (shown to the customer; the item leaves the cart).
     *
     * Bring WordPress's copy up to date here (the price, the stock): Gaffer reads the price
     * after this and refuses a checkout when it changed since the customer last saw it
     * (price_changed_notice()), and the payment of an existing order placed at another price
     * (order_price_changed_notice()). An exception refuses too, with WooCommerce's "try again"
     * message (the source couldn't be asked: never sell on an unconfirmed answer), and is logged.
     * It runs again at each step (once per request): make it safe to repeat. Two customers
     * buying the last one at once is the source's to settle (a reservation it refuses twice).
     * Without an override (the default), Gaffer asks nothing and checks no prices.
     */
    public function confirm_purchase(): ?string
    {
        return null;
    }

    /**
     * The notice when the price changed since the customer last saw it in the cart (prices as
     * wc_price() HTML). The checkout stops once; with the new price shown, it goes through.
     * Override it for the site's language.
     */
    public function price_changed_notice(string $was, string $now): string
    {
        return sprintf('The price of %s changed from %s to %s. Check the new total and try again.', $this->title(), $was, $now);
    }

    /**
     * The notice when an existing order (order-pay: a pending order, a failed payment, an order
     * made in the admin) was placed at another price than the product has now. The order keeps
     * the price it was placed at, so it can't be paid: the customer places a new order. Override
     * it for the site's language.
     */
    public function order_price_changed_notice(string $was, string $now): string
    {
        return sprintf('The price of %s changed from %s to %s since this order was placed, so it can\'t be paid. Please place a new order.', $this->title(), $was, $now);
    }

    /**
     * The product gallery's images, in order, without the product image (a card's hover image
     * is `product.gallery|first`).
     *
     * @return list<Image>
     */
    final public function gallery(): array
    {
        return array_values(array_filter(array_map(
            static fn(int|string $id): ?Image => Image::from((int) $id),
            $this->product()->get_gallery_image_ids(),
        )));
    }

    /**
     * The first variation WooCommerce offers (get_available_variations(): published, visible,
     * in stock when the shop hides the rest) whose attributes all match.
     *
     * @param array<string, string> $attributes
     */
    private function matching_variation(array $attributes): ?WC_Product_Variation
    {
        $product = $this->product();
        if (!$product instanceof WC_Product_Variable) {
            return null;
        }
        foreach ($product->get_available_variations('objects') as $variation) {
            $matches = array_all(
                $variation->get_variation_attributes(),
                static fn(string $value, string $key): bool => isset($attributes[$key]) && ($value === '' || $attributes[$key] === $value),
            );
            if ($matches) {
                return $variation;
            }
        }

        return null;
    }
}
