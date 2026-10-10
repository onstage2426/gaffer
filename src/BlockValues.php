<?php

declare(strict_types=1);

namespace Gaffer;

/**
 * Undoes what kses does to ACF block data. When someone without unfiltered_html
 * saves a post (an Editor; on a multisite everyone but super admins), WordPress
 * runs wp_kses on every string attribute of every block, which turns each "&"
 * into "&amp;". ACF reads block data back as stored, so "Grind &amp; Witgrind"
 * would show as typed with Twig's escaping, and a URL's "&amp;" breaks the link.
 *
 * Only "&amp;" → "&", once: valid entities kses keeps ("&lt;") stay, so no value
 * can turn into markup, also not one printed with |raw. Data saved without kses
 * has a plain "&" and is unchanged.
 *
 * @internal
 */
final class BlockValues
{
    public static function register(): void
    {
        add_filter('acf/load_value', static fn(mixed $value, mixed $post_id): mixed => is_string($post_id) && str_starts_with($post_id, 'block_') ? self::decode($value) : $value, 10, 2);
    }

    /** "&amp;" → "&" in a string, or in every string of an array (a link, a gallery's data). */
    public static function decode(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_replace('&amp;', '&', $value);
        }

        return is_array($value) ? array_map(self::decode(...), $value) : $value;
    }
}
