<?php

declare(strict_types=1);

namespace Gaffer;

/**
 * Theme blocks in the block editor. The canvas shows a placeholder (the block's title and the
 * start of its text), never the block's own code. A Preview button in the block's toolbar opens
 * the block on the site itself: the post's preview URL in a modal, with the editor's unsaved
 * block (or the whole unsaved post) posted to it in place of the saved content. That page has
 * the theme's own header, CSS, fonts and JavaScript, so there's no editor stylesheet to keep
 * in step with the site.
 *
 * What it uses of WordPress: the post's preview link, the editor's public script API
 * (`editor.BlockEdit`, BlockControls, `serialize()`, `getEditedPostContent()`) and the
 * main query's post after `wp`. Nothing is saved: the posted content replaces a copy of the
 * queried post, never the cached one.
 *
 * @internal
 */
final class BlockPreview
{
    /** The posted content (block markup), the post's unsaved title and the nonce. */
    private const string FIELD = 'gaffer_block_preview';
    private const string TITLE = 'gaffer_block_preview_title';
    private const string NONCE = 'gaffer_block_preview_nonce';

    /** The heartbeat key that asks for a fresh nonce (the post's ID) and brings it. */
    private const string HEARTBEAT = 'gaffer_block_preview';

    public static function register(): void
    {
        add_action('enqueue_block_editor_assets', self::enqueue(...));
        add_filter('heartbeat_received', self::refresh(...), 10, 2);
        if (isset($_POST[self::FIELD])) {
            add_filter('show_admin_bar', '__return_false');
            add_action('pre_get_posts', self::statuses(...));
            add_action('wp', self::swap(...));
        }
    }

    /**
     * What a theme block shows in the editor's canvas: its title and the first text in its
     * fields. Styled inline: nothing of the theme's CSS is in the canvas.
     *
     * @param array<string, mixed> $block
     */
    public static function placeholder(array $block): string
    {
        $title = is_string($block['title'] ?? null) && $block['title'] !== '' ? $block['title']
            : (is_string($block['name'] ?? null) ? $block['name'] : '');
        $summary = self::summary(is_array($block['data'] ?? null) ? $block['data'] : []);

        return '<div class="gaffer-block-placeholder" style="padding:14px 18px;border:1px dashed #c3c4c7;border-radius:4px;background:#f6f7f7;color:#1e1e1e;font:13px/1.5 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif">'
            . '<strong style="display:block;font-size:14px;font-weight:600">' . htmlspecialchars($title, ENT_QUOTES) . '</strong>'
            . ($summary !== '' ? '<span style="display:block;margin-top:2px;color:#50575e">' . htmlspecialchars($summary, ENT_QUOTES) . '</span>' : '')
            . '</div>';
    }

    /**
     * The first text an editor typed into the block (a heading, a paragraph), shortened:
     * not field keys, numbers (image IDs) or lists (repeaters).
     *
     * @param array<mixed> $data
     */
    public static function summary(array $data): string
    {
        foreach ($data as $name => $value) {
            if (!is_string($value) || (is_string($name) && str_starts_with($name, '_')) || str_starts_with($value, 'field_') || is_numeric($value)) {
                continue;
            }
            $text = trim((string) preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES)));
            if ($text !== '') {
                return mb_strlen($text) > 120 ? rtrim(mb_substr($text, 0, 119)) . '…' : $text;
            }
        }

        return '';
    }

    /** The Preview button, for the theme's blocks in a post's editor. */
    private static function enqueue(): void
    {
        $post = get_post();
        $blocks = array_values(BlockFields::names());
        $link = $post instanceof \WP_Post ? get_preview_post_link($post) : null;
        if ($blocks === [] || !is_string($link) || !current_user_can('edit_post', $post->ID) || !is_post_type_viewable($post->post_type)) {
            return; // the site editor, the widgets screen, a post type without pages on the site
        }
        $script = file_get_contents(dirname(__DIR__) . '/resources/editor/block-preview.js'); // inline: it deploys with this PHP
        if ($script === false) {
            return;
        }
        $settings = [
            'url' => $link,
            'field' => self::FIELD,
            'title_field' => self::TITLE,
            'nonce_field' => self::NONCE,
            'nonce' => wp_create_nonce(self::action($post->ID)),
            'post' => $post->ID,
            'heartbeat' => self::HEARTBEAT,
            'blocks' => $blocks,
            // WordPress's own strings, translated with the admin (its script translations are per script)
            'labels' => [
                'preview' => _x('Preview', 'noun'),
                'block' => __('Block'),
                'page' => __('Page'),
                'desktop' => __('Desktop'),
                'tablet' => __('Tablet'),
                'mobile' => __('Mobile'),
            ],
        ];
        wp_register_script('gaffer-block-preview', false, ['heartbeat', 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-compose', 'wp-data', 'wp-element', 'wp-hooks'], null, true);
        wp_enqueue_script('gaffer-block-preview');
        wp_add_inline_script('gaffer-block-preview', 'window.gafferBlockPreview = ' . wp_json_encode($settings) . ";\n" . $script);
    }

    /**
     * A fresh nonce for the editor's Preview button, on each heartbeat that asks for one.
     *
     * @param array<string, mixed> $response
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function refresh(array $response, array $data): array
    {
        $id = $data[self::HEARTBEAT] ?? null;
        if (is_numeric($id) && current_user_can('edit_post', (int) $id)) {
            $response[self::HEARTBEAT] = wp_create_nonce(self::action((int) $id));
        }

        return $response;
    }

    /**
     * A post never saved yet (WordPress's auto-draft) is in no query: the preview request's main
     * query takes it like a draft. swap() refuses any post the user can't edit.
     */
    private static function statuses(\WP_Query $query): void
    {
        if ($query->is_main_query()) {
            $query->set('post_status', ['publish', 'future', 'draft', 'pending', 'private', 'auto-draft']);
        }
    }

    /**
     * On the preview request (after the main query): the queried post, as a copy with the posted
     * content, everywhere a template reads it (the queried object, the loop, global $post). The
     * cached post stays as saved: with a persistent object cache, other visitors would see it.
     */
    private static function swap(): void
    {
        global $wp_query, $post;
        $queried = $wp_query->get_queried_object();
        $nonce = isset($_POST[self::NONCE]) && is_string($_POST[self::NONCE]) ? $_POST[self::NONCE] : '';
        if (!$queried instanceof \WP_Post || !wp_verify_nonce($nonce, self::action($queried->ID)) || !current_user_can('edit_post', $queried->ID)) {
            wp_die('This preview has expired, or you were logged out: reload the editor and press Preview again.', 403);
        }
        $content = is_string($_POST[self::FIELD]) ? wp_unslash($_POST[self::FIELD]) : '';
        $title = isset($_POST[self::TITLE]) && is_string($_POST[self::TITLE]) ? wp_unslash($_POST[self::TITLE]) : $queried->post_title;
        if (!current_user_can('unfiltered_html')) {
            $content = wp_kses_post($content); // as WordPress filters it on save
            $title = wp_kses_post($title);
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true); // page caches that cache POST requests or logged-in visitors
        }
        nocache_headers();
        header('X-Robots-Tag: noindex');

        $copy = clone $queried;
        $copy->post_content = $content;
        $copy->post_title = $title;
        foreach ($wp_query->posts as $i => $item) {
            if ($item instanceof \WP_Post && $item->ID === $queried->ID) {
                $wp_query->posts[$i] = $copy;
            }
        }
        $wp_query->queried_object = $copy;
        if ($wp_query->post instanceof \WP_Post && $wp_query->post->ID === $queried->ID) {
            $wp_query->post = $copy;
        }
        if ($post instanceof \WP_Post && $post->ID === $queried->ID) {
            $post = $copy;
        }
    }

    private static function action(int $id): string
    {
        return "gaffer_block_preview_{$id}";
    }
}
