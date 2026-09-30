<?php

declare(strict_types=1);

namespace Gaffer\Types;

final class Image extends Attachment
{
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

    public function atts(): string
    {
        $alt = 'alt="' . \esc_attr($this->alt()) . '"';
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
        $source = "full" === $size ? $metadata : ($metadata["sizes"][$size] ?? null);

        return is_array($source) ? ($source[$data] ?? "") : "";
    }
}
