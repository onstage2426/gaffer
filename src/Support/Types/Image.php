<?php

declare(strict_types=1);

namespace Gaffer\Support\Types;

final class Image extends Attachment
{
    #[\Override]
    public function src(string $size = "full"): string
    {
        $img = \wp_get_attachment_image_src($this->ID, $size);

        if (is_array($img) && isset($img[0]) && is_string($img[0])) {
            return $img[0];
        }

        return "";
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

    public function atts(): string
    {
        $alt = 'alt="' . $this->alt() . '"';
        $width = 'width="' . $this->width() . '"';
        $height = 'height="' . $this->height() . '"';

        return "$alt $width $height";
    }

    public function sizes(): array
    {
        $sizes = $this->data("sizes");
        return is_array($sizes) ? array_keys($sizes) : [];
    }

    public function data(string $data, string $size = "full"): mixed
    {
        $metadata = $this->meta("_wp_attachment_metadata");

        if (isset($metadata[$data])) {
            if ("full" === $size) {
                return $metadata[$data];
            }
            return $metadata[$size][$data];
        }

        return "";
    }
}
