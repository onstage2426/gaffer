<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Closure;
use Gaffer\BlockFields;
use Gaffer\Config;
use Gaffer\Console\Report;
use Gaffer\Console\ThemeFiles;
use Gaffer\Forms\FormTemplates;
use Gaffer\Forms\GravityForm;
use Gaffer\Mcp\Mcp;
use Gaffer\Mcp\Tool;
use Gaffer\Paths;
use Gaffer\Types\Image;
use RuntimeException;
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
        $this->field_types($report);
        $this->woocommerce_templates($report);
        $this->forms($report);
        $this->menus($report);
        $this->mcp($report);
    }

    /**
     * Gaffer's MCP server answers an agent's start command with every tool (catches
     * MCP Adapter updates that break it, and WP-CLI missing from the PATH).
     */
    private function mcp(Report $report): void
    {
        if (!Mcp::enabled()) {
            return;
        }
        $expected = array_map(static fn(Tool $tool): string => "gaffer-{$tool->name()}", Mcp::tools());

        try {
            $served = Mcp::served_tools();
        } catch (RuntimeException $e) {
            $report->error('mcp', $e->getMessage(), null, null,
                'Agents get no gaffer tools. Check that `wp` runs from the theme directory; after an MCP Adapter update, check its changelog for create_server() changes.');
            return;
        }

        $missing = array_diff($expected, $served);
        if ($missing !== []) {
            $report->error('mcp', 'The MCP server "gaffer" lacks ' . implode(', ', $missing) . ' (serves: ' . (implode(', ', $served) ?: 'nothing') . ')', null, null,
                'Run the start command from .mcp.json by hand and look at stderr; abilities may have failed to register.');
            return;
        }
        $report->info('mcp', 'MCP server "gaffer" serves its ' . count($expected) . ' tools');
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
                    'Renamed field? php gaffer migrate:field <block> <old> <new>. Removed on purpose? php gaffer migrate:remove-field <block> <field>.');
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

    /**
     * Every type in blocks/*\/fields.php is a field type ACF has registered (asked at
     * runtime, so new ACF versions and plugin field types need no Gaffer update).
     */
    private function field_types(Report $report): void
    {
        if (!function_exists('acf_get_field_type')) {
            return;
        }

        $check = function (array $fields, string $file) use (&$check, $report): void {
            foreach ($fields as $field) {
                if (!acf_get_field_type((string) $field['type'])) {
                    $report->error('wp', "Unknown ACF field type \"{$field['type']}\" (field \"" . ($field['name'] ?? $field['label'] ?? '?') . '")', $file);
                }
                if (is_array($field['sub_fields'] ?? null)) {
                    $check($field['sub_fields'], $file);
                }
            }
        };
        foreach (glob(Paths::blocks() . '/*/fields.php') ?: [] as $file) {
            try {
                $check(BlockFields::group(basename(dirname($file)))['fields'], $file);
            } catch (\Throwable) {
                continue; // BlocksCheck reports invalid fields.php
            }
        }
    }

    /**
     * Every file in woocommerce/ overrides a template WooCommerce has (else
     * WooCommerce never loads it by itself, and a renamed or removed template
     * silently stops being overridden), and no custom woocommerce.php router.
     */
    private function woocommerce_templates(Report $report): void
    {
        if (!function_exists('WC')) {
            return;
        }

        $dir = Paths::base('woocommerce');
        $files = is_dir($dir) ? array_map(static fn(string $f): string => substr($f, strlen($dir) + 1), ThemeFiles::find(['php'], $dir)) : [];
        $core = WC()->plugin_path() . '/templates/';
        // Besides its template files, WooCommerce's loader looks for per-taxonomy and per-product page templates
        $loader = '/^(?:taxonomy-(?:' . implode('|', array_map(static fn(string $t): string => preg_quote($t, '/'), get_object_taxonomies('product'))) . ')(?:-[^\/]+)?|single-product-[^\/]+)\.php$/';
        foreach (self::unknown_templates($files, static fn(string $relative): bool => is_file($core . $relative) || preg_match($loader, $relative) === 1) as $relative) {
            $report->warning('wp', "woocommerce/{$relative} is not a WooCommerce template: WooCommerce never loads it by itself", "{$dir}/{$relative}", null,
                'Use the name of the WooCommerce template it should replace, or render the view from that template.');
        }

        if (is_file(Paths::base('woocommerce.php'))) {
            $report->warning('wp', 'woocommerce.php replaces WooCommerce\'s own template choice for every shop page', Paths::base('woocommerce.php'), null,
                'Delete it and use WooCommerce\'s hierarchy: single-product.php, archive-product.php, taxonomy-product_cat.php in woocommerce/.');
        }
    }

    /**
     * @param list<string> $files paths relative to woocommerce/
     * @param Closure(string): bool $exists whether WooCommerce has a template at that path
     * @return list<string>
     */
    public static function unknown_templates(array $files, Closure $exists): array
    {
        return array_values(array_filter($files, static fn(string $file): bool => !$exists($file)));
    }

    /**
     * Each views/forms/{name}.twig has an active Gravity Forms form titled like it,
     * sends only fields the form has, and sends every required one.
     */
    private function forms(Report $report): void
    {
        $templates = FormTemplates::all();
        if ($templates === []) {
            return;
        }
        if (!class_exists('GFAPI')) {
            $report->error('forms', 'views/forms/ has form templates, but Gravity Forms is not active');
            return;
        }
        if (!is_file(Paths::ajax() . '/Form/Form.php')) {
            $report->error('forms', 'No ajax/Form/Form.php: forms post to it', null, null,
                'class Form extends \\Gaffer\\Forms\\FormAction {} in namespace Theme\\Ajax\\Form.');
        }

        foreach ($templates as $name => $file) {
            $gravity = GravityForm::find($name);
            if ($gravity === null) {
                $report->error('forms', "No active Gravity Forms form titled like \"{$name}\"", $file, null,
                    'The form\'s title must slug to the template name (php gaffer forms:show).');
                continue;
            }
            $fields = $gravity->fields();
            $sent = FormTemplates::field_names($file);
            foreach (array_diff($sent, array_keys($fields)) as $unknown) {
                $report->error('forms', "Sends \"{$unknown}\", which {$gravity->title()} has no field for", $file, null,
                    'Set it as the Admin Field Label of the field in Gravity Forms, or fix the name.');
            }
            foreach ($fields as $field_name => $field) {
                if ($field->isRequired && !in_array($field_name, $sent, true)) {
                    $report->error('forms', "Doesn't send \"{$field_name}\", which {$gravity->title()} requires", $file);
                }
            }
            foreach ($gravity->unnamed() as $field) {
                $report->warning('forms', "{$gravity->title()}: field \"{$field->label}\" has no Admin Field Label, so the theme can't send it", $file);
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
