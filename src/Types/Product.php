<?php

declare(strict_types=1);

namespace Gaffer\Types;

use WP_Post;
use WC_Product;
use WC_Product_Attribute;
use Gaffer\Facades\Theme;
use Automattic\WooCommerce\Enums\ProductType;

class Product extends Post
{
    private array $attributeCache;
    private array $query_vars = [];

    public ?WC_Product $product = null;

    public ?WC_Product $variation = null;

    public static function build(WP_Post $wp_post, array $query_vars = []): static
    {
        $static = new static();
        $static->import($wp_post);
        $static->query_vars = $query_vars;

        $static->product = wc_get_product($wp_post->ID) ?: null;

        if ($static->product?->get_type() === ProductType::VARIABLE) {
            foreach ($static->product->get_available_variations() as $row) {
                $matches = [];
                foreach ($row["attributes"] as $attr => $val) {
                    $matches[] =
                        ($val === "" && isset($query_vars[$attr])) ||
                        (isset($query_vars[$attr]) && $query_vars[$attr] === $val);
                }

                if (array_all($matches, fn($in) => $in)) {
                    $static->variation = \wc_get_product($row["variation_id"]);
                    break;
                }
            }
        }

        return $static;
    }

    public function add_to_cart_id(): int
    {
        if ($this->variation !== null) {
            return $this->variation->get_id();
        }
        return $this->product->get_id();
    }

    public function regular_price(): float
    {
        if ($this->variation !== null) {
            return (float) $this->variation->get_regular_price();
        }

        return (float) $this->product->get_regular_price();
    }

    public function sale_price(): float
    {
        if ($this->variation !== null) {
            return (float) $this->variation->get_sale_price();
        }

        return (float) $this->product->get_sale_price();
    }

    public function from_price(): float
    {
        if ($this->product->get_type() === ProductType::VARIABLE) {
            return min(
                (float) $this->product->get_variation_regular_price("min"),
                (float) $this->product->get_variation_sale_price("min"),
            );
        }

        return 0.0;
    }

    public function is_on_sale(): bool
    {
        if ($this->variation !== null) {
            return $this->variation->is_on_sale();
        }
        return $this->product->is_on_sale();
    }

    public function is_variable(): bool
    {
        return $this->product->get_type() === ProductType::VARIABLE;
    }

    public function thumbnail_id(): int
    {
        if ($this->variation !== null) {
            return (int) $this->variation->get_image_id();
        }
        return (int) $this->product->get_image_id();
    }

    public function thumbnail(): Image
    {
        return Theme::get_image($this->thumbnail_id());
    }

    public function hover_thumbnail(): ?Image
    {
        $product_gallery = $this->product->get_gallery_image_ids();

        return isset($product_gallery[0])
            ? Theme::get_image($product_gallery[0])
            : null;
    }

    public function attributes(): array
    {
        if (!isset($this->attributeCache)) {
            $this->attributeCache = array_map(
                fn($attribute) => [
                    "key"       => strtolower($attribute->get_name()),
                    "title"     => ucfirst(\wc_attribute_label($attribute->get_name())),
                    "value"     => $this->product->get_attribute($attribute->get_name()),
                    "visible"   => $attribute->get_visible(),
                    "taxonomy"  => $attribute->is_taxonomy(),
                    "terms"     => array_map([Theme::class, "get_term"], (array) $attribute->get_terms()),
                    "variation" => $attribute->get_variation(),
                    "options"   => $this->resolveAttributeOptions($attribute),
                    "selected"  => $this->query_vars["attribute_" . strtolower($attribute->get_name())] ?? false,
                ],
                $this->product->get_attributes(),
            );
        }

        return $this->attributeCache;
    }

    private function resolveAttributeOptions(WC_Product_Attribute $attribute): array
    {
        if (!$attribute->is_taxonomy()) {
            return $attribute->get_options();
        }

        $options = [];
        foreach ($attribute->get_options() as $term_id) {
            $term = Theme::get_term($term_id);
            $order = $term->meta("order", true);
            $options["{$order}_{$term->title()}"] = $term;
        }

        ksort($options);
        return array_values($options);
    }

    public function brand(): ?Term
    {
        $brands = $this->terms("product_brand");
        return $brands[0] ?? null;
    }
}
