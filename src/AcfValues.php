<?php

declare(strict_types=1);

namespace Gaffer;

/**
 * Undoes what kses does to ACF values. When someone without unfiltered_html saves
 * (an Editor; on a multisite everyone but super admins), WordPress runs wp_kses on
 * what they save: every string attribute of every block, and ACF's fields on
 * posts, terms and options pages, which turns each "&" into "&amp;". ACF reads it
 * back as stored, so "bestrating &amp; tegels" would show as typed with Twig's
 * escaping, and a URL's "&amp;" breaks the link.
 *
 * Only "&amp;" → "&", once: valid entities kses keeps ("&lt;") stay, so no value
 * can turn into markup, also not one printed with |raw. Values saved without kses
 * have a plain "&" and are unchanged. Wysiwyg fields are HTML, where "&amp;" is
 * right: they keep it (decoding a typed "&amp;lt;" would show "<").
 *
 * @internal
 */
final class AcfValues
{
    public static function register(): void
    {
        add_filter('acf/load_value', static function (mixed $value, mixed $post_id, mixed $field): mixed {
            return is_array($field) && ($field['type'] ?? null) === 'wysiwyg' ? $value : self::decode($value);
        }, 10, 3);
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
