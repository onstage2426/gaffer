<?php

declare(strict_types=1);

namespace Gaffer\Console\Migrate;

use RuntimeException;

/**
 * Finds, reads and writes block content straight in the database: no
 * wp_update_post() (no revisions, no save hooks, no kses stripping markup when
 * there's no logged-in user), and reads that bypass the object cache.
 */
final class ContentStore
{
    /**
     * Every post (any type and status except revisions and auto-drafts) and block
     * widget whose content contains $needle.
     *
     * @return list<Location>
     */
    public static function find(string $needle): array
    {
        global $wpdb;

        $locations = [];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_type, post_title FROM {$wpdb->posts}
             WHERE post_type <> 'revision' AND post_status <> 'auto-draft' AND post_content LIKE %s ORDER BY ID",
            '%' . $wpdb->esc_like($needle) . '%',
        ));
        foreach ($rows as $row) {
            $locations[] = new Location('post', (int) $row->ID, "{$row->post_type} {$row->ID} \"{$row->post_title}\"");
        }

        foreach (self::widgets() as $id => $widget) {
            if (is_array($widget) && is_string($widget['content'] ?? null) && str_contains($widget['content'], $needle)) {
                $locations[] = new Location('widget', (int) $id, "block widget {$id}");
            }
        }

        return $locations;
    }

    /**
     * The stored content; with $lock inside a transaction, the row stays locked until COMMIT/ROLLBACK.
     */
    public static function read(Location $location, bool $lock = false): string
    {
        global $wpdb;

        if ($location->kind === 'post') {
            $content = $wpdb->get_var($wpdb->prepare(
                "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d" . ($lock ? ' FOR UPDATE' : ''),
                $location->id,
            ));
            return is_string($content) ? $content : throw new RuntimeException("{$location->label} no longer exists");
        }

        $content = self::widgets($lock)[$location->id]['content'] ?? null;

        return is_string($content) ? $content : throw new RuntimeException("{$location->label} no longer exists");
    }

    public static function write(Location $location, string $content): void
    {
        global $wpdb;

        if ($location->kind === 'post') {
            $result = $wpdb->update($wpdb->posts, ['post_content' => $content], ['ID' => $location->id]);
        } else {
            $widgets = self::widgets(true);
            $widgets[$location->id]['content'] = $content;
            $result = $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($widgets)], ['option_name' => 'widget_block']);
        }

        if ($result === false) {
            throw new RuntimeException("Writing {$location->label} failed: {$wpdb->last_error}");
        }
    }

    /**
     * Drops cached copies after writing, so WordPress serves the new content.
     */
    public static function flush(Location $location): void
    {
        if ($location->kind === 'post') {
            clean_post_cache($location->id);
            return;
        }
        wp_cache_delete('widget_block', 'options');
        wp_cache_delete('alloptions', 'options');
    }

    /**
     * Who has this post open in the editor right now, if anyone: the "time:user" in
     * _edit_lock, as wp_check_post_lock() reads it (that one is admin-only).
     */
    public static function locked_by(Location $location): ?int
    {
        if ($location->kind !== 'post') {
            return null;
        }
        [$time, $user] = array_map('intval', array_pad(explode(':', (string) get_post_meta($location->id, '_edit_lock', true)), 2, '0'));
        $window = (int) apply_filters('wp_check_post_lock_window', 150);

        return $time > time() - $window && $user > 0 ? $user : null;
    }

    /**
     * Whether the tables written to support transactions.
     */
    public static function transactional(): bool
    {
        global $wpdb;

        $engines = $wpdb->get_col($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s)',
            $wpdb->posts,
            $wpdb->options,
        ));

        return count($engines) === 2 && array_unique(array_map('strtolower', $engines)) === ['innodb'];
    }

    /** @return array<mixed> */
    private static function widgets(bool $lock = false): array
    {
        global $wpdb;

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s" . ($lock ? ' FOR UPDATE' : ''),
            'widget_block',
        ));
        $widgets = is_string($value) ? maybe_unserialize($value) : [];

        return is_array($widgets) ? $widgets : [];
    }
}
