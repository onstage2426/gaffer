<?php

declare(strict_types=1);

namespace Gaffer\Console\Migrate;

use RuntimeException;

/**
 * Finds, reads and writes block content and ACF field references straight in
 * the database: no wp_update_post()/update_metadata() (no revisions, no save
 * hooks, no kses stripping markup when there's no logged-in user), and reads
 * that bypass the object cache.
 */
final class ContentStore
{
    /** Reference rows: table property on $wpdb, row ID column, owner column, value column. */
    private const array ROWS = [
        'postmeta' => ['postmeta', 'meta_id', 'post_id', 'meta_value'],
        'termmeta' => ['termmeta', 'meta_id', 'term_id', 'meta_value'],
        'option' => ['options', 'option_id', null, 'option_value'],
    ];

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
     * Every ACF field reference ("_name" postmeta, termmeta or option row, any language
     * prefix, revisions included) whose value is one of these field keys. Field keys are
     * unique, so the value alone says which field a row belongs to.
     *
     * @param list<string> $keys
     * @return list<Location>
     */
    public static function references(array $keys): array
    {
        global $wpdb;

        if ($keys === []) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($keys), '%s'));
        $locations = [];

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.meta_id, m.post_id, m.meta_key, p.post_type FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id
             WHERE m.meta_key LIKE '\\_%%' AND m.meta_value IN ({$in}) ORDER BY m.meta_id",
            ...$keys,
        ));
        foreach ($rows as $row) {
            $locations[] = new Location('postmeta', (int) $row->meta_id, "{$row->post_type} {$row->post_id} {$row->meta_key}");
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.meta_id, m.term_id, m.meta_key, t.taxonomy FROM {$wpdb->termmeta} m LEFT JOIN {$wpdb->term_taxonomy} t ON t.term_id = m.term_id
             WHERE m.meta_key LIKE '\\_%%' AND m.meta_value IN ({$in}) ORDER BY m.meta_id",
            ...$keys,
        ));
        foreach ($rows as $row) {
            $locations[] = new Location('termmeta', (int) $row->meta_id, "{$row->taxonomy} term {$row->term_id} {$row->meta_key}");
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_id, option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_%%' AND option_value IN ({$in}) ORDER BY option_id",
            ...$keys,
        ));
        foreach ($rows as $row) {
            $locations[] = new Location('option', (int) $row->option_id, "option {$row->option_name}");
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
            // get_row(), not get_var(): get_var() turns an empty post_content into null.
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d" . ($lock ? ' FOR UPDATE' : ''),
                $location->id,
            ), ARRAY_A);
            return is_string($row['post_content'] ?? null) ? $row['post_content'] : throw new RuntimeException("{$location->label} no longer exists");
        }
        if (isset(self::ROWS[$location->kind])) {
            [$table, $id, , $column] = self::ROWS[$location->kind];
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT {$column} FROM {$wpdb->$table} WHERE {$id} = %d" . ($lock ? ' FOR UPDATE' : ''),
                $location->id,
            ), ARRAY_A);
            return is_string($row[$column] ?? null) ? $row[$column] : throw new RuntimeException("{$location->label} no longer exists");
        }

        $content = self::widgets($lock)[$location->id]['content'] ?? null;

        return is_string($content) ? $content : throw new RuntimeException("{$location->label} no longer exists");
    }

    public static function write(Location $location, string $content): void
    {
        global $wpdb;

        if ($location->kind === 'post') {
            $result = $wpdb->update($wpdb->posts, ['post_content' => $content], ['ID' => $location->id]);
        } elseif (isset(self::ROWS[$location->kind])) {
            [$table, $id, , $column] = self::ROWS[$location->kind];
            $result = $wpdb->update($wpdb->$table, [$column => $content], [$id => $location->id]);
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
        match ($location->kind) {
            'post' => clean_post_cache($location->id),
            'postmeta' => wp_cache_delete((int) self::owner($location), 'post_meta'),
            'termmeta' => wp_cache_delete((int) self::owner($location), 'term_meta'),
            'option' => self::flush_option((string) self::option_name($location)),
            default => self::flush_option('widget_block'),
        };
    }

    /**
     * The post (postmeta) or term (termmeta) a reference row belongs to.
     */
    public static function owner(Location $location): ?int
    {
        global $wpdb;

        $row = self::ROWS[$location->kind] ?? null;
        if ($row === null || $row[2] === null) {
            return null;
        }
        [$table, $id, $owner] = $row;
        $value = $wpdb->get_var($wpdb->prepare("SELECT {$owner} FROM {$wpdb->$table} WHERE {$id} = %d", $location->id));

        return $value !== null ? (int) $value : null;
    }

    private static function option_name(Location $location): ?string
    {
        global $wpdb;

        $name = $wpdb->get_var($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_id = %d", $location->id));

        return is_string($name) ? $name : null;
    }

    private static function flush_option(string $name): void
    {
        wp_cache_delete($name, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }

    /**
     * Who has this post open in the editor right now, if anyone (WordPress's own
     * wp_check_post_lock(), which lives in the admin includes).
     */
    public static function locked_by(Location $location): ?int
    {
        $post = match ($location->kind) {
            'post' => $location->id,
            'postmeta' => self::owner($location),
            default => null,
        };
        if ($post === null) {
            return null;
        }
        require_once ABSPATH . 'wp-admin/includes/post.php';
        $user = wp_check_post_lock($post);

        return $user ? (int) $user : null;
    }

    /**
     * Whether the tables written to (posts, options, postmeta, termmeta) support transactions.
     */
    public static function transactional(): bool
    {
        global $wpdb;

        $engines = $wpdb->get_col($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s, %s, %s)',
            $wpdb->posts,
            $wpdb->options,
            $wpdb->postmeta,
            $wpdb->termmeta,
        ));

        return count($engines) === 4 && array_unique(array_map('strtolower', $engines)) === ['innodb'];
    }

    /**
     * The widget_block option: [id => ['content' => ...], '_multiwidget' => 1]. Throws on
     * anything else, so a changed format is refused instead of rewritten.
     *
     * @return array<mixed>
     */
    private static function widgets(bool $lock = false): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s" . ($lock ? ' FOR UPDATE' : ''),
            'widget_block',
        ), ARRAY_A);
        $value = $row['option_value'] ?? null;
        if (!is_string($value)) {
            return [];
        }

        $widgets = maybe_unserialize($value);
        if (!is_array($widgets)) {
            throw new RuntimeException('The widget_block option has a shape Gaffer doesn\'t know (not an array): not touching widgets');
        }
        foreach ($widgets as $id => $widget) {
            if ($id !== '_multiwidget' && (!is_int($id) || !is_array($widget) || !is_string($widget['content'] ?? null))) {
                throw new RuntimeException("The widget_block option has a shape Gaffer doesn't know (entry {$id}): not touching widgets");
            }
        }

        return $widgets;
    }
}
