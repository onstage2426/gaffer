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

    /** @internal The class of the box a failed preview shows in the editor (doctor looks for it). */
    public const PREVIEW_ERROR = 'gaffer-preview-error';

    /**
     * ACF's render callback for a theme block: its functions.php, with ACF's template variables
     * ($block, $content, $is_preview, $post_id, $wp_block, $context), on the site and, with
     * `theme.block_previews` on, as the editor's preview (styled by Editor); off, nothing there. It counts as a block render itself: ACF's preview
     * request after a field edit calls it without WordPress's block type. A preview that throws
     * shows the error in the block (PREVIEW_ERROR) instead of breaking the editor.
     *
     * @internal
     * @param array<string, mixed> $block
     */
    public static function render_block(array $block, mixed $content = '', mixed $is_preview = false, mixed $post_id = 0, mixed $wp_block = null, mixed $context = false): void
    {
        $file = (is_string($block['path'] ?? null) ? $block['path'] : '') . '/functions.php';
        $name = is_string($block['name'] ?? null) ? $block['name'] : '';
        if (!is_file($file)) {
            throw new \LogicException("Block {$name}: no {$file}");
        }
        $render = self::rendering(static function (string $__file, array $block, mixed $content, mixed $is_preview, mixed $post_id, mixed $wp_block, mixed $context): void {
            require $__file;
        });

        if ($is_preview !== true && !is_admin()) {
            $render($file, $block, $content, $is_preview, $post_id, $wp_block, $context);
            return;
        }
        if (!Editor::enabled()) {
            return; // theme.block_previews off: an empty preview
        }
        $level = ob_get_level();
        try {
            $render($file, $block, $content, $is_preview, $post_id, $wp_block, $context);
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean(); // a half-rendered template's buffers
            }
            $message = ($e instanceof \Twig\Error\Error ? $e->getRawMessage() : $e->getMessage())
                . ' (' . basename($e instanceof \Twig\Error\Error ? (string) $e->getSourceContext()?->getPath() : $e->getFile())
                . ':' . ($e instanceof \Twig\Error\Error ? $e->getTemplateLine() : $e->getLine()) . ')';
            error_log("Gaffer: block {$name} preview: {$message}");
            printf('<div class="%s" style="padding:1rem;border:1px solid #d63638;color:#d63638;font:14px/1.5 sans-serif">%s</div>',
                self::PREVIEW_ERROR, htmlspecialchars("{$name}: {$message}", ENT_QUOTES));
        }
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
