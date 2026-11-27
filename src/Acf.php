<?php

declare(strict_types=1);

namespace Gaffer;

use Gaffer\Types\Post;

/**
 * ACF fields that should be arrays (repeaters, galleries, relationships): an array, [] when
 * ACF is missing or the field is empty (block_array() outside a block render: see there).
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
     * What every theme block's block.json would repeat, filled in while Gaffer registers the
     * theme's blocks (block_type_metadata): the name from the folder (BlockFields::name()),
     * ACF Blocks V3, and functions.php rendered through render_block(). A block.json that
     * says it itself keeps working; doctor warns that it can go.
     *
     * @internal
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public static function block_metadata(array $metadata): array
    {
        $file = is_string($metadata['file'] ?? null) ? $metadata['file'] : '';
        $metadata['name'] ??= BlockFields::name(basename(dirname($file)));
        $acf = is_array($metadata['acf'] ?? null) ? $metadata['acf'] : [];
        $acf['blockVersion'] ??= 3; // ACF's default depends on the ACF and WordPress versions
        if (!isset($acf['renderCallback']) && ($acf['renderTemplate'] ?? 'functions.php') === 'functions.php') {
            unset($acf['renderTemplate']);
            $acf['renderCallback'] = self::class . '::render_block'; // a string: ACF passes block types to the editor as JSON
        }
        $metadata['acf'] = $acf;

        return $metadata;
    }

    /**
     * ACF's render callback for a theme block: its functions.php, with ACF's template variables
     * ($block, $content, $is_preview, $post_id, $wp_block, $context). It counts as a block render
     * itself (rendering()). In the editor the block's code doesn't run: the canvas shows
     * BlockPreview's placeholder, and its Preview button shows the block on the site itself.
     *
     * @internal
     * @param array<string, mixed> $block
     */
    public static function render_block(array $block, mixed $content = '', mixed $is_preview = false, mixed $post_id = 0, mixed $wp_block = null, mixed $context = false): void
    {
        if ($is_preview === true || is_admin()) {
            echo BlockPreview::placeholder($block);
            return;
        }
        $file = (is_string($block['path'] ?? null) ? $block['path'] : '') . '/functions.php';
        if (!is_file($file)) {
            $name = is_string($block['name'] ?? null) ? $block['name'] : '';
            throw new \LogicException("Block {$name}: no {$file}");
        }

        self::rendering(static function (string $__file, array $block, mixed $content, mixed $is_preview, mixed $post_id, mixed $wp_block, mixed $context): void {
            require $__file;
        })($file, $block, $content, $is_preview, $post_id, $wp_block, $context);
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
