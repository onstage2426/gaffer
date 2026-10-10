<?php

declare(strict_types=1);

namespace Gaffer\Types;

use Gaffer\ImageSizes;
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
     * skip otherwise. $sizes replaces it where the image is wider than its box:
     * object-fit: cover in a box of another aspect ratio needs box height × the
     * image's aspect ratio, which neither "auto" nor the size's width knows:
     * <img class="object-cover" {{ image.attrs('large', '(min-width: 1024px) 60vw, 150vw') }}>
     * WordPress's "auto, " for lazy images in content is taken off again (ImageSizes).
     *
     * Writes the size's own width and height: set the shown size with CSS (h-20 w-auto),
     * not a width or height attribute on the tag, which would be a second one.
     */
    public function attrs(string $size = 'full', ?string $sizes = null): Markup
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
            if ($sizes === null) {
                $default = \wp_get_attachment_image_sizes($this->wp->ID, $size);
                $sizes = $default ? 'auto, ' . $default : null;
            } else {
                ImageSizes::keep($sizes); // WordPress would prefix "auto, " in block content
            }
            if ($sizes !== null) {
                $attrs['sizes'] = \esc_attr($sizes);
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

    /** The URL of this size, null when WordPress can't give one. */
    public function src(string $size = 'full'): ?string
    {
        $img = \wp_get_attachment_image_src($this->wp->ID, $size);

        return is_array($img) ? $img[0] : null;
    }

    /** The full image's width, null when its metadata doesn't have one. */
    public function width(): ?int
    {
        $width = $this->metadata('width');
        return is_int($width) ? $width : null;
    }

    /** The full image's height, null when its metadata doesn't have one. */
    public function height(): ?int
    {
        $height = $this->metadata('height');
        return is_int($height) ? $height : null;
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
