<?php

declare(strict_types=1);

namespace Gaffer;

/**
 * Keeps a sizes value a template gave Image::attrs(). WordPress (6.7+) prefixes
 * "auto, " to the sizes of every lazy image in content (wp_filter_content_tags,
 * which also runs over ACF blocks' output), and with "auto" browsers pick by the
 * rendered width and skip the list: an image cropped into a box with object-fit:
 * cover then gets a file as wide as the box, too small. This takes "auto, " off
 * again for images whose sizes is one a template gave in this request.
 *
 * @internal
 */
final class ImageSizes
{
    /** @var array<string, true> */
    private static array $given = [];

    public static function keep(string $sizes): void
    {
        if (self::$given === []) {
            \add_filter('wp_content_img_tag', self::without_auto(...), 20);
        }
        self::$given[trim($sizes)] = true;
    }

    public static function without_auto(string $image): string
    {
        $tag = new \WP_HTML_Tag_Processor($image);
        if (!$tag->next_tag(['tag_name' => 'IMG'])) {
            return $image;
        }
        $sizes = $tag->get_attribute('sizes');
        if (!is_string($sizes) || !preg_match('/^\s*auto\s*,\s*(.+)$/is', $sizes, $m) || !isset(self::$given[trim($m[1])])) {
            return $image;
        }
        $tag->set_attribute('sizes', trim($m[1]));

        return $tag->get_updated_html();
    }
}
