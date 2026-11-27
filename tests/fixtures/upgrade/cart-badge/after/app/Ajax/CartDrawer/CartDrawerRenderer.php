<?php

declare(strict_types=1);

namespace Theme\Ajax\CartDrawer;

use Gaffer\Acf;
use Gaffer\Types\Image;
use Gaffer\Types\Post;
use Gaffer\Types\Term;
use Gaffer\View;
use WC_Coupon;
use WC_Shipping_Free_Shipping;
use WC_Shipping_Zones;

/**
 * Gathers the cart and renders the drawer body (cart/drawer.twig).
 * Used by CartDrawer and CartAdd.
 */
trait CartDrawerRenderer
{
    /**
     * @param list<Post> $cross_sells
     */
    private function render_drawer(bool $added = false, array $cross_sells = []): void
    {
        $cart = WC()->cart;

        View::render("cart/drawer.twig", [
            "cart_items" => array_values(array_map($this->cart_item(...), $cart->get_cart_contents())),
            "cart_count" => $cart->get_cart_contents_count(),
            "subtotal" => $cart->get_subtotal() + $cart->get_subtotal_tax(),
            "total" => (float) $cart->get_totals()["total"],
            "shipping" => $this->cheapest_shipping(),
            "coupons" => array_map($this->coupon(...), $cart->get_applied_coupons()),
            "apply_nonce" => wp_create_nonce("apply-coupon"),
            "checkout_url" => get_permalink(wc_get_page_id("checkout")),
            "trust_icons" => array_values(array_filter(array_map(
                fn(mixed $id): ?Image => Image::from((int) $id),
                Acf::option_array("trust_icons"),
            ))),
            "notices" => wc_notice_count() > 0 ? $this->printed_notices() : "",
            "added" => $added,
            "cross_sells" => $cross_sells,
        ]);
    }

    /**
     * One cart line as the template needs it.
     *
     * @param array<string, mixed> $line WooCommerce cart item
     * @return array<string, mixed>
     */
    private function cart_item(array $line): array
    {
        $product = $line["data"];

        return [
            "key" => $line["key"],
            "quantity" => (int) $line["quantity"],
            "line_total" => (float) $line["line_total"] + (float) $line["line_tax"], // incl. VAT, like the subtotal
            "post" => Post::from((int) $line["product_id"]),
            "image" => Image::from((int) $product->get_image_id()),
            "attributes" => $this->variation_attributes($line),
            "stock" => $product->get_manage_stock() ? (int) $product->get_stock_quantity() : null,
            "remove_url" => wc_get_cart_remove_url($line["key"]),
        ];
    }

    /**
     * An applied coupon: its discount as a percentage or an amount.
     *
     * @return array{code: string, percent: bool, amount: float, nonce: string}
     */
    private function coupon(string $code): array
    {
        $coupon = new WC_Coupon($code);

        return [
            "code" => $code,
            "percent" => $coupon->get_discount_type() === "percent",
            "amount" => (float) $coupon->get_amount(),
            "nonce" => wp_create_nonce("remove-coupon"),
        ];
    }

    /**
     * The chosen variation attributes as label/value pairs ("Kleur: Rood").
     *
     * @param array<string, mixed> $line
     * @return list<array{label: string, value: string}>
     */
    private function variation_attributes(array $line): array
    {
        $attributes = [];
        foreach ($line["variation"] ?? [] as $key => $value) {
            if ($value === "") {
                continue;
            }
            $taxonomy = str_replace("attribute_", "", (string) $key);
            $term = taxonomy_exists($taxonomy) ? get_term_by("slug", $value, $taxonomy) : false;
            $attributes[] = [
                "label" => wc_attribute_label($taxonomy, $line["data"]),
                "value" => $term ? Term::from($term->term_id)?->title() ?? $value : $value,
            ];
        }

        return $attributes;
    }

    /**
     * The cheapest enabled shipping method for the Netherlands (0 with free shipping),
     * null when there is none.
     */
    private function cheapest_shipping(): ?float
    {
        $cheapest = null;
        foreach (WC_Shipping_Zones::get_zones() as $zone) {
            $for_nl = array_any($zone["zone_locations"], fn(object $location): bool => $location->type === "country" && $location->code === "NL");
            if (!$for_nl) {
                continue;
            }
            foreach ($zone["shipping_methods"] as $method) {
                if ($method->enabled !== "yes") {
                    continue;
                }
                $cost = $method instanceof WC_Shipping_Free_Shipping ? 0.0 : (float) $method->cost;
                $cheapest = $cheapest === null ? $cost : min($cheapest, $cost);
            }
        }

        return $cheapest;
    }

    private function printed_notices(): string
    {
        ob_start();
        wc_print_notices();

        return (string) ob_get_clean();
    }
}
