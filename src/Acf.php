<?php

declare(strict_types=1);

namespace Gaffer;

use Gaffer\Types\Post;

/**
 * ACF fields that should be arrays (repeaters, galleries, relationships):
 * always returns an array, [] when ACF is missing or the field is empty.
 */
final class Acf
{
    /** How many of the theme's blocks are rendering right now (rendering()). */
    private static int $blocks = 0;

    /**
     * A field of the ACF block being rendered (in a block's functions.php): ACF's own
     * get_field($selector) inside a block render.
     *
     * Outside one of the theme's block renders it's a programmer error: it throws with
     * WP_DEBUG (doctor's renders, development), and in production logs it and returns []
     * (a page keeps rendering).
     *
     * @return array<mixed>
     */
    public static function block_array(string $selector): array
    {
        if (self::$blocks === 0) {
            $message = "Acf::block_array('{$selector}') is called outside a block render; a post's field is Acf::post_array('{$selector}', \$post).";
            if (Gaffer::debug()) {
                throw new \LogicException($message);
            }
            error_log("Gaffer: {$message}");
            return [];
        }

        return self::read($selector, false);
    }

    /**
     * A field of a post (a product's downloads repeater).
     *
     * @return array<mixed>
     */
    public static function post_array(string $selector, Post $post): array
    {
        return self::read($selector, $post->wp->ID);
    }

    /**
     * A field on an ACF options page.
     *
     * @return array<mixed>
     */
    public static function option_array(string $selector): array
    {
        return self::read($selector, 'option');
    }

    /**
     * Wraps a theme block's render callback (ACF's) so block_array() knows a block is
     * rendering: WordPress's own block type, not ACF's internals.
     *
     * @internal
     */
    public static function rendering(callable $render): \Closure
    {
        return static function (mixed ...$arguments) use ($render): mixed {
            self::$blocks++;
            try {
                return $render(...$arguments);
            } finally {
                self::$blocks--;
            }
        };
    }

    /** @return array<mixed> */
    private static function read(string $selector, int|string|false $post_id): array
    {
        $value = function_exists('get_field') ? get_field($selector, $post_id) : null;

        return is_array($value) ? $value : [];
    }
}
