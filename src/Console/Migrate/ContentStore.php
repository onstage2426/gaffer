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
    /** @var array<string, int> the post or term of a reference row, "kind:id" => ID (filled by the queries) */
    private static array $owners = [];

    /** @var array<int, array{user: int, since: int}|null> editor locks by post, once looked up */
    private static array $locks = [];

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
     * prefix, revisions included) whose value is one of these field keys, anywhere.
     * FieldReferences decides which are a target's own.
     *
     * @param list<string> $keys
     * @return list<Reference>
     */
    public static function references(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($keys), '%s'));

        return self::reference_rows("meta_value IN ({$in})", "option_value IN ({$in})", $keys, null);
    }

    /**
     * References stored in this target (posts of the type and their revisions, terms of the
     * taxonomy, options with one of these name prefixes) holding a field key that isn't one
     * of $known: candidates for a broken key.
     *
     * @param list<string> $known
     * @param list<string> $prefixes option name prefixes, for options_page targets
     * @return list<Reference>
     */
    public static function target_references(string $param, string $value, array $known, array $prefixes): array
    {
        global $wpdb;

        $not_in = $known === [] ? '' : ' AND %VALUE% NOT IN (' . implode(', ', array_fill(0, count($known), '%s')) . ')';
        $like = "%VALUE% LIKE 'field\\_%%'" . $not_in;
        $meta = str_replace('%VALUE%', 'm.meta_value', $like);

        return match ($param) {
            'post_type' => self::reference_rows("{$meta} AND COALESCE(pp.post_type, p.post_type) = %s", null, [...$known, $value], 'postmeta'),
            'taxonomy' => self::reference_rows("{$meta} AND t.taxonomy = %s", null, [...$known, $value], 'termmeta'),
            default => $prefixes === [] ? [] : self::reference_rows(
                null,
                str_replace('%VALUE%', 'option_value', $like) . ' AND (' . implode(' OR ', array_fill(0, count($prefixes), 'option_name LIKE %s')) . ')',
                [...$known, ...array_map(static fn(string $prefix): string => '\\_' . $wpdb->esc_like($prefix) . '\\_%', $prefixes)],
                'option',
            ),
        };
    }

    /**
     * Every row stored in this target (posts of the type and their revisions, terms of the
     * taxonomy, options) whose meta_key or option_name matches $regex (MySQL REGEXP, the
     * whole name), values and references alike: what removing a field deletes.
     *
     * @return list<Reference> the value in Reference::$key
     */
    public static function target_rows(string $param, string $value, string $regex): array
    {
        return match ($param) {
            'post_type' => self::reference_rows('COALESCE(pp.post_type, p.post_type) = %s', null, [$regex, $value], 'postmeta', false),
            'taxonomy' => self::reference_rows('t.taxonomy = %s', null, [$regex, $value], 'termmeta', false),
            default => self::reference_rows(null, '1 = 1', [$regex], 'option', false),
        };
    }

    /**
     * Reference rows ("_" names) matching these conditions, from the tables that have one
     * ($only: just that kind). $args fill the placeholders of each condition.
     *
     * @param list<string> $args
     * @return list<Reference>
     */
    private static function reference_rows(?string $meta_where, ?string $option_where, array $args, ?string $only, bool $references = true): array
    {
        // References: "_" names. Otherwise the name matches the REGEXP that is the first argument.
        $meta_name = $references ? "m.meta_key LIKE '\\\\_%%'" : 'm.meta_key REGEXP %s';
        $option_name = $references ? "option_name LIKE '\\\\_%%'" : 'option_name REGEXP %s';
        global $wpdb;

        $found = [];
        if ($meta_where !== null && $only !== 'termmeta' && $only !== 'option') {
            // A revision belongs to its parent's post type.
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT m.meta_id, m.post_id, m.meta_key, m.meta_value, p.post_type, pp.post_type AS parent_type
                 FROM {$wpdb->postmeta} m
                 LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id
                 LEFT JOIN {$wpdb->posts} pp ON pp.ID = p.post_parent AND p.post_type = 'revision'
                 WHERE {$meta_name} AND {$meta_where} ORDER BY m.meta_id",
                ...$args,
            ));
            foreach ($rows as $row) {
                $location = new Location('postmeta', (int) $row->meta_id, "{$row->post_type} {$row->post_id} {$row->meta_key}", (int) $row->post_id, (string) $row->meta_key);
                self::$owners["postmeta:{$row->meta_id}"] = (int) $row->post_id;
                $found[] = new Reference($location, (string) $row->meta_key, (string) $row->meta_value, (string) ($row->parent_type ?? $row->post_type), (int) $row->post_id);
            }
        }
        if ($meta_where !== null && $only !== 'postmeta' && $only !== 'option') {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT m.meta_id, m.term_id, m.meta_key, m.meta_value, t.taxonomy
                 FROM {$wpdb->termmeta} m LEFT JOIN {$wpdb->term_taxonomy} t ON t.term_id = m.term_id
                 WHERE {$meta_name} AND {$meta_where} ORDER BY m.meta_id",
                ...$args,
            ));
            foreach ($rows as $row) {
                $location = new Location('termmeta', (int) $row->meta_id, "{$row->taxonomy} term {$row->term_id} {$row->meta_key}", (int) $row->term_id, (string) $row->meta_key);
                self::$owners["termmeta:{$row->meta_id}"] = (int) $row->term_id;
                $found[] = new Reference($location, (string) $row->meta_key, (string) $row->meta_value, (string) $row->taxonomy, (int) $row->term_id);
            }
        }
        if ($option_where !== null && $only !== 'postmeta' && $only !== 'termmeta') {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE {$option_name} AND {$option_where} ORDER BY option_id",
                ...$args,
            ));
            foreach ($rows as $row) {
                $location = new Location('option', (int) $row->option_id, "option {$row->option_name}", null, (string) $row->option_name);
                $found[] = new Reference($location, (string) $row->option_name, (string) $row->option_value, 'option', null);
            }
        }

        return $found;
    }

    /**
     * The stored content; with $lock inside a transaction, the row stays locked until COMMIT/ROLLBACK.
     */
    public static function read(Location $location, bool $lock = false): string
    {
        return self::read_row($location, $lock) ?? throw new RuntimeException("{$location->label} no longer exists");
    }

    /**
     * The stored content, or null when the row doesn't exist (deleted by a removal, or not yet
     * restored by its rollback); with $lock inside a transaction, the row stays locked.
     */
    public static function read_row(Location $location, bool $lock = false): ?string
    {
        global $wpdb;

        if ($location->kind === 'post') {
            // get_row(), not get_var(): get_var() turns an empty post_content into null.
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d" . ($lock ? ' FOR UPDATE' : ''),
                $location->id,
            ), ARRAY_A);
            return is_string($row['post_content'] ?? null) ? $row['post_content'] : null;
        }
        if (isset(self::ROWS[$location->kind])) {
            [$table, $id, , $column] = self::ROWS[$location->kind];
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT {$column} FROM {$wpdb->$table} WHERE {$id} = %d" . ($lock ? ' FOR UPDATE' : ''),
                $location->id,
            ), ARRAY_A);
            return is_string($row[$column] ?? null) ? $row[$column] : null;
        }

        $content = self::widgets($lock)[$location->id]['content'] ?? null;

        return is_string($content) ? $content : null;
    }

    /**
     * Writes the content; null deletes a meta or option row, and content for a row that
     * doesn't exist inserts it again with its own ID, owner and name (a rollback).
     */
    public static function write(Location $location, ?string $content): void
    {
        global $wpdb;

        if ($content === null || (isset(self::ROWS[$location->kind]) && self::read_row($location) === null)) {
            [$table, $id, $owner, $column] = self::ROWS[$location->kind] ?? throw new RuntimeException("{$location->label} can't be deleted or inserted (only meta and option rows)");
            if ($content === null) {
                $result = $wpdb->delete($wpdb->$table, [$id => $location->id]);
            } elseif ($location->name === null || ($owner !== null && $location->owner === null)) {
                throw new RuntimeException("{$location->label} doesn't exist and its backup doesn't say where it belongs");
            } else {
                $name = $location->kind === 'option' ? 'option_name' : 'meta_key';
                $result = $wpdb->insert($wpdb->$table, [$id => $location->id, ...($owner !== null ? [$owner => $location->owner] : ['autoload' => 'auto']), $name => $location->name, $column => $content]);
            }
        } elseif ($location->kind === 'post') {
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
            'option' => self::flush_option((string) ($location->name ?? self::option_name($location))),
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
        if ($location->owner !== null) {
            return $location->owner; // a row that may be deleted carries its owner
        }
        $cached = "{$location->kind}:{$location->id}";
        if (isset(self::$owners[$cached])) {
            return self::$owners[$cached];
        }
        [$table, $id, $owner] = $row;
        $value = $wpdb->get_var($wpdb->prepare("SELECT {$owner} FROM {$wpdb->$table} WHERE {$id} = %d", $location->id));

        return $value !== null ? self::$owners[$cached] = (int) $value : null;
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
     * The post this location is part of, when it has one (a post's content or its meta).
     */
    public static function post_of(Location $location): ?int
    {
        return match ($location->kind) {
            'post' => $location->id,
            'postmeta' => self::owner($location),
            default => null,
        };
    }

    /**
     * Who has this post open in the editor right now, and since when (WordPress's own
     * wp_check_post_lock(), which lives in the admin includes; once per post and run).
     *
     * @return array{user: int, since: int}|null
     */
    public static function lock(int $post): ?array
    {
        if (array_key_exists($post, self::$locks)) {
            return self::$locks[$post];
        }
        require_once ABSPATH . 'wp-admin/includes/post.php';
        $user = wp_check_post_lock($post);
        $since = (int) explode(':', (string) get_post_meta($post, '_edit_lock', true))[0];

        return self::$locks[$post] = $user ? ['user' => (int) $user, 'since' => $since] : null;
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
