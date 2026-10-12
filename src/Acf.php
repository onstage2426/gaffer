<?php

declare(strict_types=1);

namespace Gaffer;

use Gaffer\Types\Term;

/**
 * ACF fields that should be arrays (repeaters, galleries, relationships):
 * always returns an array, [] when ACF is missing or the field is empty.
 */
final class Acf
{
    /**
     * @return array<mixed>
     */
    public static function field_array(string $selector, ?int $post_id = null): array
    {
        return self::read($selector, $post_id ?? false);
    }

    /**
     * Same, for a field on an ACF options page.
     *
     * @return array<mixed>
     */
    public static function option_array(string $selector): array
    {
        return self::read($selector, 'option');
    }

    /**
     * Same, for a field on a term (a product category's FAQ repeater).
     *
     * @return array<mixed>
     */
    public static function term_array(string $selector, Term $term): array
    {
        return self::read($selector, "term_{$term->wp->term_id}");
    }

    /** @return array<mixed> */
    private static function read(string $selector, int|string|false $post_id): array
    {
        $value = function_exists('get_field') ? get_field($selector, $post_id) : null;

        return is_array($value) ? $value : [];
    }
}
