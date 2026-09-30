<?php

declare(strict_types=1);

namespace Gaffer\Types;

use Twig\Markup;

final class Image extends Attachment
{
    /**
     * Everything inside an <img> tag for the given size: src, srcset, sizes,
     * width/height of that size and the escaped alt. The tag itself (class,
     * loading, fetchpriority, data/Alpine attributes) stays in the template:
     * <img class="..." {{ image.attrs('large') }} loading="lazy">
     *
     * sizes starts with "auto", which browsers only honor on lazy images and
     * skip otherwise.
     */
    public function attrs(string $size = "full"): Markup
    {
        $img = \wp_get_attachment_image_src($this->ID, $size);

        if (!is_array($img)) {
            return new Markup("", "UTF-8");
        }

        [$src, $width, $height] = $img;

        $attrs = ["src" => \esc_url($src)];

        $srcset = \wp_get_attachment_image_srcset($this->ID, $size);
        if ($srcset) {
            $attrs["srcset"] = \esc_attr($srcset);
            $sizes = \wp_get_attachment_image_sizes($this->ID, $size);
            if ($sizes) {
                $attrs["sizes"] = \esc_attr("auto, " . $sizes);
            }
        }

        if ($width && $height) {
            $attrs["width"] = (int) $width;
            $attrs["height"] = (int) $height;
        }

        $attrs["alt"] = \esc_attr(trim(strip_tags($this->alt())));

        $html = implode(" ", array_map(
            static fn(string $key, string|int $value): string => $key . '="' . $value . '"',
            array_keys($attrs),
            $attrs,
        ));

        return new Markup($html, "UTF-8");
    }

    #[\Override]
    public function src(string $size = "full"): string
    {
        $img = \wp_get_attachment_image_src($this->ID, $size);

        return is_array($img) ? $img[0] : "";
    }

    public function file(): string
    {
        return \get_attached_file($this->ID) ?: '';
    }

    public function file_contents(): string
    {
        $path = $this->file();
        return $path !== '' ? (file_get_contents($path) ?: '') : '';
    }

    public function width(): int
    {
        $width = $this->data("width");
        return is_int($width) ? $width : 0;
    }

    public function height(): int
    {
        $height = $this->data("height");
        return is_int($height) ? $height : 0;
    }

    public function alt(): string
    {
        return $this->meta("_wp_attachment_image_alt");
    }

    public function sizes(): array
    {
        $sizes = $this->data("sizes");
        return is_array($sizes) ? array_keys($sizes) : [];
    }

    public function data(string $data, string $size = "full"): mixed
    {
        $metadata = $this->meta("_wp_attachment_metadata");
        $source = "full" === $size ? $metadata : ($metadata["sizes"][$size] ?? null);

        return is_array($source) ? ($source[$data] ?? "") : "";
    }
}
