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
    public function attrs(string $size = 'full'): Markup
    {
        $img = \wp_get_attachment_image_src($this->wp->ID, $size);

        if (!is_array($img)) {
            return new Markup('', 'UTF-8');
        }

        [$src, $width, $height] = $img;

        $attrs = ['src' => \esc_url($src)];

        $srcset = \wp_get_attachment_image_srcset($this->wp->ID, $size);
        if ($srcset) {
            $attrs['srcset'] = \esc_attr($srcset);
            $sizes = \wp_get_attachment_image_sizes($this->wp->ID, $size);
            if ($sizes) {
                $attrs['sizes'] = \esc_attr('auto, ' . $sizes);
            }
        }

        if ($width && $height) {
            $attrs['width'] = (int) $width;
            $attrs['height'] = (int) $height;
        }

        $attrs['alt'] = \esc_attr(trim(strip_tags($this->alt())));

        $html = implode(' ', array_map(
            static fn(string $key, string|int $value): string => $key . '="' . $value . '"',
            array_keys($attrs),
            $attrs,
        ));

        return new Markup($html, 'UTF-8');
    }

    public function src(string $size = 'full'): string
    {
        $img = \wp_get_attachment_image_src($this->wp->ID, $size);

        return is_array($img) ? $img[0] : '';
    }

    public function width(): int
    {
        $width = $this->metadata('width');
        return is_int($width) ? $width : 0;
    }

    public function height(): int
    {
        $height = $this->metadata('height');
        return is_int($height) ? $height : 0;
    }

    public function alt(): string
    {
        return $this->meta('_wp_attachment_image_alt');
    }

    /** A value of the full image's attachment metadata ("width", "height"). */
    private function metadata(string $key): mixed
    {
        $metadata = $this->meta('_wp_attachment_metadata');

        return is_array($metadata) ? ($metadata[$key] ?? null) : null;
    }
}
