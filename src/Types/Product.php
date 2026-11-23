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
 * but thumbnail(), which a theme may override (a placeholder, images from elsewhere).
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
