<?php

declare(strict_types=1);

namespace Gaffer;

/**
 * The block editor's canvas as the site: when the build has an `assets/css/editor.css` entry,
 * it goes into the editor's iframe (WordPress 7.1+ always iframes the post editor), where
 * Gaffer's block previews (Acf::render_block()) render with it.
 *
 * The theme builds that entry with Tailwind's parts imported without `layer()`: everything
 * WordPress loads in the iframe is unlayered, and unlayered CSS beats layered CSS whatever its
 * specificity. Gaffer makes room for it: no default editor styles (Noto Serif), theme blocks
 * full width without the classic layout's gaps, and none of the admin's common.css and
 * forms.css, nor the reset that undoes them inside the canvas (only dependencies there: the
 * reset sets p and heading margins back to the browser's, above Tailwind's preflight).
 *
 * @internal
 */
final class Editor
{
    public const ENTRY = 'assets/css/editor.css';

    /** Admin styles the canvas gets only as dependencies of wp-reset-editor-styles. */
    private const ADMIN_STYLES = ['common', 'forms', 'wp-reset-editor-styles'];

    private const CANVAS = '.editor-styles-wrapper{padding:0}'
        . '.editor-styles-wrapper .wp-block[data-type^="acf/"]{max-width:none;margin-block:0}';

    public static function register(): void
    {
        add_action('after_setup_theme', static function (): void {
            if (is_admin() && self::url() !== null) {
                add_theme_support('editor-styles');
            }
        });
        add_action('enqueue_block_assets', static function (): void {
            $url = is_admin() ? self::url() : null;
            if ($url !== null) {
                wp_enqueue_style('gaffer-editor', $url, [], null);
                wp_add_inline_style('gaffer-editor', self::CANVAS);
            }
        });
        add_filter('block_editor_settings_all', static function (array $settings): array {
            $styles = $settings['__unstableResolvedAssets']['styles'] ?? null;
            if (is_string($styles) && self::url() !== null) {
                $settings['__unstableResolvedAssets']['styles'] = self::without_admin_styles($styles);
            }

            return $settings;
        }, 20);
    }

    /**
     * The iframe's style tags without the admin's own (WordPress prints `<link id='{handle}-css'>`).
     */
    public static function without_admin_styles(string $html): string
    {
        $handles = implode('|', array_map(static fn(string $handle): string => preg_quote($handle, '~'), self::ADMIN_STYLES));

        return (string) preg_replace("~<link\\b[^>]*\\bid=(['\"])(?:{$handles})-css\\1[^>]*>\\s*~", '', $html);
    }

    private static function url(): ?string
    {
        return Vite::url(self::ENTRY);
    }
}
