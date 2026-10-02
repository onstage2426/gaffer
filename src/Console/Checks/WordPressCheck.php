<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Closure;
use Gaffer\Console\Report;
use Gaffer\Config;
use Gaffer\Paths;
use Gaffer\Types\Image;
use WP_Block_Type_Registry;

/**
 * What WordPress actually has: registered blocks, block names and ACF field keys
 * used in content, ACF JSON vs database, menu locations, the image fallback.
 * Needs WordPress loaded.
 */
final class WordPressCheck implements Check
{
    #[\Override]
    public function run(Report $report): void
    {
        $this->blocks($report);
        $this->acf_sync($report);
        $this->menus($report);
    }

    private function blocks(Report $report): void
    {
        $registry = WP_Block_Type_Registry::get_instance();

        foreach (BlocksCheck::names() as $dir => $name) {
            if (!$registry->is_registered($name)) {
                $report->error('wp', "Block {$name} is not registered", Paths::blocks() . "/{$dir}/block.json");
            }
        }

        // ACF block names used in content that nothing registers (e.g. after a rename).
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT ID, post_type, post_content FROM {$wpdb->posts}
             WHERE post_content LIKE '%<!-- wp:acf/%' AND post_type <> 'revision' AND post_status <> 'trash'",
        );

        $orphans = [];
        foreach ($rows as $row) {
            preg_match_all('#<!-- wp:(acf/[a-z0-9-]+)#', $row->post_content, $m);
            foreach (array_unique($m[1]) as $name) {
                if (!$registry->is_registered($name)) {
                    $orphans[$name][] = "{$row->post_type} {$row->ID}";
                }
            }
        }
        foreach ($orphans as $name => $posts) {
            $report->error('wp', "Content uses unregistered block {$name}: " . implode(', ', $posts), null, null,
                'Renamed block? php gaffer migrate:block <old> <new>.');
        }

        $this->stale_fields($report, $rows);
    }

    /**
     * Block data whose field keys no ACF field has (a field renamed or removed).
     *
     * @param array<object> $rows
     */
    private function stale_fields(Report $report, array $rows): void
    {
        if (!function_exists('acf_get_field')) {
            return;
        }

        $stale = [];
        $walk = function (array $blocks, string $post) use (&$walk, &$stale): void {
            foreach ($blocks as $block) {
                foreach ($block['attrs']['data'] ?? [] as $name => $key) {
                    if (is_string($name) && str_starts_with($name, '_') && is_string($key) && str_starts_with($key, 'field_') && !acf_get_field($key)) {
                        $stale[$block['blockName']][substr($name, 1)][$post] = true;
                    }
                }
                $walk($block['innerBlocks'] ?? [], $post);
            }
        };
        foreach ($rows as $row) {
            $walk(parse_blocks($row->post_content), "{$row->post_type} {$row->ID}");
        }

        foreach ($stale as $block => $fields) {
            foreach ($fields as $field => $posts) {
                $report->warning('wp', "{$block}: content stores \"{$field}\" for a field that no longer exists: " . implode(', ', array_keys($posts)), null, null,
                    'Renamed field? php gaffer migrate:field <block> <old> <new>. Removed on purpose? The stored value is unused and harmless.');
            }
        }
    }

    private function acf_sync(Report $report): void
    {
        if (!function_exists('acf_get_field_group_post')) {
            return;
        }

        foreach (glob(Paths::storage() . '/acf-json/group_*.json') ?: [] as $file) {
            $json = json_decode((string) file_get_contents($file), true);
            if (!is_array($json) || !isset($json['key'])) {
                continue; // AcfJsonCheck reports invalid files
            }

            $post = acf_get_field_group_post($json['key']);
            $state = match (true) {
                !$post => 'only in JSON (sync it in ACF → Field Groups)',
                ($json['modified'] ?? 0) > strtotime("{$post->post_modified_gmt} UTC") => 'JSON is newer than the database (sync it in ACF)',
                ($json['modified'] ?? 0) < strtotime("{$post->post_modified_gmt} UTC") => 'database is newer than the JSON (re-save the group in ACF)',
                default => null,
            };

            if ($state !== null) {
                $report->warning('wp', "ACF field group \"{$json['title']}\": {$state}", $file);
            }
        }
    }

    private function menus(Report $report): void
    {
        $assigned = get_nav_menu_locations();

        foreach (get_registered_nav_menus() as $location => $label) {
            $menu = $assigned[$location] ?? 0;
            if (!$menu) {
                $report->warning('wp', "Menu location \"{$location}\" ({$label}) has no menu assigned", null, null,
                    'Appearance → Menus → Manage Locations.');
            } elseif (!wp_get_nav_menu_object($menu)) {
                $report->error('wp', "Menu location \"{$location}\" points at menu {$menu}, which doesn't exist");
            }
        }
    }
}
